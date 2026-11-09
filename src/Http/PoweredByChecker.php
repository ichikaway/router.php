<?php

namespace Http;

/**
 * 転送中のHTTPレスポンスヘッダから X-Powered-By: PHP/x.y.z を探し、PHPのバージョンを返す
 *
 * - ヘッダがTCPセグメントをまたいでも扱えるよう、"HTTP/1." から空行(\r\n\r\n)まではバッファする
 * - keep-aliveで同じ接続に複数のレスポンスが流れる場合は、レスポンス毎に判定する
 * - TCP再送で同じセグメントが来ても二重に判定しないよう、処理済みのseq番号を覚えておく
 */
class PoweredByChecker
{
    /** ヘッダとして待つ最大バイト数。超えたら諦める */
    private const MAX_HEADER = 16384;

    /** 保持するフロー数の上限 */
    private const MAX_FLOWS = 256;

    /** フロー毎に覚えておく処理済みseq番号の数 */
    private const MAX_SEQS = 64;

    /**
     * @var array<string, array{buf: ?string, seqs: array<int, true>}>
     */
    private array $flows = [];

    /**
     * @return string|null X-Powered-By に書かれたPHPのバージョン (例: "8.5.0")。無ければnull
     */
    public function feed(string $flowKey, int $seq, string $payload, bool $closing = false): ?string
    {
        $version = null;

        if ($payload !== '') {
            $flow = $this->flows[$flowKey] ?? ['buf' => null, 'seqs' => []];

            if (!isset($flow['seqs'][$seq])) {
                $flow['seqs'][$seq] = true;
                if (count($flow['seqs']) > self::MAX_SEQS) {
                    array_shift($flow['seqs']);
                }
                $version = $this->scan($flow, $payload);
            }

            unset($this->flows[$flowKey]);
            $this->flows[$flowKey] = $flow;
            if (count($this->flows) > self::MAX_FLOWS) {
                array_shift($this->flows);
            }
        }

        if ($closing) {
            unset($this->flows[$flowKey]);
        }

        return $version;
    }

    /**
     * @param array{buf: ?string, seqs: array<int, true>} $flow
     */
    private function scan(array &$flow, string $payload): ?string
    {
        if ($flow['buf'] !== null) {
            $data = $flow['buf'] . $payload;
        } elseif (str_starts_with($payload, 'HTTP/1.')) {
            // レスポンスの先頭セグメント
            $data = $payload;
        } else {
            // ボディの途中
            return null;
        }
        $flow['buf'] = null;

        $end = strpos($data, "\r\n\r\n");
        if ($end === false) {
            if (strlen($data) <= self::MAX_HEADER) {
                $flow['buf'] = $data; // ヘッダの続きを待つ
            }
            return null;
        }

        $header = substr($data, 0, $end);
        if (preg_match('/^X-Powered-By:[ \t]*PHP\/([0-9][0-9A-Za-z.\-]*)/mi', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * 指定したバージョン未満なら脆弱とみなす
     */
    public static function isVulnerable(string $version, string $fixedVersion = '8.5.12'): bool
    {
        return version_compare($version, $fixedVersion, '<');
    }
}
