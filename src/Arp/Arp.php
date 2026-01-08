<?php

namespace Arp;

use Socket;

require ('../Utils/functions.php');

if (!defined('ETH_P_ARP')) {
    define('ETH_P_ARP', 0x0806);
}

class Arp
{
    private Socket $socket;

    private string $targetIp;
    private string $targetMac;

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
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 555, 'usec' => 0]);
        $this->socket = $socket;
        socket_bind($this->socket, $nic);
    }

    public function sendArpRequest(string $destIp): string
    {
        $etherFrame = $this->createArpRequestPayload($destIp);
        socket_write($this->socket, $etherFrame, strlen($etherFrame));
        while(1) {
            $recv = socket_read($this->socket, 1024);
            if ($recv !== $etherFrame) {
                return $recv;
            }
        }
    }

    public function getDestMac(string $data): string
    {
        // todo
        // check ARP reply
        // check source IP = dest IP
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
        echo "close socket.\n";
        unset($this->socket);
    }
}

