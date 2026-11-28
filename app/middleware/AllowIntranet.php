<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * aoss.tuuz.cc（后台 / 门户）仅允许 10.0.0.0/8 内网访问。
 *
 * 客户端 IP 取值顺序：X-Real-IP → X-Forwarded-For（首个）→ REMOTE_ADDR。
 * 生产环境务必确保 Nginx 覆盖写入 X-Real-IP，且不将客户端自带的同名头透传。
 */
class AllowIntranet
{
    private const HOST = 'aoss.tuuz.cc';

    private const CIDRS = ['10.0.0.0/8'];

    public function handle(Request $request, Closure $next)
    {
        if (!$this->isProtectedHost($request)) {
            return $next($request);
        }

        if (!$this->inCidrs($this->clientIp($request))) {
            return Response::create('Forbidden: aoss.tuuz.cc 仅允许内网访问', 'html', 403);
        }

        return $next($request);
    }

    private function isProtectedHost(Request $request): bool
    {
        foreach ([(string)$request->host(), (string)$request->header('x-forwarded-host', '')] as $host) {
            $host = strtolower(trim(explode(',', $host)[0]));
            $host = preg_replace('/:\d+$/', '', $host);
            if ($host === self::HOST) {
                return true;
            }
        }

        return false;
    }

    private function clientIp(Request $request): string
    {
        $realIp = (string)$request->header('x-real-ip', '');
        if ($realIp !== '') {
            return trim(explode(',', $realIp)[0]);
        }

        $forwarded = (string)$request->header('x-forwarded-for', '');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return (string)$request->ip();
    }

    private function inCidrs(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::CIDRS as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $ipLong = ip2long($ip);
            $netLong = ip2long($net);
            if ($ipLong === false || $netLong === false) {
                continue;
            }
            $mask = -1 << (32 - (int)$bits);
            if (($ipLong & $mask) === ($netLong & $mask)) {
                return true;
            }
        }

        return false;
    }
}
