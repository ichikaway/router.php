<?php

#if (!defined('IPPROTO_IP')) {
#    define('IPPROTO_IP', 0);
#}
if (!defined('IPPROTO_ICMP')) {
    define('IPPROTO_ICMP', 1);
}
if (!defined('IPPROTO_TCP')) {
    define('IPPROTO_TCP', 6);
}
if (!defined('IPPROTO_UDP')) {
    define('IPPROTO_UDP', 17);
}

//define('AF_PACKET', 17);
//define('ETH_P_IP', 0x0800);
//define('ETH_P_ALL', 0x0003);
//define('SOL_PACKET', 263);
//define('PACKET_ADD_MEMBERSHIP', 1);
//define('PACKET_MR_PROMISC', 1);

class Router
{
    private $socket0;

    private $socket1;

    private array $nic = [];

    public function __construct(array $nic)
    {
        $this->nic = $nic;

        $socket0 = socket_create(AF_PACKET, SOCK_RAW, ETH_P_IP);
        if ($socket0 === false) {
            die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
        }
        socket_set_option($socket0, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 555, 'usec' => 0]);

        $socket1 = socket_create(AF_PACKET, SOCK_RAW, ETH_P_IP);
        if ($socket1 === false) {
            die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
        }
        socket_set_option($socket1, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 555, 'usec' => 0]);

        $this->socket0 = $socket0;
        $this->socket1 = $socket1;

        //SO_BINDTODEVICE でNICをbindする方法はPHPでは利用できなかったため、socket_bindを使う
        //$a = socket_set_option($socket0, SOL_SOCKET, SO_BINDTODEVICE, "eth0");
        socket_bind($this->socket0, 'eth0');
        socket_bind($this->socket1, 'eth1');
    }

    public function start()
    {
        $cnt = 0;
        while (true) {
            echo "\n ===== start receive =====\n";
            $buf  = '';
            $from = '';
            $port = 0;

            $cnt++;

            // 受信バッファサイズを定義
            //$data = @socket_recv($this->socket, $buf, 1000, 0);
            if ($cnt % 2) {
                echo "read from socket0 \n";
                $data = @socket_read($this->socket0, 8000);
            } else {
                echo "read from socket1 \n";
                $data = @socket_read($this->socket1, 8000);
            }
            if ($data === false) {
                echo "タイムアウト: TCPパケットを受信できませんでした。\n";
                return $data;
            }
            var_dump("socket_recv buf: " . bin2hex($data) . "\n");

            $ip_header_length = (ord($buf[0]) & 0x0F) * 4;  // IPヘッダーの長さを取得
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

            //todo
            // 自分のNIC宛のIPアドレスの場合はスルーする。
            // 自分以外のIPの場合は、該当のIPと同じネットワークのNICに流す
            //  - 該当ネットワークの自身のNICのMACアドレスを、送信パケットの送信元MACに設定
            //  - 宛先IPのMACアドレスを、送信パケットの送信先MACに設定
            //  - IPヘッダのTTLを一つ減らしてチェックサムを再計算する
            if (in_array($this->nic[0]['ip'], [$srcIp, $dstIp]) || in_array($this->nic[1]['ip'], [$srcIp, $dstIp])) {
                echo "same IP of NIC\n";
                continue;
            }

            $alice = ['ip' => '10.0.0.10', 'mac' => '0e:3d:9c:cc:d3:ba'];
            $bob = ['ip' => '10.0.1.10', 'mac' => '12:59:0c:af:36:54'];

            var_dump($srcMac);
            var_dump(str_replace(':', '', $this->nic[0]['mac']));
            // src MACがルータのNICの場合は、ルータから外に転送する際のパケットのためこれは処理しない
            if ($srcMac === str_replace(':', '', $this->nic[0]['mac'])) {
                echo "packet from my nic(eth0). nothing to do. \n";
                continue;
            }
            if ($srcMac === str_replace(':', '', $this->nic[1]['mac'])) {
                echo "packet from my nic(eth1). nothing to do. \n";
                continue;
            }

            if ($dstIp === $bob['ip']) {
                echo "routing from alice to bob. \n";
                $dstPkt = $pkt;
                $srcNewMac = macToBinary($this->nic[1]['mac']);
                $dstNewMac = macToBinary($bob['mac']);
                $dstPkt = substr_replace($dstPkt, $dstNewMac . $srcNewMac, 0, 12);

                $dstPkt = decrementIPv4TtlAndFixChecksum($dstPkt);
                if ($dstPkt == null) {
                    continue;
                }
                //socket_sendto($this->socket1, $dstPkt, strlen($dstPkt), 0, $dstIp, 0);
                //socket_send($this->socket1, $dstPkt, strlen($dstPkt), 0);
                socket_write($this->socket1, $dstPkt, strlen($dstPkt));
                //hexDump($pkt);
                //hexDump($dstPkt);
                continue;
            }
            if ($dstIp === $alice['ip']) {
                echo "routing from bob to alice. \n";
                $dstPkt = $pkt;
                $srcNewMac = macToBinary($this->nic[0]['mac']);
                $dstNewMac = macToBinary($alice['mac']);
                $dstPkt = substr_replace($dstPkt, $dstNewMac . $srcNewMac, 0, 12);

                $dstPkt = decrementIPv4TtlAndFixChecksum($dstPkt);
                if ($dstPkt == null) {
                    continue;
                }
                //socket_sendto($this->socket1, $dstPkt, strlen($dstPkt), 0, $dstIp, 0);
                //socket_send($this->socket1, $dstPkt, strlen($dstPkt), 0);
                socket_write($this->socket0, $dstPkt, strlen($dstPkt));
                //hexDump($pkt);
                //hexDump($dstPkt);
                continue;
            }
        }
    }
}


function macToBinary(string $mac): string {
    // コロンを削除して連続した16進文字列にする
    $hex = str_replace(':', '', $mac);
    // pack() で16進文字列をバイナリ化
    return pack('H*', $hex);
}

function hexDump($data, $width = 16) {
    $len = strlen($data);
    for ($i = 0; $i < $len; $i += $width) {
        $chunk = substr($data, $i, $width);
        $hex = implode(' ', str_split(bin2hex($chunk), 2));
        $ascii = preg_replace('/[^\x20-\x7E]/', '.', $chunk);
        printf("    %04x: %-48s %s\n", $i, $hex, $ascii);
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

    $newChecksum = ipv4HeaderChecksum($frame, $ipStart, $ipHeaderLen);

    // ネットワークバイトオーダーで書き戻し
    $frame[$cksumPos]     = chr(($newChecksum >> 8) & 0xFF);
    $frame[$cksumPos + 1] = chr($newChecksum & 0xFF);

    return $frame;
}

/**
 * IPv4 ヘッダチェックサム（1の補数和）
 * $buf[$start .. $start+$len-1] の 16bit ワードを加算し、キャリー回し、1の補数。
 * ※ チェックサムフィールドは呼び出し元で 0 にしておくこと。
 */
function ipv4HeaderChecksum(string $buf, int $start, int $len): int
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
