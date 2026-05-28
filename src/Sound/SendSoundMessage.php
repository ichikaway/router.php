<?php

namespace Sound;

class SendSoundMessage
{


    private string $host = '127.0.0.1';
    private int $port = 57120;

    private $socket;

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
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
        $packet =
            $this->oscString($address) .
            $this->oscString(',');

        socket_sendto($this->socket, $packet, strlen($packet), 0, $this->host, $this->port);
    }

}