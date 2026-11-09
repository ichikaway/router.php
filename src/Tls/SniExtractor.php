<?php

namespace Tls;

/**
 * 転送中のTLS ClientHello(TCPペイロード)から SNI(server_name拡張)の接続先FQDNを取り出す
 *
 * - ポスト量子鍵共有(X25519MLKEM768等)でClientHelloが1500バイトを超え、
 *   複数のTCPセグメントに分割されることがあるため、フロー単位で再構成してから解析する
 * - 1フローにつき1回だけ返す(TCP再送で二重に読み上げない)
 */
class SniExtractor
{
    /** TLSレコードの最大長 (2^14 + ヘッダ) */
    private const MAX_RECORD = 16384 + 5;

    /** 保持するフロー数の上限 */
    private const MAX_FLOWS = 256;

    /**
     * 再構成中のフロー
     * @var array<string, array{buf: string, nextSeq: int}>
     */
    private array $pending = [];

    /**
     * 解析済み(または対象外と判定済み)のフロー。再送を無視するために覚えておく
     * @var array<string, true>
     */
    private array $done = [];

    /**
     * @param string $flowKey 送信元/宛先IP:ポートで一意になるキー
     * @param int $seq TCPシーケンス番号
     * @param string $payload TCPペイロード
     * @param bool $closing FIN/RSTが立っているか
     * @return string|null 取り出せたFQDN
     */
    public function feed(string $flowKey, int $seq, string $payload, bool $closing = false): ?string
    {
        $result = null;

        if ($payload !== '' && !isset($this->done[$flowKey])) {
            $result = $this->append($flowKey, $seq, $payload);
        }

        if ($closing) {
            unset($this->pending[$flowKey], $this->done[$flowKey]);
        }

        return $result;
    }

    private function append(string $flowKey, int $seq, string $payload): ?string
    {
        if (isset($this->pending[$flowKey])) {
            $flow = $this->pending[$flowKey];
            if ($seq !== $flow['nextSeq']) {
                // 再送や順序入れ替わり。順番通りのセグメントだけつなげる
                return null;
            }
            $buf = $flow['buf'] . $payload;
        } else {
            $buf = $payload;
        }

        // TLS Handshakeレコード(0x16) かつ ClientHello(0x01) で始まるフローのみ対象
        if (ord($buf[0]) !== 0x16 || (strlen($buf) >= 6 && ord($buf[5]) !== 0x01)) {
            $this->markDone($flowKey);
            return null;
        }

        $recordLen = strlen($buf) >= 5 ? unpack('n', $buf, 3)[1] + 5 : PHP_INT_MAX;
        if ($recordLen !== PHP_INT_MAX && $recordLen > self::MAX_RECORD) {
            $this->markDone($flowKey);
            return null;
        }

        if (strlen($buf) < $recordLen || strlen($buf) < 6) {
            // まだ続きのセグメントがある
            $this->pending[$flowKey] = ['buf' => $buf, 'nextSeq' => ($seq + strlen($payload)) & 0xFFFFFFFF];
            if (count($this->pending) > self::MAX_FLOWS) {
                array_shift($this->pending);
            }
            return null;
        }

        $this->markDone($flowKey);
        return self::parseClientHello(substr($buf, 5, $recordLen - 5));
    }

    private function markDone(string $flowKey): void
    {
        unset($this->pending[$flowKey]);
        $this->done[$flowKey] = true;
        if (count($this->done) > self::MAX_FLOWS) {
            array_shift($this->done);
        }
    }

    /**
     * Handshakeメッセージ(ClientHello)から server_name を取り出す
     */
    public static function parseClientHello(string $hs): ?string
    {
        $len = strlen($hs);
        // handshake type(1) + length(3) + client_version(2) + random(32)
        $p = 4 + 2 + 32;
        if ($len < $p + 1 || ord($hs[0]) !== 0x01) {
            return null;
        }

        // session_id
        $p += 1 + ord($hs[$p]);
        // cipher_suites
        if ($len < $p + 2) return null;
        $p += 2 + unpack('n', $hs, $p)[1];
        // compression_methods
        if ($len < $p + 1) return null;
        $p += 1 + ord($hs[$p]);
        // extensions
        if ($len < $p + 2) return null;
        $extEnd = min($len, $p + 2 + unpack('n', $hs, $p)[1]);
        $p += 2;

        while ($p + 4 <= $extEnd) {
            ['type' => $type, 'len' => $extLen] = unpack('ntype/nlen', $hs, $p);
            $p += 4;
            if ($type === 0x0000) {
                return self::parseServerName(substr($hs, $p, $extLen));
            }
            $p += $extLen;
        }
        return null;
    }

    private static function parseServerName(string $ext): ?string
    {
        // server_name_list length(2) の後に name_type(1) + name length(2) + name が並ぶ
        $len = strlen($ext);
        $p = 2;
        while ($p + 3 <= $len) {
            ['type' => $type, 'len' => $nameLen] = unpack('Ctype/nlen', $ext, $p);
            $p += 3;
            $name = substr($ext, $p, $nameLen);
            $p += $nameLen;
            // host_name(0) で、FQDNとして妥当な文字だけのもの
            if ($type === 0 && preg_match('/^[A-Za-z0-9.-]{1,253}$/', $name)) {
                return strtolower($name);
            }
        }
        return null;
    }
}
