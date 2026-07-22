<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Arp\Arp;
use Arp\ArpCache;
use Dump\Dump;
use Network\Device;
use Network\IpPacket;
use Network\Netmask;
use Xdp\XskSocket;
use parallel\Runtime;
use parallel\Channel;

class Router
{
    private int $workerCount = 1;

    /** @var array<Device> $nic  */
    private array $nic = [];

    private ArpCache $arpTable;

    private ArpCache $arpNoResolveTable;

    /** @var array<string, Device> $devices */
    private readonly array $devices;

    /** @var array<string, XskSocket> $sockets AF_XDPのRX+TX兼用ハンドル(自NIC分のみ) */
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
        $this->arpNoResolveTable = new ArpCache(10); //ARPで解決できなかったIPのキャッシュテーブル。10回テーブル検索でクリアする

        /** @var Device $Device */
        foreach ($nic as $Device) {
            // readはAF_XDP(XskSocket::open(), RX+TX兼用ハンドル)。AF_XDPのXSKMAPは
            // (インターフェース, queue)ごとに1ソケットしか登録できず、後から同じNICで
            // xdpphp_open()したプロセスがプログラムごと差し替えてしまう。
            // start_eth0.php/start_eth1.phpのようにプロセスを分けてNICごとにreadする
            // 構成では、$handleNicが指定するNIC以外にAF_XDP受信ソケットを作ってはいけない。
            // (write用のAF_PACKETフォールバックソケットは全NIC分必要なため$devicesは
            // 常に全件保持する)
            if ($handleNic === null || $Device->getDeviceName() === $handleNic) {
                $sockets[$Device->getDeviceName()] = XskSocket::open($Device->getDeviceName(), $Device->getIpAddress());
            }
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
            $targets = [$this->sockets[$this->handleNic]];
        } else {
            $targets = array_values($this->sockets);
        }

        // AF_XDPは非ブロッキングのポーリングなので、select相当は行わず
        // 各ソケットを直接ドレインする(1回の呼び出しで最大200フレームまで)
        foreach ($targets as $socket) {
            $n = 0;
            while (($frame = $socket->recvFrame()) !== null) {
                $readData[] = $frame;
                if (++$n >= 200) {
                    break;
                }
            }
        }

        if (count($readData) === 0) {
            return null;
        }

        return $readData;
    }
    public function start()
    {
        // readはAF_XDP(XskSocket, RX+TX兼用ハンドル、自NIC分のみ)。writeも自NIC宛て分は
        // 同じハンドルのTX ringを、別スレッド(parallel)からsendFrame()で使う。
        // AF_XDPのbind()は(ifindex, queue)単位で排他的なため、他プロセスが読んでいる
        // NIC(他NIC)へはこのプロセスからAF_XDPで書き込めない(実測でEBUSY確認済み)。
        // そのため他NIC宛ての書き込みは従来通りAF_PACKETにフォールバックする。
        // $this->socketsに無いNIC(=他プロセスが担当するNIC)は自動的にこのフォール
        // バック経路になる。
        $nicList = array_keys($this->devices);

        $xdpHandleAddresses = [];
        foreach ($this->sockets as $deviceName => $socket) {
            $xdpHandleAddresses[$deviceName] = $socket->getHandleAddress();
        }

        $chan = [];

        for ($i = 0; $i < $this->workerCount; $i++) {
            $chanName = 'chann-' . $i;
            $runtime[$i] = new Runtime(__DIR__ . '/../vendor/autoload.php');
            $chan[$i] = Channel::make($chanName, Channel::Infinite);

            $runtime[$i]->run(static function ($chanName, $xdpHandleAddresses) use ($nicList) : void {
                $channel = Channel::open($chanName);

                $xdpSockets = [];
                $packetSockets = [];

                foreach ($nicList as $deviceName) {
                    if (isset($xdpHandleAddresses[$deviceName])) {
                        $xdpSockets[$deviceName] = XskSocket::fromHandleAddress(
                            $deviceName,
                            $xdpHandleAddresses[$deviceName],
                        );
                        continue;
                    }

                    $socket = socket_create(AF_PACKET, SOCK_RAW, ETH_P_IP);
                    if ($socket === false) {
                        die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
                    }
                    socket_bind($socket, $deviceName);
                    $packetSockets[$deviceName] = $socket;
                }

                while (true) {
                    list($frame, $deviceName) = $channel->recv();

                    if ($frame === null) {
                        break;
                    }

                    if (isset($xdpSockets[$deviceName])) {
                        $xdpSockets[$deviceName]->sendFrame($frame);
                        continue;
                    }
                    @socket_write($packetSockets[$deviceName], $frame, strlen($frame));
                }
            }, [$chanName, $xdpHandleAddresses]);
        }

        var_dump($this->devices);
        while (true) {
            //$this->Dump->info("\n ===== start receive =====\n");

            $cnt = 0;
            $readData = $this->readData();
            if ($readData === null) {
                continue;
            }
            //echo "rdata: " . count($readData) ."\n";
            foreach ($readData as $data) {
                //$ip_header_length = (ord($data[0]) & 0x0F) * 4;  // IPヘッダーの長さを取得
                //$tcp_header_start = $ip_header_length;  // TCPヘッダーの開始位置

                // --- Ethernet header (14 bytes)
                $pkt = $data;
                //$dstMac = unpack("H*", substr($pkt, 0, 6))[1];
                $srcMac = unpack("H*", substr($pkt, 6, 6))[1];
                $ethType = unpack("n", substr($pkt, 12, 2))[1]; // network-order (big endian)

                //$srcMacHex = hexToMac($srcMac);

                //$this->Dump->debug("  EtherType: 0x" . dechex($ethType) . "\n");
                //$this->Dump->debug("  Src MAC: " . chunk_split($srcMac, 2, ':') . "\n");
                //$this->Dump->debug("  Dst MAC: " . chunk_split($dstMac, 2, ':') . "\n");

                if ($ethType !== 0x0800) {
                    //$this->Dump->debug("  Not IPv4, skipping...\n");
                    //echo "no ipv4";
                    continue ;
                }

                // --- IPv4 header (starts at byte 14)
                //$ipHeader = substr($pkt, 14, 20); // IHL によっては20〜60バイト
                //$ip = unpack("Cversion_ihl/Ctos/nlength/nid/nflags_offset/Cttl/Cproto/nchecksum/Nsrc/Ndst", $ipHeader);
                // unpackを必要最低限の箇所に限定
                // offsetの14はイーサヘッダ、12はIPヘッダのIPアドレスの手前までのところ
                $ip = unpack("Nsrc/Ndst", substr($pkt, 14+12, 8));

                //$ihl = $ip["version_ihl"] & 0x0F;
                //$ipHeaderLen = $ihl * 4;

                //$srcIp = long2ip($ip["src"]);
                $dstIp = long2ip($ip["dst"]);
                $srcIpLong = $ip["src"];
                $dstIpLong = $ip["dst"];

                //echo("  Src MAC: " . chunk_split($srcMac, 2, ':') . "\n");
                //echo("  IP: $srcIp → $dstIp\n");
                //$this->Dump->debug("  IP: $srcIp → $dstIp, proto: {$ip['proto']}, TTL: {$ip['ttl']}\n");

                // --- データ部を抽出
                //$payload = substr($pkt, 14 + $ipHeaderLen);
                //$this->Dump->debug("  Payload size: " . strlen($payload) . " bytes\n");

                //hexDump($payload) ;

                foreach($this->devices as $Device) {
                    // 自分のNIC宛のIPアドレスの場合はスルーする。
                    if (in_array($Device->getIpAddressLong(), [$srcIpLong, $dstIpLong])) {
                        //if (in_array($Device->getIpAddress(), [$srcIp, $dstIp])) {
                        //$this->Dump->debug("Skip: Same IP of NIC\n");
                        continue 2; // whileループのcontinueを行う
                    }

                    // src MACがルータのNICの場合は、ルータから外に転送する際のパケットのためこれは処理しない
                    if ($srcMac === $Device->getBinaryMacAddress()) {
                    //if ($srcMacHex === $Device->getMacAddress()) {
                        //$this->Dump->debug("Skip: packet from my NIC({$Device->getDeviceName()}). nothing to do. \n");
                        echo "same data nic";
                        continue 2;
                    }

                    //todo
                    // 同じネットワークのブロードキャストアドレスだった場合はスルーする
                    // ブロードキャストアドレスはルーティング対象ではないことと、処理をする場合はARPでMACアドレスの解決ができず処理がそこで詰まるため
                    // 255.255.255.255は無視する、同じネットワークのブロードキャストアドレスか判定する
                    if (($ip["dst"] & 0x000000FF) === 0x000000FF) {
                        continue 2;
                    }

                }

                //$this->Dump->debug("srcMac: ".$srcMac);

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
                    //$this->Dump->error("No device found for routing." . $e->getMessage());
                    continue;
                }

                $writeDeviceName = $Device->getDeviceName();
                $chan[$cnt]->send([$dstPkt, $writeDeviceName]);
                $cnt++;
                if ($cnt >= $this->workerCount) {
                    $cnt = 0;
                }
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
        //$this->Dump->debug("NIC is {$Device->getDeviceName()}, DestIP: {$dstIp}, NIC IP: {$Device->getIpAddress()} \n");
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
        //$dstPkt = substr_replace($data, macToBinary($dstNewMac) . macToBinary($Device->getMacAddress()), 0, 12);
        $dstPkt = substr_replace($data, macToBinary($dstNewMac) . $Device->getBinaryMacAddress(), 0, 12);
        // substr_replaceの方が、下のsubstr組み合わせよりも少しはやい
        //$dstPkt = macToBinary($dstNewMac) . macToBinary($Device->getMacAddress()) . substr($data, 12);
        //$dstPkt = macToBinary($dstNewMac) . $Device->getBinaryMacAddress() . substr($data, 12);

        //$this->Dump->debug("dstPkt: " . bin2hex($dstPkt) . "\n");
        //$this->Dump->debug("dstPkt dstMAC: " . hexToMac(bin2hex(substr($dstPkt, 0, 6))) . "\n");
        //$this->Dump->debug("dstPkt srcMAC: " . hexToMac(bin2hex(substr($dstPkt, 6, 6))) . "\n");

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
            if (Netmask::isSameNetworkLong(ip2long($dstIp), $Device->getIpAddressLong(), $Device->getNetMaskLong())) {
                //if (Netmask::isSameNetwork($dstIp, $Device->getIpAddress(), $Device->getNetmask())) {
                return [$dstIp, $Device];
            }
        }
        $default = $this->getDefaultRoute();
        if (isset($default['gw'])) {
            $Device = $this->devices[$default['device']];
            $dstIp  = $default['gw'];
            //$this->Dump->debug("Default GW:  {$default['device']}, gwIP: {$dstIp} \n");
            return [$dstIp, $Device];
        }
        throw new \Exception("No route device.");
    }

    private function getMacAddress(string $dstIp, string $ip, string $mac, string $device): string
    {
        // 過去にARPで解決したIPかキャッシュ検索
        $resultFromCache = $this->arpTable->get($dstIp);
        if ($resultFromCache !== null) {
            //$this->Dump->debug("Hit arp cache table. IP: {$dstIp},\n");
            return $resultFromCache;
        }

        // 過去にARPで解決できなかったIPのキャッシュを検索
        $noResultFromCache = $this->arpNoResolveTable->get($dstIp);
        if ($noResultFromCache !== null) {
            //$this->Dump->debug("Hit no result arp cache table. IP: {$dstIp},\n");
            return '';
        }

        // ARPキャッシュがヒットしなかったのでARPリクエストを送信して探す
        //$this->Dump->debugArp("start Arp IP: {$ip}\n");
        $Arp = new Arp($ip, $mac, $device);
        $dstNewMac = $Arp->sendArpRequest($dstIp);
        //$this->Dump->debugArp("end Arp MAC: {$dstNewMac}\n");

        if ($dstNewMac === '') {
            // ARP解決できなかったIPをキャッシュ
            $this->arpNoResolveTable->add($dstIp, '');
            return '';
        }

        // ARP解決したIPをキャッシュ
        $this->arpTable->add($dstIp, $dstNewMac);

        //$this->Dump->debug("=== ARP reply ===\n");
        //$this->Dump->debug("Dest MAC(bin2hex: " . bin2hex($dstNewMac));
        //$this->Dump->debug("Dest MAC(hexToMac): " . hexToMac($dstNewMac));

        return $dstNewMac;
    }
}