<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Arp\Arp;
use Arp\ArpCache;
use Dump\Dump;
use Network\Device;
use Network\IpPacket;
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

    /** @var array<string, Socket> $sockets */
    private readonly array $sockets;

    /**
     * パケット毎のループでメソッド呼び出しをしないよう、Deviceの情報をindex付きの平坦な配列に前計算しておく
     */
    private readonly int $devCount;
    /** @var array<int, int> $devIpLong NICのIPアドレス(int) */
    private readonly array $devIpLong;
    /** @var array<int, int> $devNetLong NICのネットワークアドレス(ip & netmask) */
    private readonly array $devNetLong;
    /** @var array<int, int> $devMaskLong NICのネットマスク(int) */
    private readonly array $devMaskLong;
    /** @var array<int, string> $devMacBin NICのMACアドレス(6バイトバイナリ) */
    private readonly array $devMacBin;
    /** @var array<int, string> $devName NIC名 */
    private readonly array $devName;
    /** @var array<int, string> $devIpStr NICのIPアドレス(文字列。ARP送信時のみ使用) */
    private readonly array $devIpStr;
    /** @var array<int, string> $devMacStr NICのMACアドレス(コロン区切り。ARP送信時のみ使用) */
    private readonly array $devMacStr;

    private Dump $Dump;

    private array $defaultRouteTable = [];

    /** デフォルトルートのnext hop(int)とNIC index。未設定なら null */
    private ?int $defaultGwLong = null;
    private ?int $defaultDevIdx = null;

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

        // パケット毎のループ用にDeviceの情報を平坦な配列へ展開する
        $devIpLong = $devNetLong = $devMaskLong = $devMacBin = $devName = $devIpStr = $devMacStr = [];
        foreach ($devices as $Device) {
            $ipLong   = $Device->getIpAddressLong();
            $maskLong = $Device->getNetMaskLong();
            $devIpLong[]   = $ipLong;
            $devNetLong[]  = $ipLong & $maskLong;
            $devMaskLong[] = $maskLong;
            $devMacBin[]   = $Device->getBinaryMacAddress();
            $devName[]     = $Device->getDeviceName();
            $devIpStr[]    = $Device->getIpAddress();
            $devMacStr[]   = $Device->getMacAddress();
        }
        $this->devIpLong   = $devIpLong;
        $this->devNetLong  = $devNetLong;
        $this->devMaskLong = $devMaskLong;
        $this->devMacBin   = $devMacBin;
        $this->devName     = $devName;
        $this->devIpStr    = $devIpStr;
        $this->devMacStr   = $devMacStr;
        $this->devCount    = count($devIpLong);
    }

    public function setDefaultRoute(string $gwIp, string $netmask, string $deviceName): void
    {
        $this->defaultRouteTable['gw'] = $gwIp;
        $this->defaultRouteTable['netmask'] = $netmask;
        $this->defaultRouteTable['device'] = $deviceName;

        // 転送時はintのnext hopとNIC indexしか使わないのでここで引いておく
        $idx = array_search($deviceName, $this->devName, true);
        if ($idx === false) {
            throw new \InvalidArgumentException("Unknown device for default route: {$deviceName}");
        }
        $this->defaultGwLong = ip2long($gwIp);
        $this->defaultDevIdx = $idx;
    }

    public function getDefaultRoute(): array
    {
        return $this->defaultRouteTable;
    }

    private function readData(): ?array {
        $readData = [];
        if ($this->handleNic !== null) {
            //$this->Dump->debug("handleNic: " . $this->handleNic . "\n");
            $read = [$this->sockets[$this->handleNic]];
        } else {
            $read = array_values($this->sockets);
        }
        $write = null;
        $except = null;
        socket_select($read, $write, $except, 1);

        if (count($read) === 0) {
            //$this->Dump->debug("socket select again.\n");
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
                //$ret = @socket_recv($socket, $buf, 65535, 0); // 1 recv = 1 frame
                $ret = @socket_recv($socket, $buf, 1600, 0); // 1 recv = 1 frame

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
                // 128や64は多すぎたため32が適正だった <- これはスレッド対応前の話でsocket writeの処理が重かったので32ぐらいが適正だった
                // スレッド対応したら処理回数が増やせたので、Drain readの上限を200にするとさらにスループット出た
                if (++$n >= 200) { // 上限は調整
                    //echo "break";
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

        $nicList = array_keys($this->sockets);

        $chan = [];

        for ($i = 0; $i < $this->workerCount; $i++) {
            $chanName = 'chann-' . $i;
            $runtime[$i] = new Runtime();
            $chan[$i] = Channel::make($chanName, Channel::Infinite);

            $runtime[$i]->run(static function ($chanName) use ($nicList) : void {
                $channel = Channel::open($chanName);

                $sockets = [];

                foreach ($nicList as $Device) {
                    $socket = socket_create(AF_PACKET, SOCK_RAW, ETH_P_IP);
                    if ($socket === false) {
                        die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
                    }

                    // このsocketから送信したデータはreadされないようにする
                    socket_set_option($socket, 263 /*SOL_PACKET*/, 23 /*PACKET_IGNORE_OUTGOING*/, 1);

                    socket_set_nonblock($socket);
                    //socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 10*1024*1024);
                    socket_bind($socket, $Device);
                    $sockets[$Device] = $socket;
                }

                while (true) {
                    list($frame,$deviceName) = $channel->recv();

                    if ($frame === null) {
                        break;
                    }
                    //echo "socket write in thread {$i}. {$deviceName}\n";
                    @socket_write($sockets[$deviceName], $frame, strlen($frame));
                }
            }, [$chanName]);
        }


        var_dump($this->devices);

        // パケット毎のループで $this-> のプロパティ参照をしないようローカル変数に退避する
        $devCount        = $this->devCount;
        $devIpLongList   = $this->devIpLong;
        $devNetLongList  = $this->devNetLong;
        $devMaskLongList = $this->devMaskLong;
        $devMacBinList   = $this->devMacBin;
        $devNameList     = $this->devName;
        $defaultDevIdx   = $this->defaultDevIdx;
        $defaultGwLong   = $this->defaultGwLong;

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
                // Deviceが持つMACアドレスはバイナリ6バイトなので、比較できるようバイナリのまま取り出す
                $srcMac = substr($pkt, 6, 6);
                // EtherTypeの2バイトはord()で合成する。unpack()はsubstrと結果配列を作るぶん遅い
                $ethType = (ord($pkt[12]) << 8) | ord($pkt[13]); // network-order (big endian)

                //$this->Dump->debug("  EtherType: 0x" . dechex($ethType) . "\n");
                //$this->Dump->debug("  Src MAC: " . chunk_split(bin2hex($srcMac), 2, ':') . "\n");

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
                // 転送処理はintのIPアドレスだけで行う。文字列への変換(long2ip)はARP未解決時のみ
                $srcIpLong = $ip["src"];
                $dstIpLong = $ip["dst"];

                //echo("  Src MAC: " . chunk_split($srcMac, 2, ':') . "\n");
                //echo("  IP: $srcIp → $dstIp\n");
                //$this->Dump->debug("  IP: $srcIp → $dstIp, proto: {$ip['proto']}, TTL: {$ip['ttl']}\n");

                // --- データ部を抽出
                //$payload = substr($pkt, 14 + $ipHeaderLen);
                //$this->Dump->debug("  Payload size: " . strlen($payload) . " bytes\n");

                //hexDump($payload) ;

                //todo
                // 同じネットワークのブロードキャストアドレスだった場合はスルーする
                // ブロードキャストアドレスはルーティング対象ではないことと、処理をする場合はARPでMACアドレスの解決ができず処理がそこで詰まるため
                // 255.255.255.255は無視する、同じネットワークのブロードキャストアドレスか判定する
                // NICに依存しない判定なのでNICのループの外に出してNIC数分の評価をやめる
                if (($dstIpLong & 0x000000FF) === 0x000000FF) {
                    continue;
                }

                for ($d = 0; $d < $devCount; $d++) {
                    // 自分のNIC宛のIPアドレスの場合はスルーする。
                    // in_array()は毎回配列を確保するうえ緩い比較になるので === の比較にする
                    $devIpLong = $devIpLongList[$d];
                    if ($devIpLong === $srcIpLong || $devIpLong === $dstIpLong) {
                        //$this->Dump->debug("Skip: Same IP of NIC\n");
                        continue 2; // whileループのcontinueを行う
                    }

                    // src MACがルータのNICの場合は、ルータから外に転送する際のパケットのためこれは処理しない
                    if ($srcMac === $devMacBinList[$d]) {
                        //$this->Dump->debug("Skip: packet from my NIC. nothing to do. \n");
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
                // 宛先IPと同じネットワークのNICを探す。見つからなければデフォルトルートへ
                // メソッド呼び出しを避けるためここに展開している
                $devIdx = -1;
                $nextHopLong = $dstIpLong;
                for ($d = 0; $d < $devCount; $d++) {
                    if ($devNetLongList[$d] === ($dstIpLong & $devMaskLongList[$d])) {
                        $devIdx = $d;
                        break;
                    }
                }
                if ($devIdx === -1) {
                    if ($defaultDevIdx === null) {
                        //$this->Dump->error("No device found for routing.");
                        continue;
                    }
                    $devIdx = $defaultDevIdx;
                    $nextHopLong = $defaultGwLong;
                }

                // 転送できないパケット(ARP未解決/TTL切れ)はnullが返るので捨てる。例外は生成コストが高いので使わない
                $dstPkt = $this->createDestEtherFrame($pkt, $nextHopLong, $devIdx);
                if ($dstPkt === null) {
                    continue;
                }

                //socket_write($this->sockets[$devNameList[$devIdx]], $dstPkt, strlen($dstPkt));
                $writeDeviceName = $devNameList[$devIdx];
                $chan[$cnt]->send([$dstPkt, $writeDeviceName]);
                $cnt++;
                if ($cnt >= $this->workerCount) {
                    $cnt = 0;
                }
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
     * next hopのIPを見て転送するイーサフレームを作成する
     * next hopのIPからMACアドレスをAPRで取得
     * イーサフレームのsrc/dst MACアドレスを書き換える
     * IPパケットのTTLを減らしてチェックサム再計算
     *
     * @param string $data フレーム全体
     * @param int $nextHopLong next hopのIPアドレス(int)
     * @param int $devIdx 送出するNICのindex
     * @return string|null 転送できない場合はnull
     */
    private function createDestEtherFrame(string $data, int $nextHopLong, int $devIdx): ?string
    {
        //$this->Dump->debug("NIC is {$this->devName[$devIdx]}, DestIP: " . long2ip($nextHopLong) . " \n");
        $dstNewMac = $this->getMacAddress($nextHopLong, $devIdx);
        if ($dstNewMac === '') {
            return null;
        }

        //  該当ネットワークの自身のNICのMACアドレスを、送信パケットの送信元MACに設定
        //  宛先IPのMACアドレスを、送信パケットの送信先MACに設定
        $dstPkt = substr_replace($data, $dstNewMac . $this->devMacBin[$devIdx], 0, 12);
        // substr_replaceの方が、下のsubstr組み合わせよりも少しはやい
        //$dstPkt = $dstNewMac . $this->devMacBin[$devIdx] . substr($data, 12);

        //$this->Dump->debug("dstPkt: " . bin2hex($dstPkt) . "\n");

        //  IPヘッダのTTLを一つ減らしてチェックサムを再計算する
        return IpPacket::decrementIPv4TtlAndFixChecksum($dstPkt);
    }

    /**
     * next hopのIP(int)からMACアドレスをバイナリ6バイトで返す。解決できなければ空文字
     */
    private function getMacAddress(int $nextHopLong, int $devIdx): string
    {
        // 過去にARPで解決したIPかキャッシュ検索
        $resultFromCache = $this->arpTable->get($nextHopLong);
        if ($resultFromCache !== null) {
            //$this->Dump->debug("Hit arp cache table.\n");
            return $resultFromCache;
        }

        // 過去にARPで解決できなかったIPのキャッシュを検索
        $noResultFromCache = $this->arpNoResolveTable->get($nextHopLong);
        if ($noResultFromCache !== null) {
            //$this->Dump->debug("Hit no result arp cache table.\n");
            return '';
        }

        // ARPキャッシュがヒットしなかったのでARPリクエストを送信して探す
        // ここは初回のみ通るので、int -> 文字列のIPアドレス変換もここでだけ行う
        $dstIp = long2ip($nextHopLong);
        //$this->Dump->debugArp("start Arp IP: {$dstIp}\n");
        $Arp = new Arp($this->devIpStr[$devIdx], $this->devMacStr[$devIdx], $this->devName[$devIdx]);
        $dstNewMac = $Arp->sendArpRequest($dstIp);
        //$this->Dump->debugArp("end Arp MAC: {$dstNewMac}\n");

        if ($dstNewMac === '') {
            // ARP解決できなかったIPをキャッシュ
            $this->arpNoResolveTable->add($nextHopLong, '');
            return '';
        }

        // ARP解決したIPをキャッシュ
        // 転送時に毎回macToBinary()しなくて済むよう、バイナリ6バイトに変換してから保存する
        $dstNewMacBin = macToBinary($dstNewMac);
        $this->arpTable->add($nextHopLong, $dstNewMacBin);

        //$this->Dump->debug("=== ARP reply ===\n");
        //$this->Dump->debug("Dest MAC(bin2hex: " . bin2hex($dstNewMacBin));

        return $dstNewMacBin;
    }
}