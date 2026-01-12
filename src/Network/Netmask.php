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

}