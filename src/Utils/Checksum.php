<?php

namespace Utils;

class Checksum
{
    /**
     * IPv4 ヘッダチェックサム（1の補数和）
     * $buf[$start .. $start+$len-1] の 16bit ワードを加算し、キャリー回し、1の補数。
     * ※ チェックサムフィールドは呼び出し元で 0 にしておくこと。
     */
    public static function ipv4HeaderChecksum(string $buf, int $start, int $len): int
    {
        $sum = 0;

        for ($i = 0; $i < $len; $i += 2) {
            $hi = ord($buf[$start + $i]);
            $lo = ord($buf[$start + $i + 1]);
            $sum += (($hi << 8) | $lo);

            if ($sum > 0xFFFF) {                // 途中で軽く折返し
                $sum = ($sum & 0xFFFF) + ($sum >> 16);
            }
        }

        // 最終キャリー折返し（2回やる流儀でもOK）
        while ($sum > 0xFFFF) {
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }

        return (~$sum) & 0xFFFF;                // 0x0000 もそのまま返す
    }

}