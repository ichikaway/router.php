<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Utils\DeviceInfo;

$nic = (new DeviceInfo())->getDevice();
$router = new Router($nic);
$router->start();