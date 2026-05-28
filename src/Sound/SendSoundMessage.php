<?php

namespace Sound;

class SendSoundMessage
{


    private string $host = '127.0.0.1';
    private int $port = 57120;

    private $socket;

    private $lastTime;

    private int $packetCount = 0;

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->lastTime = time();
    }

    private function oscString(string $s): string
    {
        $s .= "\0";
        while (strlen($s) % 4 !== 0) {
            $s .= "\0";
        }
        return $s;
    }

    public function sendOsc(string $address): void
    {
        $now = time();
        $this->packetCount++;

        if ($now !== $this->lastTime) {
            $packet =
                $this->oscString($address) .
                $this->oscString(',');

            if ($this->packetCount <= 10) {
                //echo ".";
                socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);
            } else if ($this->packetCount > 10) {
                //echo "*";
                socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);
                usleep(10000);
                socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);
            }

            $this->packetCount = 0;
            $this->lastTime = $now;
        }
    }

}