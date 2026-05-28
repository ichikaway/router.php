<?php

namespace Sound;

class SendSoundMessage
{


    private string $host = '127.0.0.1';
    private int $port = 57120;

    private $socket;

    private $lastTime;

    private int $packetCount = 0;

    private int $timingCount = 0;

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->lastTime = microtime(true);
    }

    private function oscString(string $s): string
    {
        $s .= "\0";
        while (strlen($s) % 4 !== 0) {
            $s .= "\0";
        }
        return $s;
    }

    public function sendOsc(): void
    {
        $now = microtime(true);
        $this->packetCount++;
        $address = '/kick';

        if (($now - $this->lastTime) >= 0.25) {
            $packet =
                $this->oscString($address) .
                $this->oscString(',');

            if ($this->packetCount == 0) {
                return;
            }
            if ($this->packetCount <= 5) {
                //echo ".";
                socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);
            } else if ($this->packetCount > 5) {
                //echo "*";
                if ($this->timingCount % 2 !== 0) {
                    $address = '/snare';
                    $packet =
                        $this->oscString($address) .
                        $this->oscString(',');
                }
                socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);
            }
            $this->timingCount++;

            $this->packetCount = 0;
            $this->lastTime = $now;
        }
    }

}