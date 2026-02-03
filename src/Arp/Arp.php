<?php

namespace Arp;

use Socket;

if (!defined('ETH_P_ARP')) {
    define('ETH_P_ARP', 0x0806);
}

class Arp
{
    private Socket $socket;

    private readonly string $sourceIp;
    private readonly string $sourceMac;

    private const int ARP_REQUEST = 1;
    private const int ARP_REPLY = 2;

    /**
     * @param string $sourceIp
     * @param string $sourceMac
     * @param string $nic
     */
    public function __construct(string $sourceIp, string $sourceMac, string $nic)
    {
        $this->sourceIp = $sourceIp;
        $this->sourceMac = $sourceMac;
        $this->createSocket($nic);
    }

    public function createSocket(string $nic)
    {
        $socket = socket_create(AF_PACKET, SOCK_RAW, ETH_P_ARP);
        if ($socket === false) {
            die("ソケットの作成に失敗しました: " . socket_strerror(socket_last_error()));
        }
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        $this->socket = $socket;
        socket_bind($this->socket, $nic);
    }

    public function sendArpRequest(string $destIp): string
    {
        $etherFrame = $this->createArpRequestPayload($destIp);
        socket_write($this->socket, $etherFrame, strlen($etherFrame));
        $cnt = 0;
        while(1) {
            $cnt++;
            $recv = socket_read($this->socket, 1024);
            //echo bin2hex($recv) . "\n";
            //echo "read\n";
            if ($recv === false) {
                return "";
            }
            //自分が送信したフレームも読んでしまうため、自分以外のフレームのみ処理
            if ($recv !== $etherFrame) {
                $destMac = $this->getDestMac($recv, $destIp);
                if ($destMac !== "") {
                    return $destMac;
                }
            }
            if ($cnt > 500) {
                return "";
            }
        }
    }

    public function getDestMac(string $data, string $destIp): string
    {
        //echo bin2hex($data) . "\n";

        // check arp packet
        $ethType = unpack("n", substr($data, 12, 2))[1]; // network-order (big endian)
        if ($ethType !== ETH_P_ARP) {
            //echo "ethType is not ETH_P_ARP\n";
            return "";
        }
        $arpReplyData = unpack("nhtype/nptype/Chasize/Cpasize/nopcode/H12sourceMac/NsourceIp/H12destMac/NdestIp", $data, 14); //ethernetヘッダを除いたペイロードを対象にするため14バイト以降から抽出
        //var_dump($arpData);

        // check ARP reply
        if ($arpReplyData['opcode'] !== self::ARP_REPLY) {
            return "";
        }

        // check target IP = arp reply source IP
        // 今回送信したARP RequestのIPに対して、そのIPのマシンからARP Replyが返ってきているかIPを比較してチェック
        // OSが送ったARPなどが混ざる可能性があるため念のためチェックしておく
        if ($destIp === long2ip($arpReplyData['sourceIp'])) {
            // get Mac address
            return $arpReplyData['sourceMac'];
        }
        //var_dump(long2ip($arpData['sourceIp']));
        //var_dump(long2ip($arpData['destIp']));
        return "";
    }

    private function createArpRequestPayload(string $destIp): string
    {
        $destDummyMac = '00:00:00:00:00:00';
        $payload = pack('n', 0x0001); //hardware type: Ether(0x0001)
        $payload .= pack('n', 0x0800); //protocol type: IP
        $payload .= pack('C', 6); //hardware address size: 6 (Mac address 6byte)
        $payload .= pack('C', 4); //protocol address size: 4 (IP address 4byte)
        $payload .= pack('n', self::ARP_REQUEST); // OperationCode: apr request
        $payload .= macToBinary($this->sourceMac);
        $payload .= inet_pton($this->sourceIp);
        $payload .= macToBinary($destDummyMac);
        $payload .= inet_pton($destIp);

        return $this->createEtherHeader() . $payload;
    }

    private function createEtherHeader(): string
    {
        $destMac = 'ff:ff:ff:ff:ff:ff'; //broadcast Mac
        return macToBinary($destMac) .
            macToBinary($this->sourceMac) .
            pack('n', ETH_P_ARP); // 16bit BigEndian
    }

    public function __destruct()
    {
        //echo "close socket.\n";
        unset($this->socket);
    }
}

