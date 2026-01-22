<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dump\Dump;
use Utils\DeviceInfo;

$nic = (new DeviceInfo())->getDevice();

//$router = new Router($nic, new Dump(Dump::ALL));
//$router = new Router($nic, new Dump(Dump::NONE));
$router = new Router($nic, new Dump(Dump::NONE), 'eth0', 0);
$router->start();