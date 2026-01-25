<?php

namespace Utils;

use Network\Device;

/**
 * RouterマシンにあるNICの情報を取得する
 * NICはeth, MACアドレスはLinuxの/sys/class/net/以下の情報から取得する
 */
class DeviceInfo
{
    /**
     * @param array $specificNicName eth以外のNICを指定する場合に配列で渡す。ethは渡さなくても自動検出される
     * @return array<Device>
     * @throws \Exception
     */
    public function getDevice($specificNicName = []): array
    {
        $nic = [];
        $ifs = net_get_interfaces();
        foreach ($ifs as $name => $if) {
            if (preg_match('/eth[0-9]/', $name) || in_array($name, $specificNicName, true)) {
                foreach ($if["unicast"] as $info) {
                    if (isset($info["address"]) && isset($info["netmask"]) && $this->isIpv4($info["address"])) {
                        $nic[] = new Device(
                            deviceName: $name,
                            macAddress: $this->macFromIf($name),
                            ipAddress: $info["address"],
                            netmask: $info["netmask"]
                        );
                    }
                }
            }
        }
        if (count($nic) < 2 || !is_object($nic[0])) {
            throw new \Exception("Device not registered");
        }
        return $nic;
    }


    private function isIpv4(mixed $ipAddress)
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }
        return false;
    }

    private function macFromIf(string $if): ?string
    {
        $path = "/sys/class/net/{$if}/address";
        return is_readable($path) ? strtolower(trim(file_get_contents($path))) : null;
    }

}