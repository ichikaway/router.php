/* SPDX-License-Identifier: GPL-2.0
 *
 * PHP FFIから呼び出すための、AF_XDP(copyモード)の薄いラッパー。
 * PHP側にはopaqueハンドルと非ブロッキングのrecv/send関数だけを見せる。
 * fill/completion ringによるUMEMフレームの再利用はすべてこの層で完結させる。
 *
 * recv()とsend()は同じxdpphp_socket_tハンドルを異なるOSスレッドから同時に
 * 呼び出せる設計になっている(単一プロセス内でRXはメインスレッド、TXは
 * parallelのワーカースレッドが担う想定)。recvはrx/fillリングのみ、sendは
 * tx/compリングのみを触り、UMEM上のフレーム領域もRX用/TX用で完全に分離して
 * いるため、ロック無しでも競合しない。ただしこの前提が崩れる呼び方
 * (例: 同じハンドルのrecvを複数スレッドから同時に呼ぶ)はサポートしない。
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <stdarg.h>
#include <errno.h>
#include <unistd.h>
#include <net/if.h>
#include <linux/if_link.h>
#include <sys/mman.h>
#include <sys/socket.h>
#include <sys/resource.h>
#include <arpa/inet.h>
#include <netinet/in.h>

#include <bpf/libbpf.h>
#include <bpf/bpf.h>
#include <xdp/xsk.h>

#define XDPPHP_PROG_NAME      "xdpphp_redirect_ip"
#define XDPPHP_MAP_NAME       "xsks_map"
#define XDPPHP_SELF_IP_MAP_NAME "self_ip4"

#define XDPPHP_FRAME_SIZE     2048u
#define XDPPHP_NUM_RX_FRAMES  2048u
#define XDPPHP_NUM_TX_FRAMES  2048u
#define XDPPHP_NUM_FRAMES     (XDPPHP_NUM_RX_FRAMES + XDPPHP_NUM_TX_FRAMES)
#define XDPPHP_UMEM_SIZE      ((uint64_t)XDPPHP_NUM_FRAMES * XDPPHP_FRAME_SIZE)

#define XDPPHP_COMP_BATCH     64u

struct xdpphp_socket {
    struct xsk_umem   *umem;
    struct xsk_socket *xsk;
    struct xsk_ring_prod fill;
    struct xsk_ring_cons comp;
    struct xsk_ring_cons rx;
    struct xsk_ring_prod tx;
    void     *umem_area;
    struct bpf_object *obj;
    int       ifindex;
    int       xsk_fd;
    /* tx_free/tx_free_countはsend側スレッドのみが読み書きする */
    uint64_t  tx_free[XDPPHP_NUM_TX_FRAMES];
    uint32_t  tx_free_count;
};

static __thread char xdpphp_err[256] = "";

static void set_error(const char *fmt, ...)
{
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(xdpphp_err, sizeof(xdpphp_err), fmt, ap);
    va_end(ap);
}

const char *xdpphp_last_error(void)
{
    return xdpphp_err;
}

static void xdpphp_teardown(struct xdpphp_socket *sock)
{
    if (!sock)
        return;
    if (sock->xsk)
        xsk_socket__delete(sock->xsk);
    if (sock->umem)
        xsk_umem__delete(sock->umem);
    if (sock->umem_area && sock->umem_area != MAP_FAILED)
        munmap(sock->umem_area, XDPPHP_UMEM_SIZE);
    if (sock->obj) {
        bpf_xdp_detach(sock->ifindex, 0, NULL);
        bpf_object__close(sock->obj);
    }
    free(sock);
}

void xdpphp_close(struct xdpphp_socket *sock)
{
    xdpphp_teardown(sock);
}

struct xdpphp_socket *xdpphp_open(const char *ifname, unsigned int queue_id,
                                   const char *bpf_obj_path, const char *self_ipv4)
{
    struct rlimit rlim = { RLIM_INFINITY, RLIM_INFINITY };
    setrlimit(RLIMIT_MEMLOCK, &rlim); /* best effort: privilegedコンテナ前提 */

    struct xdpphp_socket *sock = calloc(1, sizeof(*sock));
    if (!sock) {
        set_error("calloc failed: %s", strerror(errno));
        return NULL;
    }

    sock->ifindex = if_nametoindex(ifname);
    if (sock->ifindex == 0) {
        set_error("if_nametoindex(%s) failed: %s", ifname, strerror(errno));
        goto fail;
    }

    sock->obj = bpf_object__open_file(bpf_obj_path, NULL);
    if (!sock->obj) {
        set_error("bpf_object__open_file(%s) failed: %s", bpf_obj_path, strerror(errno));
        goto fail;
    }

    if (bpf_object__load(sock->obj)) {
        set_error("bpf_object__load failed: %s", strerror(errno));
        goto fail;
    }

    struct bpf_program *prog = bpf_object__find_program_by_name(sock->obj, XDPPHP_PROG_NAME);
    if (!prog) {
        set_error("program %s not found in %s", XDPPHP_PROG_NAME, bpf_obj_path);
        goto fail;
    }
    int prog_fd = bpf_program__fd(prog);
    if (prog_fd < 0) {
        set_error("bpf_program__fd failed: %d", prog_fd);
        goto fail;
    }

    if (bpf_xdp_attach(sock->ifindex, prog_fd, 0, NULL) < 0) {
        if (bpf_xdp_attach(sock->ifindex, prog_fd, XDP_FLAGS_SKB_MODE, NULL) < 0) {
            set_error("bpf_xdp_attach failed on %s (native and skb mode): %s",
                      ifname, strerror(errno));
            goto fail;
        }
    }

    struct bpf_map *xsks_map = bpf_object__find_map_by_name(sock->obj, XDPPHP_MAP_NAME);
    if (!xsks_map) {
        set_error("map %s not found in %s", XDPPHP_MAP_NAME, bpf_obj_path);
        goto fail;
    }
    int xsks_map_fd = bpf_map__fd(xsks_map);
    if (xsks_map_fd < 0) {
        set_error("bpf_map__fd failed: %d", xsks_map_fd);
        goto fail;
    }

    struct bpf_map *self_ip_map = bpf_object__find_map_by_name(sock->obj, XDPPHP_SELF_IP_MAP_NAME);
    if (!self_ip_map) {
        set_error("map %s not found in %s", XDPPHP_SELF_IP_MAP_NAME, bpf_obj_path);
        goto fail;
    }
    int self_ip_map_fd = bpf_map__fd(self_ip_map);
    if (self_ip_map_fd < 0) {
        set_error("bpf_map__fd(self_ip4) failed: %d", self_ip_map_fd);
        goto fail;
    }
    struct in_addr self_addr;
    if (inet_pton(AF_INET, self_ipv4, &self_addr) != 1) {
        set_error("invalid self_ipv4 address: %s", self_ipv4);
        goto fail;
    }
    uint32_t self_ip_key = 0;
    uint32_t self_ip_be = (uint32_t)self_addr.s_addr; /* inet_pton produces network byte order already */
    if (bpf_map_update_elem(self_ip_map_fd, &self_ip_key, &self_ip_be, 0)) {
        set_error("bpf_map_update_elem(self_ip4) failed: %s", strerror(errno));
        goto fail;
    }

    sock->umem_area = mmap(NULL, XDPPHP_UMEM_SIZE, PROT_READ | PROT_WRITE,
                           MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    if (sock->umem_area == MAP_FAILED) {
        set_error("mmap umem (%lu bytes) failed: %s",
                  (unsigned long)XDPPHP_UMEM_SIZE, strerror(errno));
        goto fail;
    }

    struct xsk_umem_config umem_cfg = {
        .fill_size = XDPPHP_NUM_RX_FRAMES,
        .comp_size = XDPPHP_NUM_TX_FRAMES,
        .frame_size = XDPPHP_FRAME_SIZE,
        .frame_headroom = 0,
        .flags = 0,
    };
    int ret = xsk_umem__create(&sock->umem, sock->umem_area, XDPPHP_UMEM_SIZE,
                                &sock->fill, &sock->comp, &umem_cfg);
    if (ret) {
        set_error("xsk_umem__create failed: %d (%s)", ret, strerror(-ret));
        goto fail;
    }

    struct xsk_socket_config xsk_cfg = {
        .rx_size = XSK_RING_CONS__DEFAULT_NUM_DESCS,
        .tx_size = XSK_RING_PROD__DEFAULT_NUM_DESCS,
        .libxdp_flags = XSK_LIBBPF_FLAGS__INHIBIT_PROG_LOAD,
        .xdp_flags = 0,
        .bind_flags = XDP_COPY, /* vethはDMA非対応のためzero-copyを強制せずcopyモード固定 */
    };
    ret = xsk_socket__create(&sock->xsk, ifname, queue_id, sock->umem,
                              &sock->rx, &sock->tx, &xsk_cfg);
    if (ret) {
        set_error("xsk_socket__create failed on %s queue %u: %d (%s)",
                  ifname, queue_id, ret, strerror(-ret));
        goto fail;
    }
    sock->xsk_fd = xsk_socket__fd(sock->xsk);

    if (bpf_map_update_elem(xsks_map_fd, &queue_id, &sock->xsk_fd, 0)) {
        set_error("bpf_map_update_elem(xsks_map) failed: %s", strerror(errno));
        goto fail;
    }

    /* fill ringにRX用フレームを全部投入しておく (addr: 0 .. RX_FRAMES-1 * FRAME_SIZE) */
    uint32_t idx = 0;
    uint32_t reserved = xsk_ring_prod__reserve(&sock->fill, XDPPHP_NUM_RX_FRAMES, &idx);
    for (uint32_t i = 0; i < reserved; i++) {
        *xsk_ring_prod__fill_addr(&sock->fill, idx + i) = (uint64_t)i * XDPPHP_FRAME_SIZE;
    }
    xsk_ring_prod__submit(&sock->fill, reserved);

    /* TX用フレームのfree-listを初期化 (addr: RX_FRAMES .. NUM_FRAMES-1 * FRAME_SIZE) */
    for (uint32_t i = 0; i < XDPPHP_NUM_TX_FRAMES; i++) {
        sock->tx_free[i] = (uint64_t)(XDPPHP_NUM_RX_FRAMES + i) * XDPPHP_FRAME_SIZE;
    }
    sock->tx_free_count = XDPPHP_NUM_TX_FRAMES;

    return sock;

fail:
    xdpphp_teardown(sock);
    return NULL;
}

long xdpphp_recv(struct xdpphp_socket *sock, unsigned char *buf, unsigned long buf_len)
{
    uint32_t idx = 0;
    if (xsk_ring_cons__peek(&sock->rx, 1, &idx) != 1)
        return 0;

    const struct xdp_desc *desc = xsk_ring_cons__rx_desc(&sock->rx, idx);
    uint64_t addr = desc->addr;
    uint32_t len = desc->len;

    uint32_t copy_len = len < buf_len ? len : (uint32_t)buf_len;
    memcpy(buf, (unsigned char *)sock->umem_area + addr, copy_len);

    xsk_ring_cons__release(&sock->rx, 1);

    /* 受信で消費したフレームをfill ringへ戻す。通常は必ず1枠空くはずだが、
     * 万一取れなければこのフレームはfill ringに戻せず失われる(致命的ではない)。 */
    uint32_t fidx = 0;
    if (xsk_ring_prod__reserve(&sock->fill, 1, &fidx) == 1) {
        *xsk_ring_prod__fill_addr(&sock->fill, fidx) = addr;
        xsk_ring_prod__submit(&sock->fill, 1);
    }

    return (long)copy_len;
}

static void xdpphp_drain_completions(struct xdpphp_socket *sock)
{
    uint32_t idx = 0;
    uint32_t n = xsk_ring_cons__peek(&sock->comp, XDPPHP_COMP_BATCH, &idx);
    for (uint32_t i = 0; i < n; i++) {
        uint64_t addr = *xsk_ring_cons__comp_addr(&sock->comp, idx + i);
        if (sock->tx_free_count < XDPPHP_NUM_TX_FRAMES) {
            sock->tx_free[sock->tx_free_count++] = addr;
        }
    }
    if (n)
        xsk_ring_cons__release(&sock->comp, n);
}

/* 送信専用。recvと同じハンドルを別スレッドから呼んでよい(ファイル先頭のコメント参照)。 */
int xdpphp_send(struct xdpphp_socket *sock, const unsigned char *buf, unsigned long len)
{
    xdpphp_drain_completions(sock);

    if (sock->tx_free_count == 0)
        return 0; /* バックプレッシャー: 送らずdrop (既存socket_writeも未チェックのため同等) */

    if (len > XDPPHP_FRAME_SIZE)
        len = XDPPHP_FRAME_SIZE;

    uint32_t idx = 0;
    if (xsk_ring_prod__reserve(&sock->tx, 1, &idx) != 1)
        return 0;

    uint64_t addr = sock->tx_free[--sock->tx_free_count];
    memcpy((unsigned char *)sock->umem_area + addr, buf, len);

    struct xdp_desc *desc = xsk_ring_prod__tx_desc(&sock->tx, idx);
    desc->addr = addr;
    desc->len = (uint32_t)len;
    desc->options = 0;

    xsk_ring_prod__submit(&sock->tx, 1);

    if (xsk_ring_prod__needs_wakeup(&sock->tx))
        sendto(sock->xsk_fd, NULL, 0, MSG_DONTWAIT, NULL, 0);

    return 1;
}

/* handleの生アドレスをuintptr_tとして返す。単一プロセス内の別スレッド(parallel
 * のRuntime)がFFI::cast()でポインタを再構築し、同じハンドルに対してxdpphp_send()を
 * 呼び出すために使う。プロセスをまたいだ共有はできない(単なる仮想アドレスのため)。 */
uintptr_t xdpphp_handle_address(struct xdpphp_socket *sock)
{
    return (uintptr_t)sock;
}
