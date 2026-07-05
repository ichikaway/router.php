<?php

namespace Xdp;

/**
 * AF_XDP(copyモード)でイーサフレームを読み書きするためのFFIラッパー。
 * NICごとに1インスタンス。フレームの再利用(fill/completion ring)は
 * libxdpphp.so側で完結しており、ここでは単純なポーリングAPIしか使わない。
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
        const char *xdpphp_last_error(void);
        CDEF;

    private static ?\FFI $ffi = null;

    private \FFI\CData $handle;

    private \FFI\CData $recvBuf;

    public function __construct(
        private readonly string $deviceName,
        private readonly string $selfIpv4,
        int $queueId = 0,
    ) {
        self::$ffi ??= \FFI::cdef(self::CDEF, __DIR__ . '/../c/libxdpphp.so');

        $this->recvBuf = self::$ffi->new('unsigned char[' . self::RECV_BUF_LEN . ']');

        $bpfObjPath = __DIR__ . '/../c/xdp_filter.bpf.o';
        $handle = self::$ffi->xdpphp_open($deviceName, $queueId, $bpfObjPath, $selfIpv4);
        if (\FFI::isNull($handle)) {
            throw new \RuntimeException(
                "xdpphp_open failed for {$deviceName}: " . self::$ffi->xdpphp_last_error()
            );
        }
        $this->handle = $handle;
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
     * 1フレーム分を非ブロッキングで送る。バックプレッシャー時はfalse(drop)。
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

    public function __destruct()
    {
        if (isset($this->handle) && !\FFI::isNull($this->handle)) {
            self::$ffi->xdpphp_close($this->handle);
        }
    }
}
