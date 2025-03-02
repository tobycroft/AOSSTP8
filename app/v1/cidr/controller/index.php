<?php

namespace app\v1\cidr\controller;

use app\Request;
use BaseController\CommonController;
use Ret;

class index extends CommonController
{

    public function index(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isFile()) {
            Ret::Fail(400, null, 'file字段没有用文件提交');
        }
        $content = file_get_contents($file->getPath() . DIRECTORY_SEPARATOR . $file->getFilename());
//        Ret::Success(0, $content);
        $missingRanges = $this->calculateMissingRanges(explode('\r\n', $content));

        echo "Missing IP Ranges:\n";
        foreach ($missingRanges as $range) {
            echo $range . "\n";
        }
    }

    public function cidrToRange($cidr)
    {
        list($subnet, $mask) = explode('/', $cidr);
        $ip = ip2long($subnet);
        $mask = (int)$mask;  // 强制转换为整数
        $mask = 0xffffffff << (32 - $mask);
        $start = $ip & $mask;
        $end = $start | (~$mask & 0xffffffff);
        return [$start, $end];
    }

// 将 long 转换回 IP
    public function longToIpRange($start, $end)
    {
        return long2ip($start) . ' - ' . long2ip($end);
    }

// 计算给定 CIDR 列表之外的 IP 范围
    public function calculateMissingRanges($cidrList)
    {
        // 将每个 CIDR 转为起始和结束 IP
        $ranges = [];
        foreach ($cidrList as $cidr) {
            list($start, $end) = $this->cidrToRange($cidr);
            $ranges[] = ['start' => $start, 'end' => $end];
        }

        // 排序范围，按起始 IP 排序
        usort($ranges, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        // 计算缺失的范围
        $missingRanges = [];
        $prevEnd = 0;
        $maxIp = ip2long('255.255.255.255'); // IPv4 最大值

        foreach ($ranges as $range) {
            if ($range['start'] > $prevEnd + 1) {
                // 计算前一个范围的结束和当前范围的开始之间的缺失部分
                $missingRanges[] = $this->longToIpRange($prevEnd + 1, $range['start'] - 1);
            }
            $prevEnd = max($prevEnd, $range['end']);
        }

        // 最后检查是否还有大于最后一个 CIDR 结束部分的范围
        if ($prevEnd < $maxIp) {
            $missingRanges[] = $this->longToIpRange($prevEnd + 1, $maxIp);
        }

        return $missingRanges;
    }
}