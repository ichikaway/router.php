<?php

use Utils\DeviceInfo;

require './Utils/DeviceInfo.php';
require './Router.php';

$nic = (new DeviceInfo())->getDevice();
$router = new Router($nic);
$router->start();