<?php

namespace Network;

class IpPacket
{

    /**
     * 入力: イーサネットフレーム（binary string, socket_read()で得たもの）
     * 出力: TTLを1減算し、IPヘッダチェックサムを再計算したフレーム（binary string）
     *       対象外（IPv4以外/壊れたフレーム/TTL<=1）は null
     */
    public static function decrementIPv4TtlAndFixChecksum(?string $frame): ?string
    {
        if ($frame === null) {
            return null;
        }
        $len = strlen($frame);
        if ($len < 14) {
            return null;
        } // Ethernetヘッダ未満

        // --- EtherType / VLAN タグをスキップしてIPv4ヘッダの開始位置を求める ---
        $offset      = 12; // EtherType の位置
        $ethType     = unpack('n', substr($frame, $offset, 2))[1];
        $l2HeaderLen = 14;

        // VLAN (802.1Q:0x8100 / 0x88A8) を多段にスキップ（Q-in-Q対策）
        while ($ethType === 0x8100 || $ethType === 0x88A8) {
            $l2HeaderLen += 4;                // VLANタグ長分を加算
            if ($len < $l2HeaderLen) {
                return null;
            }
            $offset  += 4;                      // 次のEtherTypeへ
            $ethType = unpack('n', substr($frame, $offset, 2))[1];
        }

        if ($ethType !== 0x0800) {
            // IPv4 以外は対象外（ARP/IPv6など）
            return null;
        }

        // --- IPv4 ヘッダ ---
        if ($len < $l2HeaderLen + 20) {
            return null;
        } // 最低20バイト必要
        $ipStart = $l2HeaderLen;

        $vihl    = ord($frame[$ipStart]);       // Version(4bit) + IHL(4bit)
        $version = $vihl >> 4;
        $ihl     = $vihl & 0x0F;              // 32bit words
        if ($version !== 4 || $ihl < 5) {
            return null;
        }

        $ipHeaderLen = $ihl * 4;
        if ($len < $ipStart + $ipHeaderLen) {
            return null;
        }

        // TTL は IPv4 ヘッダ 9バイト目（0始まりなら8）= オフセット ipStart+8
        $ttlPos = $ipStart + 8;
        $oldTtl = ord($frame[$ttlPos]);

        // TTL<=1 はルータ動作としては Time Exceeded 対象。ここではドロップ扱い。
        if ($oldTtl <= 1) {
            return null;
        }

        // TTL を 1 減算して書き戻し
        $newTtl         = $oldTtl - 1;
        $frame[$ttlPos] = chr($newTtl);

        // チェックサム再計算（ヘッダのみ対象）
        // 再計算前にチェックサムフィールド（10-11バイト目）をゼロにする必要あり
        $cksumPos             = $ipStart + 10;
        $frame[$cksumPos]     = "\x00";
        $frame[$cksumPos + 1] = "\x00";

        $newChecksum = \Utils\Checksum::ipv4HeaderChecksum($frame, $ipStart, $ipHeaderLen);

        // ネットワークバイトオーダーで書き戻し
        $frame[$cksumPos]     = chr(($newChecksum >> 8) & 0xFF);
        $frame[$cksumPos + 1] = chr($newChecksum & 0xFF);

        return $frame;
    }}