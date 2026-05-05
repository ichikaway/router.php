<?php

namespace Network;

use Arp\Arp;

class Routing
{
    public static function createDestEtherFrame(string $data, string $dstIp, array $Device, string $dstNewMac): string
    {
        if ($dstNewMac === '') {
            /*
            $ipHeader = substr($data, 14, 20); // IHL によっては20〜60バイト
            $ip = unpack("Cversion_ihl/Ctos/nlength/nid/nflags_offset/Cttl/Cproto/nchecksum/Nsrc/Ndst", $ipHeader);
            $srcIp = long2ip($ip["src"]);
            $dstIp2 = long2ip($ip["dst"]);
            $this->Dump->error("  IP: $srcIp → $dstIp2, proto: {$ip['proto']}, TTL: {$ip['ttl']}\n");
            */
            throw new \Exception("Error dstNewMac is Null, IP: {$dstIp} \n");
        }

        //  該当ネットワークの自身のNICのMACアドレスを、送信パケットの送信元MACに設定
        //  宛先IPのMACアドレスを、送信パケットの送信先MACに設定
        //$dstPkt = substr_replace($data, macToBinary($dstNewMac) . macToBinary($Device->getMacAddress()), 0, 12);
        $dstPkt = substr_replace($data, macToBinary($dstNewMac) . $Device['binaryMacAddress'], 0, 12);
        // substr_replaceの方が、下のsubstr組み合わせよりも少しはやい
        //$dstPkt = macToBinary($dstNewMac) . macToBinary($Device->getMacAddress()) . substr($data, 12);
        //$dstPkt = macToBinary($dstNewMac) . $Device->getBinaryMacAddress() . substr($data, 12);

        //$this->Dump->debug("dstPkt: " . bin2hex($dstPkt) . "\n");
        //$this->Dump->debug("dstPkt dstMAC: " . hexToMac(bin2hex(substr($dstPkt, 0, 6))) . "\n");
        //$this->Dump->debug("dstPkt srcMAC: " . hexToMac(bin2hex(substr($dstPkt, 6, 6))) . "\n");

        //  IPヘッダのTTLを一つ減らしてチェックサムを再計算する
        $dstPkt = IpPacket::decrementIPv4TtlAndFixChecksum($dstPkt);
        if ($dstPkt == null) {
            throw new \Exception("dstPkt is null\n");
        }
        return $dstPkt;
    }

    public static function getNextHopByTargetIp(string $dstIp, array $devices, array $default): array
    {
        foreach ($devices as $Device) {
            if (Netmask::isSameNetworkLong(ip2long($dstIp), $Device['ipAddressLong'], $Device['netMaskLong'])) {
                //if (Netmask::isSameNetwork($dstIp, $Device->getIpAddress(), $Device->getNetmask())) {
                return [$dstIp, $Device];
            }
        }
        if (isset($default['gw'])) {
            $Device = $devices[$default['device']];
            $dstIp  = $default['gw'];
            //$this->Dump->debug("Default GW:  {$default['device']}, gwIP: {$dstIp} \n");
            return [$dstIp, $Device];
        }
        throw new \Exception("No route device.");
    }

    public static function getMacAddress(string $dstIp, string $ip, string $mac, string $device): string
    {
        /*
        // 過去にARPで解決したIPかキャッシュ検索
        $resultFromCache = $this->arpTable->get($dstIp);
        if ($resultFromCache !== null) {
            //$this->Dump->debug("Hit arp cache table. IP: {$dstIp},\n");
            return $resultFromCache;
        }

        // 過去にARPで解決できなかったIPのキャッシュを検索
        $noResultFromCache = $this->arpNoResolveTable->get($dstIp);
        if ($noResultFromCache !== null) {
            //$this->Dump->debug("Hit no result arp cache table. IP: {$dstIp},\n");
            return '';
        }

        */

        // ARPキャッシュがヒットしなかったのでARPリクエストを送信して探す
        //$this->Dump->debugArp("start Arp IP: {$ip}\n");
        $Arp = new Arp($ip, $mac, $device);
        $dstNewMac = $Arp->sendArpRequest($dstIp);
        //var_dump("end Arp MAC: {$dstNewMac}\n");
        //$this->Dump->debugArp("end Arp MAC: {$dstNewMac}\n");

        /*
        if ($dstNewMac === '') {
            // ARP解決できなかったIPをキャッシュ
            $this->arpNoResolveTable->add($dstIp, '');
            return '';
        }

        // ARP解決したIPをキャッシュ
        $this->arpTable->add($dstIp, $dstNewMac);
        */

        //$this->Dump->debug("=== ARP reply ===\n");
        //$this->Dump->debug("Dest MAC(bin2hex: " . bin2hex($dstNewMac));
        //$this->Dump->debug("Dest MAC(hexToMac): " . hexToMac($dstNewMac));

        return $dstNewMac;
    }
}