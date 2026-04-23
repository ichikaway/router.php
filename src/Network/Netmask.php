<?php

namespace Network;

class Netmask
{
    public static function isSameNetwork(string $from, string $to, string $netMask): bool
    {
        $fromNet = ip2long($from) & ip2long($netMask);
        $toNet = ip2long($to) & ip2long($netMask);
        return $fromNet === $toNet;
    }
    public static function isSameNetworkLong(int $from, int $to, int $netMask): bool
    {
        $fromNet = $from & $netMask;
        $toNet = $to & $netMask;
        return $fromNet === $toNet;
    }

}