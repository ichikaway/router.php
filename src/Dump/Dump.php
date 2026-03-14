<?php

namespace Dump;

class Dump
{
    public const int NONE = 0;
    public const int INFO = 1;
    public const int DEBUG = 2;
    public const int DEBUG_ARP = 3;
    public const int ERROR = 4;
    public const int ALL = 7;

    private int $debugLevel;

    public function __construct(int $debugLevel)
    {
        $this->debugLevel = $debugLevel;
    }

    public function echo(mixed $message, int $level): void
    {
        if ($this->isNone()) {
            return;
        }
        if ($this->debugLevel === $level || $this->isAllDump()) {
            $now = (new \DateTimeImmutable())->format("Y-m-d H:i:s");
            $message = "[{$now}] $message";
            if (is_string($message)) {
                echo $message;
            } else {
                var_dump($message);
            }
        }
    }

    public function info(mixed $message): void
    {
        $this->echo($message, self::INFO);
    }

    public function debug(mixed $message): void
    {
        $this->echo($message, self::DEBUG);
    }

    public function debugArp(mixed $message): void
    {
        $this->echo($message, self::DEBUG_ARP);
    }

    public function error(mixed $message): void
    {
        $this->echo($message, self::ERROR);
    }

    private function isAllDump(): bool
    {
        return $this->debugLevel === self::ALL;
    }

    private function isNone(): bool
    {
        return $this->debugLevel === self::NONE;
    }
}