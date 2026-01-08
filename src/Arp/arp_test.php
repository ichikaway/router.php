<?php

use Arp\Arp;

require ('../Utils/DeviceInfo.php');
require ('./Arp.php');

$nic = (new \Utils\DeviceInfo())->getDevice();
$nic0 = $nic[0];
echo "My ip: ".$nic0['ip'] . ", mac: " . $nic0['mac'] . ", dev: " . $nic0['device'] . "\n";

$Arp = new Arp($nic0['ip'], $nic0['mac'], $nic0['device']);
$bob = ['ip' => '10.0.1.10', 'mac' => '12:59:0c:af:36:54'];
$reply = $Arp->sendArpRequest($bob['ip']);

echo "=== reply ===\n";
var_dump(bin2hex($reply));
var_dump(hexToMac($reply));