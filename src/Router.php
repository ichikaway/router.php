<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Network\Netmask;
use Arp\Arp;
use Arp\ArpCache;

class Router
{
    private array $nic = [];

    private ArpCache $arpTable;

    private readonly array $devices;
    private readonly array $sockets;

    public function __construct(array $nic)
    {
        $this->nic = $nic;

        $devices = [];
        $sockets = [];

        $this->arpTable = new ArpCache();

        foreach ($nic as $k => $nicInfo) {
            $socket = socket_create(AF_PACKET, SOCK_RAW, ETH_P_IP);
            if ($socket === false) {
                die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
            }
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
            socket_bind($socket, $nicInfo['device']);

            $sockets[$nicInfo['device']] = $socket;
            $devices[$nicInfo['device']] = $nicInfo;
        }
        $this->sockets = $sockets;
        $this->devices = $devices;
    }

    public function start()
    {
        var_dump($this->devices);
        while (true) {
            echo "\n ===== start receive =====\n";

            // データ受信. スレッドは使わないためnicを順番にreadして最大1秒でタイムアウトさせて次のnicから読み込み
            $data = null;
            for($readCount = 0 ; true; $readCount++) {
                foreach ($this->sockets as $nicName => $socket) {
                    echo "read from {$nicName} \n";
                    $data = @socket_read($socket, 8000);
                    if ($data === false || $data === '') {
                        echo "タイムアウト: {$readCount} \n";
                    } else {
                        break 2;
                    }
                }
                if ($readCount > 10) {
                    echo "タイムアウト: TCPパケットを受信できませんでした。\n";
                    return $data;
                }
            }

            var_dump("socket_recv buf: " . bin2hex($data) . "\n");

            $ip_header_length = (ord($data[0]) & 0x0F) * 4;  // IPヘッダーの長さを取得
            $tcp_header_start = $ip_header_length;  // TCPヘッダーの開始位置

            // --- Ethernet header (14 bytes)
            $pkt = $data;
            $dstMac = unpack("H*", substr($pkt, 0, 6))[1];
            $srcMac = unpack("H*", substr($pkt, 6, 6))[1];
            $ethType = unpack("n", substr($pkt, 12, 2))[1]; // network-order (big endian)

            echo "  EtherType: 0x" . dechex($ethType) . "\n";
            echo "  Src MAC: " . chunk_split($srcMac, 2, ':') . "\n";
            echo "  Dst MAC: " . chunk_split($dstMac, 2, ':') . "\n";

            if ($ethType !== 0x0800) {
                echo "  Not IPv4, skipping...\n";
                continue;
            }

            // --- IPv4 header (starts at byte 14)
            $ipHeader = substr($pkt, 14, 20); // IHL によっては20〜60バイト
            $ip = unpack("Cversion_ihl/Ctos/nlength/nid/nflags_offset/Cttl/Cproto/nchecksum/Nsrc/Ndst", $ipHeader);

            $ihl = $ip["version_ihl"] & 0x0F;
            $ipHeaderLen = $ihl * 4;

            $srcIp = long2ip($ip["src"]);
            $dstIp = long2ip($ip["dst"]);

            echo "  IP: $srcIp → $dstIp, proto: {$ip['proto']}, TTL: {$ip['ttl']}\n";

            // --- データ部を抽出
            $payload = substr($pkt, 14 + $ipHeaderLen);
            echo "  Payload size: " . strlen($payload) . " bytes\n";

            hexDump($payload) ;



            foreach($this->devices as $device) {
                // 自分のNIC宛のIPアドレスの場合はスルーする。
                if (in_array($device['ip'], [$srcIp, $dstIp])) {
                    echo "same IP of NIC\n";
                    continue 2; // whileループのcontinueを行う
                }

                // src MACがルータのNICの場合は、ルータから外に転送する際のパケットのためこれは処理しない
                if (hexToMac($srcMac) === $device['mac']) {
                    echo "packet from my NIC({$device['device']}). nothing to do. \n";
                    continue 2;
                }
            }

            var_dump($srcMac);
            var_dump(str_replace(':', '', $this->nic[0]['mac']));

            // 宛先IPを見て、自分と同じサブネットのIPアドレスであれば、該当NICからARPを送ってMACアドレスを取得
            // 宛先MACアドレスをARPで取得したMACアドレスに差し替えて送信
            foreach($this->devices as $device) {
                if (Netmask::isSameNetwork($dstIp, $device['ip'], $device['netmask'])) {

                    echo "NIC is {$device['device']}, DestIP: {$dstIp}, NIC IP: {$device['ip']} \n";
                    $dstNewMac = $this->getMacAddress($dstIp, $device['ip'], $device['mac'], $device['device']);
                    if ($dstNewMac === '') {
                        echo "Error dstNewMac is Null, IP: {$dstIp} \n";
                        continue 2;
                    }

                    //  該当ネットワークの自身のNICのMACアドレスを、送信パケットの送信元MACに設定
                    //  宛先IPのMACアドレスを、送信パケットの送信先MACに設定
                    $dstPkt = $pkt;
                    $dstPkt = substr_replace($dstPkt, macToBinary($dstNewMac) . macToBinary($device['mac']), 0, 12);
                    echo "dstPkt: " . bin2hex($dstPkt) . "\n";
                    echo "dstPkt dstMAC: " . hexToMac(bin2hex(substr($dstPkt, 0, 6))) . "\n";
                    echo "dstPkt srcMAC: " . hexToMac(bin2hex(substr($dstPkt, 6, 6))) . "\n";

                    //  IPヘッダのTTLを一つ減らしてチェックサムを再計算する
                    $dstPkt = decrementIPv4TtlAndFixChecksum($dstPkt);
                    if ($dstPkt == null) {
                        echo "dstPkt is null\n";
                        continue;
                    }
                    socket_write($this->sockets[$device['device']], $dstPkt, strlen($dstPkt));
                    break;
                }
            }
        }
    }

    private function getMacAddress(string $dstIp, string $ip, string $mac, string $device): string
    {
        if ($this->arpTable->get($dstIp) !== null) {
            echo "Hit arp table. IP: {$dstIp},\n";
            return $this->arpTable->get($dstIp);
        }
        $Arp = new Arp($ip, $mac, $device);
        $dstNewMac = $Arp->sendArpRequest($dstIp);

        if ($dstNewMac === '') {
            return '';
        }

        $this->arpTable->add($dstIp, $dstNewMac);

        echo "=== ARP reply ===\n";
        var_dump("Dest MAC(bin2hex: " . bin2hex($dstNewMac));
        var_dump("Dest MAC(hexToMac): " . hexToMac($dstNewMac));

        return $dstNewMac;
    }
}


/**
 * 入力: イーサネットフレーム（binary string, socket_read()で得たもの）
 * 出力: TTLを1減算し、IPヘッダチェックサムを再計算したフレーム（binary string）
 *       対象外（IPv4以外/壊れたフレーム/TTL<=1）は null
 */
function decrementIPv4TtlAndFixChecksum(?string $frame): ?string
{
    if ($frame === null) return null;
    $len = strlen($frame);
    if ($len < 14) return null; // Ethernetヘッダ未満

    // --- EtherType / VLAN タグをスキップしてIPv4ヘッダの開始位置を求める ---
    $offset = 12; // EtherType の位置
    $ethType = unpack('n', substr($frame, $offset, 2))[1];
    $l2HeaderLen = 14;

    // VLAN (802.1Q:0x8100 / 0x88A8) を多段にスキップ（Q-in-Q対策）
    while ($ethType === 0x8100 || $ethType === 0x88A8) {
        $l2HeaderLen += 4;                // VLANタグ長分を加算
        if ($len < $l2HeaderLen) return null;
        $offset += 4;                      // 次のEtherTypeへ
        $ethType = unpack('n', substr($frame, $offset, 2))[1];
    }

    if ($ethType !== 0x0800) {
        // IPv4 以外は対象外（ARP/IPv6など）
        return null;
    }

    // --- IPv4 ヘッダ ---
    if ($len < $l2HeaderLen + 20) return null; // 最低20バイト必要
    $ipStart = $l2HeaderLen;

    $vihl  = ord($frame[$ipStart]);       // Version(4bit) + IHL(4bit)
    $version = $vihl >> 4;
    $ihl     = $vihl & 0x0F;              // 32bit words
    if ($version !== 4 || $ihl < 5) return null;

    $ipHeaderLen = $ihl * 4;
    if ($len < $ipStart + $ipHeaderLen) return null;

    // TTL は IPv4 ヘッダ 9バイト目（0始まりなら8）= オフセット ipStart+8
    $ttlPos = $ipStart + 8;
    $oldTtl = ord($frame[$ttlPos]);

    // TTL<=1 はルータ動作としては Time Exceeded 対象。ここではドロップ扱い。
    if ($oldTtl <= 1) {
        return null;
    }

    // TTL を 1 減算して書き戻し
    $newTtl = $oldTtl - 1;
    $frame[$ttlPos] = chr($newTtl);

    // チェックサム再計算（ヘッダのみ対象）
    // 再計算前にチェックサムフィールド（10-11バイト目）をゼロにする必要あり
    $cksumPos = $ipStart + 10;
    $frame[$cksumPos]     = "\x00";
    $frame[$cksumPos + 1] = "\x00";

    $newChecksum = \Utils\Checksum::ipv4HeaderChecksum($frame, $ipStart, $ipHeaderLen);

    // ネットワークバイトオーダーで書き戻し
    $frame[$cksumPos]     = chr(($newChecksum >> 8) & 0xFF);
    $frame[$cksumPos + 1] = chr($newChecksum & 0xFF);

    return $frame;
}