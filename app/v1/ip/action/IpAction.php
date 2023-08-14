<?php

namespace app\v1\ip\action;

class IpAction
{

    public static function ipInRange($ip, $startIP, $endIP): bool
    {
        $ipLong = ip2long($ip);
        $startIPLong = ip2long($startIP);
        $endIPLong = ip2long($endIP);

        if ($ipLong >= $startIPLong && $ipLong <= $endIPLong) {
            return true;
        }

        return false;
    }

    public static function ipInCIDR($ip, $cidr)
    {
        list($subnet, $maskBits) = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = -1 << (32 - $maskBits);
        $subnetMasked = $subnetLong & $mask;

        if (($ipLong & $mask) === $subnetMasked) {
            return true;
        }

        return false;
    }

    function ipRangeToCIDR($startIP, $endIP)
    {
        $startLong = ip2long($startIP);
        $endLong = ip2long($endIP);

        $result = array();

        while ($endLong >= $startLong) {
            $maxSize = 32 - floor(log(($endLong - $startLong) + 1, 2));
            $mask = long2ip(-1 << (32 - $maxSize));

            $subnet = long2ip($startLong);
            $result[] = "$subnet/$maxSize";

            $startLong += pow(2, (32 - $maxSize));
        }

        return $result;
    }

    function cidrToIPRange($cidr)
    {
        list($subnet, $maskBits) = explode('/', $cidr);
        $subnetLong = ip2long($subnet);
        $mask = -1 << (32 - $maskBits);

        $startIP = long2ip($subnetLong & $mask);
        $endIP = long2ip($subnetLong | (~$mask & 0xFFFFFFFF));

        return array($startIP, $endIP);
    }

}