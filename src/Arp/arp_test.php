<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Arp\Arp;

$nic = (new \Utils\DeviceInfo())->getDevice();
$nic0 = $nic[0];
echo "My ip: ".$nic0['ip'] . ", mac: " . $nic0['mac'] . ", dev: " . $nic0['device'] . "\n";

$Arp = new Arp($nic0['ip'], $nic0['mac'], $nic0['device']);
$bob = ['ip' => '10.0.0.10', 'mac' => ''];
$reply = $Arp->sendArpRequest($bob['ip']);

echo "=== reply ===\n";
var_dump(bin2hex($reply));
var_dump(hexToMac($reply));
