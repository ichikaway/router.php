<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dump\Dump;
use Utils\DeviceInfo;

// phpのコマンドの引数で、NIC名の指定があればそれを受け取る
$specifiedNicNameList = array_slice($argv, 1);

$nic = (new DeviceInfo())->getDevice($specifiedNicNameList);

$router = new Router($nic, new Dump(Dump::ALL));
//$router = new Router($nic, new Dump(Dump::NONE));
$router->start();