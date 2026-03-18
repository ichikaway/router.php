<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dump\Dump;
use Utils\DeviceInfo;

// raspiはeth0とwlan0の2つのNIC。eth系は自動で認識するためwlanのみ指定する
$nic = (new DeviceInfo())->getDevice(['wlan0']);

$router = new Router($nic, new Dump(Dump::ERROR));

// デフォルトルートの指定
$router->setDefaultRoute('192.168.11.1', '255.255.255.0', 'wlan0');

$router->start();