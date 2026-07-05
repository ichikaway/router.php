/* SPDX-License-Identifier: GPL-2.0 */

/*
 * XDPプログラム: EtherType 0x0800 (IPv4) のフレームのうち、
 * 「このルーターNIC自身宛てではないもの(=転送対象)」だけをXSKMAP(AF_XDPソケット)
 * へredirectする。
 *
 * - ARPなどIPv4以外はXDP_PASSでカーネルの通常経路に流す
 *   (Arp.phpが使う別系統のAF_PACKET(ETH_P_ARP)ソケットを壊さないため)
 * - 宛先IPがこのNIC自身のIPと一致するIPv4パケット(ping/iperf3等の管理トラフィック)も
 *   XDP_PASSする。redirectは「複製」ではなく「奪う」操作なので、無条件に全IPv4を
 *   redirectすると、ルーター自身宛ての通信までカーネルのIPスタックに届かなくなる。
 */

#include <linux/bpf.h>
#include <linux/if_ether.h>
#include <linux/ip.h>
#include <bpf/bpf_helpers.h>
#include <bpf/bpf_endian.h>

struct {
    __uint(type, BPF_MAP_TYPE_XSKMAP);
    __uint(max_entries, 8);
    __uint(key_size, sizeof(int));
    __uint(value_size, sizeof(int));
} xsks_map SEC(".maps");

/* このNIC自身のIPv4アドレス(ネットワークバイトオーダー)を1個だけ保持する */
struct {
    __uint(type, BPF_MAP_TYPE_ARRAY);
    __uint(max_entries, 1);
    __type(key, __u32);
    __type(value, __u32);
} self_ip4 SEC(".maps");

SEC("xdp")
int xdpphp_redirect_ip(struct xdp_md *ctx)
{
    void *data = (void *)(long)ctx->data;
    void *data_end = (void *)(long)ctx->data_end;
    struct ethhdr *eth = data;

    if ((void *)(eth + 1) > data_end)
        return XDP_PASS;

    if (eth->h_proto != bpf_htons(ETH_P_IP))
        return XDP_PASS;

    struct iphdr *iph = (struct iphdr *)(eth + 1);
    if ((void *)(iph + 1) > data_end)
        return XDP_PASS;

    __u32 key = 0;
    __u32 *self_ip = bpf_map_lookup_elem(&self_ip4, &key);
    if (self_ip && iph->daddr == *self_ip)
        return XDP_PASS;

    return bpf_redirect_map(&xsks_map, ctx->rx_queue_index, XDP_PASS);
}

char _license[] SEC("license") = "GPL";
