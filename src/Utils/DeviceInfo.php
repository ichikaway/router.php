<?php

namespace Utils;

/**
 * RouterマシンにあるNICの情報を取得する
 * NICはeth, MACアドレスはLinuxの/sys/class/net/以下の情報から取得する
 */
class DeviceInfo
{
    /**
     * @param array $specificNicName eth以外のNICを指定する場合に配列で渡す。ethは渡さなくても自動検出される
     * @return mixed
     */
    public function getDevice($specificNicName = [])
    {
        $ifs = net_get_interfaces();
        foreach ($ifs as $name => $if) {
            if (preg_match('/eth[0-9]/', $name) || in_array($name, $specificNicName, true)) {
                foreach ($if["unicast"] as $info) {
                    if (isset($info["address"]) && isset($info["netmask"])) {
                        $nic[] = ['device'  => $name,
                                  'mac'     => self::macFromIf($name),
                                  'ip'      => $info["address"],
                                  'netmask' => $info["netmask"]
                        ];
                    }
                }
            }
        }

        if (count($nic) < 2 || is_null($nic[0]['mac'])) {
            throw new Exception("Device not registered");
        }
        return $nic;
    }

    public static function macFromIf(string $if): ?string
    {
        $path = "/sys/class/net/{$if}/address";
        return is_readable($path) ? strtolower(trim(file_get_contents($path))) : null;
    }
}