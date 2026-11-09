<?php

namespace Http;

/**
 * 転送中のHTTPレスポンス(TCPペイロード)から <h1>...</h1> の中身を取り出す
 *
 * - TCPセグメントをまたいで <h1> と </h1> が分かれていても扱えるよう、フロー単位でバッファする
 * - TCP再送で同じセグメントが来ても二重に読み上げないよう、処理済みのseq番号を覚えておく
 * - gzip等で圧縮されたレスポンスは対象外 (curlのデフォルトは非圧縮)
 */
class H1Extractor
{
    /** <h1 を見つけてから </h1> を待つ最大バイト数。超えたら諦める */
    private const MAX_BUFFER = 8192;

    /** 保持するフロー数の上限 */
    private const MAX_FLOWS = 256;

    /** フロー毎に覚えておく処理済みseq番号の数 */
    private const MAX_SEQS = 64;

    /**
     * @var array<string, array{buf: ?string, tail: string, seqs: array<int, true>}>
     */
    private array $flows = [];

    /**
     * @param string $flowKey 送信元/宛先IP:ポートで一意になるキー
     * @param int $seq TCPシーケンス番号
     * @param string $payload TCPペイロード
     * @param bool $closing FIN/RSTが立っているか
     * @return string[] 取り出せたh1のテキスト
     */
    public function feed(string $flowKey, int $seq, string $payload, bool $closing = false): array
    {
        $results = [];

        if ($payload !== '') {
            $flow = $this->flows[$flowKey] ?? ['buf' => null, 'tail' => '', 'seqs' => []];

            if (!isset($flow['seqs'][$seq])) {
                $flow['seqs'][$seq] = true;
                if (count($flow['seqs']) > self::MAX_SEQS) {
                    array_shift($flow['seqs']);
                }
                $results = $this->scan($flow, $payload);
            }

            unset($this->flows[$flowKey]); // 末尾に付け直してLRU的に扱う
            $this->flows[$flowKey] = $flow;
            if (count($this->flows) > self::MAX_FLOWS) {
                array_shift($this->flows);
            }
        }

        if ($closing) {
            unset($this->flows[$flowKey]);
        }

        return $results;
    }

    /**
     * @param array{buf: ?string, tail: string, seqs: array<int, true>} $flow
     * @return string[]
     */
    private function scan(array &$flow, string $payload): array
    {
        $results = [];
        // 前セグメント末尾の数バイトを足して "<h" | "1>" のような分断にも対応する
        $data = $flow['buf'] ?? $flow['tail'];
        $data .= $payload;
        $flow['buf'] = null;

        while (true) {
            if (!preg_match('/<h1(?:\s[^>]*)?>/i', $data, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }
            $start = $m[0][1] + strlen($m[0][0]);
            $end = stripos($data, '</h1', $start);
            if ($end === false) {
                // </h1> がまだ届いていない。<h1 から先を次のセグメントまで持ち越す
                $pending = substr($data, $m[0][1]);
                $flow['buf'] = strlen($pending) <= self::MAX_BUFFER ? $pending : null;
                $flow['tail'] = '';
                return $results;
            }

            $text = self::toText(substr($data, $start, $end - $start));
            if ($text !== '') {
                $results[] = $text;
            }
            $data = substr($data, $end + 4);
        }

        $flow['tail'] = substr($data, -8);
        return $results;
    }

    private static function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text);
        // 長すぎる文字列は読み上げに時間がかかるので切り詰める (UTF-8で100文字)
        if (preg_match('/^.{0,100}/us', $text, $m)) {
            $text = $m[0];
        }
        return $text;
    }
}
