<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Arp\Arp;
use Arp\ArpCache;
use Dump\Dump;
use Network\Device;
use Network\IpPacket;
use Network\Netmask;

class Router
{
    /** @var array<Device> $nic  */
    private array $nic = [];

    private ArpCache $arpTable;

    /** @var array<string, Device> $devices */
    private readonly array $devices;

    /** @var array<string, Socket> $sockets */
    private readonly array $sockets;

    private Dump $Dump;

    private array $defaultRouteTable = [];

    /**
     * 複数のプロセスでそれぞれ入力処理を分ける場合、どのNICでreadを待つか指定する
     * @var string|null
     */
    private readonly ?string $handleNic;

    public function __construct(array $nic, Dump $dump, ?string $handleNic = null)
    {
        $this->Dump = $dump;

        $this->handleNic = $handleNic;

        $this->nic = $nic;

        $devices = [];
        $sockets = [];

        $this->arpTable = new ArpCache();

        /** @var Device $Device */
        foreach ($nic as $Device) {
            $socket = socket_create(AF_PACKET, SOCK_RAW, ETH_P_IP);
            if ($socket === false) {
                die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
            }
            //socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

            // このsocketから送信したデータはreadされないようにする
            socket_set_option($socket, 263 /*SOL_PACKET*/, 23 /*PACKET_IGNORE_OUTGOING*/, 1);

            socket_set_nonblock($socket);
            //socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 10*1024*1024);
            socket_bind($socket, $Device->getDeviceName());

            $sockets[$Device->getDeviceName()] = $socket;
            $devices[$Device->getDeviceName()] = $Device;
        }
        $this->sockets = $sockets;
        $this->devices = $devices;
    }

    public function setDefaultRoute(string $gwIp, string $netmask, string $deviceName): void
    {
        $this->defaultRouteTable['gw'] = $gwIp;
        $this->defaultRouteTable['netmask'] = $netmask;
        $this->defaultRouteTable['device'] = $deviceName;
    }

    public function getDefaultRoute(): array
    {
        return $this->defaultRouteTable;
    }

    private function readData(): ?array {
        $readData = [];
        if ($this->handleNic !== null) {
            $this->Dump->debug("handleNic: " . $this->handleNic . "\n");
            $read = [$this->sockets[$this->handleNic]];
        } else {
            $read = array_values($this->sockets);
        }
        $write = null;
        $except = null;
        socket_select($read, $write, $except, 1);

        if (count($read) === 0) {
            $this->Dump->debug("socket select again.\n");
            return null;
        }

        /*
        // 1回のselectで1回のreadのみ実行
        foreach ($read as $socket) {
            //$nicName = array_search($socket, $this->sockets, true);
            //$this->Dump->debug("read from {$nicName} \n");
            //イーサフレームは1514バイトだが、ジャンボフレームなども考慮して65535にした
            $data = @socket_read($socket, 65535);
            //$data = '';
            //$ret = @socket_recv($socket, $data, 65535, 0); // 1 recv = 1 frame

            if ($data === false || $data === '') {
                $this->Dump->error("read timeout or error \n");
            } else {
                $this->Dump->debug("socket_recv buf: " . bin2hex($data) . "\n");
                $readData[] = $data;
            }
        }
        */

        //Drain read
        // 1回のselectで届いたイーサフレームをできるかぎりreadする
        foreach ($read as $socket) {
            $n = 0;

            while (true) {
                $buf = '';
                //イーサフレームは1514バイトだが、ジャンボフレームなども考慮して65535に
                $ret = @socket_recv($socket, $buf, 65535, 0); // 1 recv = 1 frame

                //$this->Dump->debug("socket_recv buf: " . bin2hex($buf) . "\n");
                if ($ret === false) {
                    $err = socket_last_error($socket);

                    // EAGAIN/EWOULDBLOCK: もう読み尽くした
                    if ($err === SOCKET_EAGAIN || $err === SOCKET_EWOULDBLOCK) {
                        socket_clear_error($socket);
                        break;
                    }

                    // それ以外はエラーとして扱う
                    throw new RuntimeException("socket_recv error: " . socket_strerror($err));
                }

                if ($ret === 0) {
                    // RAW/AF_PACKETで 0 は基本出にくいが、念のため脱出
                    break;
                }

                $readData[] = $buf;
                // 飢餓防止（他ソケットのチャンスを残す）
                if (++$n >= 128) { // 上限は調整
                    break;
                }
            }
        }

        /*
        // 低速版の処理。
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
        */

        return $readData;
    }
    public function start()
    {
        var_dump($this->devices);
        while (true) {
            $this->Dump->info("\n ===== start receive =====\n");

            $readData = $this->readData();
            if ($readData === null) {
                continue;
            }

            foreach ($readData as $data) {
                $ip_header_length = (ord($data[0]) & 0x0F) * 4;  // IPヘッダーの長さを取得
                $tcp_header_start = $ip_header_length;  // TCPヘッダーの開始位置

                // --- Ethernet header (14 bytes)
                $pkt = $data;
                $dstMac = unpack("H*", substr($pkt, 0, 6))[1];
                $srcMac = unpack("H*", substr($pkt, 6, 6))[1];
                $ethType = unpack("n", substr($pkt, 12, 2))[1]; // network-order (big endian)

                $this->Dump->debug("  EtherType: 0x" . dechex($ethType) . "\n");
                $this->Dump->debug("  Src MAC: " . chunk_split($srcMac, 2, ':') . "\n");
                $this->Dump->debug("  Dst MAC: " . chunk_split($dstMac, 2, ':') . "\n");

                if ($ethType !== 0x0800) {
                    $this->Dump->debug("  Not IPv4, skipping...\n");
                    continue ;
                }

                // --- IPv4 header (starts at byte 14)
                $ipHeader = substr($pkt, 14, 20); // IHL によっては20〜60バイト
                $ip = unpack("Cversion_ihl/Ctos/nlength/nid/nflags_offset/Cttl/Cproto/nchecksum/Nsrc/Ndst", $ipHeader);

                $ihl = $ip["version_ihl"] & 0x0F;
                $ipHeaderLen = $ihl * 4;

                $srcIp = long2ip($ip["src"]);
                $dstIp = long2ip($ip["dst"]);

                $this->Dump->debug("  IP: $srcIp → $dstIp, proto: {$ip['proto']}, TTL: {$ip['ttl']}\n");

                // --- データ部を抽出
                $payload = substr($pkt, 14 + $ipHeaderLen);
                $this->Dump->debug("  Payload size: " . strlen($payload) . " bytes\n");

                //hexDump($payload) ;

                foreach($this->devices as $Device) {
                    // 自分のNIC宛のIPアドレスの場合はスルーする。
                    if (in_array($Device->getIpAddress(), [$srcIp, $dstIp])) {
                        $this->Dump->debug("Skip: Same IP of NIC\n");
                        continue 2; // whileループのcontinueを行う
                    }

                    // src MACがルータのNICの場合は、ルータから外に転送する際のパケットのためこれは処理しない
                    if (hexToMac($srcMac) === $Device->getMacAddress()) {
                        $this->Dump->debug("Skip: packet from my NIC({$Device->getDeviceName()}). nothing to do. \n");
                        continue 2;
                    }
                }

                $this->Dump->debug("srcMac: ".$srcMac);

                //todo
                // Routing Table
                // DestNetworkIP(local IP net or default)
                // gateway(local is 0.0.0.0, default is next hop IP)
                // netmask, interfaceを管理
                //
                // 宛先IPを見て、自分と同じサブネットのIPアドレスであれば、該当NICからARPを送ってMACアドレスを取得
                // 宛先MACアドレスをARPで取得したMACアドレスに差し替えて送信
                try {
                    list($dstIp, $Device) = $this->getNextHopByTargetIp($dstIp);
                    $dstPkt = $this->createDestEtherFrame($pkt, $dstIp, $Device);
                } catch (Exception $e) {
                    $this->Dump->error("No device found for routing." . $e->getMessage());
                    continue;
                }

                $sendByte = socket_write($this->sockets[$Device->getDeviceName()], $dstPkt, strlen($dstPkt));
                /*
                //データ送信でエラーがでてるか確認したが、iperfでもエラーがでてなかったのでコメントアウト
                if ($sendByte === false) {
                    var_dump("Error writing to socket\n");
                }
                if ($sendByte !== strlen($dstPkt)) {
                    var_dump("Error writing to socket. sendByte: {$sendByte}\n");
                }
                if ($sendByte > 1000) {
                    var_dump("sendByte: {$sendByte}\n");
                }
                */

            }

        }
    }

    /**
     * dstIpを見て転送するイーサフレームを作成する
     * dstIpからMACアドレスをAPRで取得
     * イーサフレームのsrc/dst MACアドレスを書き換える
     * IPパケットのTTLを減らしてチェックサム再計算
     *
     * @param string $data
     * @param string $dstIp
     * @param Device $Device
     * @return string
     * @throws Exception
     */
    private function createDestEtherFrame(string $data, string $dstIp, Device $Device): string
    {
        $this->Dump->debug("NIC is {$Device->getDeviceName()}, DestIP: {$dstIp}, NIC IP: {$Device->getIpAddress()} \n");
        $dstNewMac = $this->getMacAddress($dstIp, $Device->getIpAddress(), $Device->getMacAddress(), $Device->getDeviceName());
        if ($dstNewMac === '') {
            $ipHeader = substr($data, 14, 20); // IHL によっては20〜60バイト
            $ip = unpack("Cversion_ihl/Ctos/nlength/nid/nflags_offset/Cttl/Cproto/nchecksum/Nsrc/Ndst", $ipHeader);
            $srcIp = long2ip($ip["src"]);
            $dstIp2 = long2ip($ip["dst"]);
            $this->Dump->error("  IP: $srcIp → $dstIp2, proto: {$ip['proto']}, TTL: {$ip['ttl']}\n");

            throw new Exception("Error dstNewMac is Null, IP: {$dstIp} \n");
        }

        //  該当ネットワークの自身のNICのMACアドレスを、送信パケットの送信元MACに設定
        //  宛先IPのMACアドレスを、送信パケットの送信先MACに設定
        $dstPkt = substr_replace($data, macToBinary($dstNewMac) . macToBinary($Device->getMacAddress()), 0, 12);
        $this->Dump->debug("dstPkt: " . bin2hex($dstPkt) . "\n");
        $this->Dump->debug("dstPkt dstMAC: " . hexToMac(bin2hex(substr($dstPkt, 0, 6))) . "\n");
        $this->Dump->debug("dstPkt srcMAC: " . hexToMac(bin2hex(substr($dstPkt, 6, 6))) . "\n");

        //  IPヘッダのTTLを一つ減らしてチェックサムを再計算する
        $dstPkt = IpPacket::decrementIPv4TtlAndFixChecksum($dstPkt);
        if ($dstPkt == null) {
            throw new Exception("dstPkt is null\n");
        }
        return $dstPkt;
    }

    private function getNextHopByTargetIp(string $dstIp): array
    {
        foreach ($this->devices as $Device) {
            if (Netmask::isSameNetwork($dstIp, $Device->getIpAddress(), $Device->getNetmask())) {
                return [$dstIp, $Device];
            }
        }
        $default = $this->getDefaultRoute();
        if (isset($default['gw'])) {
            $Device = $this->devices[$default['device']];
            $dstIp  = $default['gw'];
            $this->Dump->debug("Default GW:  {$default['device']}, gwIP: {$dstIp} \n");
            return [$dstIp, $Device];
        }
        throw new \Exception("No route device.");
    }

    private function getMacAddress(string $dstIp, string $ip, string $mac, string $device): string
    {
        $resultFromCache = $this->arpTable->get($dstIp);
        if ($resultFromCache !== null) {
            $this->Dump->debug("Hit arp table. IP: {$dstIp},\n");
            return $resultFromCache;
        }
        $Arp = new Arp($ip, $mac, $device);
        $dstNewMac = $Arp->sendArpRequest($dstIp);

        if ($dstNewMac === '') {
            return '';
        }

        $this->arpTable->add($dstIp, $dstNewMac);

        $this->Dump->debug("=== ARP reply ===\n");
        $this->Dump->debug("Dest MAC(bin2hex: " . bin2hex($dstNewMac));
        $this->Dump->debug("Dest MAC(hexToMac): " . hexToMac($dstNewMac));

        return $dstNewMac;
    }
}