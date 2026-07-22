<?php

namespace Xdp;

/**
 * AF_XDP(copyモード)でイーサフレームを読み書きするためのFFIラッパー。
 * NICごとに1インスタンス。フレームの再利用(fill/completion ring)は
 * libxdpphp.so側で完結しており、ここでは単純なポーリングAPIしか使わない。
 *
 * 同一ハンドルはrecv用(メインスレッド)とsend用(parallelのワーカースレッド)の
 * 2つのOSスレッドから同時に使われる想定(単一プロセス構成のみ対応。詳細は
 * xdpphp.c冒頭のコメントと{@see fromHandleAddress()}を参照)。
 */
final class XskSocket
{
    private const int RECV_BUF_LEN = 2048;

    private const string CDEF = <<<'CDEF'
        typedef struct xdpphp_socket xdpphp_socket_t;

        xdpphp_socket_t *xdpphp_open(const char *ifname, unsigned int queue_id, const char *bpf_obj_path, const char *self_ipv4);
        void xdpphp_close(xdpphp_socket_t *sock);
        long xdpphp_recv(xdpphp_socket_t *sock, unsigned char *buf, unsigned long buf_len);
        int  xdpphp_send(xdpphp_socket_t *sock, const unsigned char *buf, unsigned long len);
        unsigned long xdpphp_handle_address(xdpphp_socket_t *sock);
        const char *xdpphp_last_error(void);
        CDEF;

    private static ?\FFI $ffi = null;

    private \FFI\CData $handle;

    private ?\FFI\CData $recvBuf = null;

    /** trueのインスタンスのみ__destruct()でxdpphp_closeする(TX用の借り物ハンドルは閉じない) */
    private bool $ownsHandle;

    private function __construct(
        private readonly string $deviceName,
    ) {
    }

    /**
     * 新規にAF_XDPソケットを開く(RX+TX)。NICごとにプロセス内で1回だけ呼ぶ。
     */
    public static function open(string $deviceName, string $selfIpv4, int $queueId = 0): self
    {
        self::$ffi ??= \FFI::cdef(self::CDEF, __DIR__ . '/../c/libxdpphp.so');

        $instance = new self($deviceName);

        $instance->recvBuf = self::$ffi->new('unsigned char[' . self::RECV_BUF_LEN . ']');

        $bpfObjPath = __DIR__ . '/../c/xdp_filter.bpf.o';
        $handle = self::$ffi->xdpphp_open($deviceName, $queueId, $bpfObjPath, $selfIpv4);
        if (\FFI::isNull($handle)) {
            throw new \RuntimeException(
                "xdpphp_open failed for {$deviceName}: " . self::$ffi->xdpphp_last_error()
            );
        }
        $instance->handle = $handle;
        $instance->ownsHandle = true;

        return $instance;
    }

    /**
     * 既に開いている(別スレッドが所有する)ハンドルの生アドレスから、送信専用の
     * インスタンスを作る。単一プロセス構成(start.php)で、write用の`parallel`
     * ワーカースレッドがメインスレッドで開いたAF_XDPハンドルのTX ringを使って
     * 送信するために使う。xdpphp_close()は呼ばない(所有権は元のインスタンス側にある)。
     */
    public static function fromHandleAddress(string $deviceName, int $handleAddress): self
    {
        self::$ffi ??= \FFI::cdef(self::CDEF, __DIR__ . '/../c/libxdpphp.so');

        $instance = new self($deviceName);
        $instance->handle = self::$ffi->cast('xdpphp_socket_t*', $handleAddress);
        $instance->ownsHandle = false;

        return $instance;
    }

    /**
     * 1フレーム分を非ブロッキングで読む。データが無ければnull。
     */
    public function recvFrame(): ?string
    {
        $n = self::$ffi->xdpphp_recv($this->handle, $this->recvBuf, self::RECV_BUF_LEN);
        if ($n > 0) {
            return \FFI::string($this->recvBuf, $n);
        }
        if ($n < 0) {
            throw new \RuntimeException(
                "xdpphp_recv error on {$this->deviceName}: " . self::$ffi->xdpphp_last_error()
            );
        }
        return null;
    }

    /**
     * 1フレーム分を非ブロッキングで送る。TX ringが埋まっている等のバックプレッシャー時はfalse(drop)。
     */
    public function sendFrame(string $frame): bool
    {
        $len = strlen($frame);
        $cdata = self::$ffi->new("unsigned char[{$len}]");
        \FFI::memcpy($cdata, $frame, $len);

        $ret = self::$ffi->xdpphp_send($this->handle, $cdata, $len);
        if ($ret < 0) {
            throw new \RuntimeException(
                "xdpphp_send error on {$this->deviceName}: " . self::$ffi->xdpphp_last_error()
            );
        }
        return $ret === 1;
    }

    /**
     * このハンドルの生アドレス(uintptr_t)を返す。同一プロセス内の別スレッド
     * (parallelのRuntime)へ渡し、{@see fromHandleAddress()}で再構築するために使う。
     * プロセスをまたいでは共有できない(単なる仮想アドレスのため)。
     */
    public function getHandleAddress(): int
    {
        return self::$ffi->xdpphp_handle_address($this->handle);
    }

    public function __destruct()
    {
        if ($this->ownsHandle && isset($this->handle) && !\FFI::isNull($this->handle)) {
            self::$ffi->xdpphp_close($this->handle);
        }
    }
}
