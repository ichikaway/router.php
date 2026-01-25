<?php

namespace Network;

class Device
{
    private readonly string $deviceName;
    private readonly string $macAddress;
    private readonly string $ipAddress;
    private readonly string $netmask;

    /**
     * @param string $deviceName
     * @param string $macAddress
     * @param string $ipAddress
     * @param string $netMask
     */
    public function __construct(string $deviceName, string $macAddress, string $ipAddress, string $netmask)
    {
        $this->deviceName = $deviceName;
        $this->macAddress = $macAddress;
        $this->ipAddress = $ipAddress;
        $this->netmask = $netmask;
    }

    public function getDeviceName(): string
    {
        return $this->deviceName;
    }

    public function getMacAddress(): string
    {
        return $this->macAddress;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getNetmask(): string
    {
        return $this->netmask;
    }



}