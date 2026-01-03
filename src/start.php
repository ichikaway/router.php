<?php
require './DeviceInfo.php';
require './Router.php';

$nic = (new DeviceInfo())->getDevice();
$router = new Router($nic);
$router->start();