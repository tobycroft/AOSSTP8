<?php

namespace app\v3\file\controller;

use app\v1\system\model\SystemParamModel;
use app\v3\file\service\AttachmentDeliveryService;
use app\v3\project\model\V3ProjectModel;
use app\v3\storage\model\V3StorageModel;
use BaseController\CommonController;
use think\facade\Request;

/**
 * V3 附件分发入口
 *
 * 两种部署方式：
 *   1. 文件与 PHP 同站：/attachment、/file 直接调用 X-Accel-Redirect，
 *      由本站 nginx 读盘输出（AttachmentDeliveryService::deliver）。
 *   2. 文件在 image 站、PHP 在 upload 站：image nginx 用 auth_request 调
 *      /v3/file/delivery/check 校验，通过后由 image nginx 本地 alias 读盘。
 *
 *   GET /attachment/{bucket_id}/{file_md5}  永久虚拟地址
 *   GET /file/{code}                        时限取件码地址
 *   ?download=1                             强制下载
 *   GET /v3/file/delivery/check?uri={原始URI} 仅鉴权，不输出文件（供 auth_request）
 *
 * check 接口需要请求头 X-Aoss-Attach-Auth 与 ATTACH_AUTH_KEY 一致。
 */
class delivery extends CommonController
{
    /** check 模式下所有拒绝统一返回 403，适配 nginx auth_request */
    private bool $checkMode = false;

    /** 永久虚拟地址：Bucket id + 文件 MD5 */
    public function attachment()
    {
        $ctx = $this->authorizeVirtual(
            (int)Request::param('bucket_id', 0),
            strtolower(trim((string)Request::param('file_md5', '')))
        );
        AttachmentDeliveryService::deliver($ctx['row'], $ctx['bucket'], $this->wantDownload());
    }

    /** 时限取件码地址：校验到期时间 + 签名后交付 */
    public function file()
    {
        $ctx = $this->authorizeCode((string)Request::param('code', ''));
        AttachmentDeliveryService::deliver(
            $ctx['row'],
            $ctx['bucket'],
            $this->wantDownload(),
            $ctx['expires_at']
        );
    }

    /**
     * 鉴权接口（供 image 站 auth_request 调用）
     *
     * 入参 uri 是客户端原始 URI，例如 /attachment/12/{md5}?download=1。
     * 通过时返回 200 + X-Aoss-Attach-Path（upload 侧查到的安全相对路径），
     * image nginx 用该响应头拼 alias 读取本地文件。
     */
    public function check()
    {
        $this->checkMode = true;
        $this->requireAuthKey();

        $rawUri = trim((string)Request::param('uri', ''));
        if ($rawUri === '') {
            $this->abort(400, '缺少 uri 参数');
        }
        $path = (string)(parse_url($rawUri, PHP_URL_PATH) ?: '');
        parse_str((string)(parse_url($rawUri, PHP_URL_QUERY) ?: ''), $query);

        $segments = explode('/', trim($path, '/'));
        if (count($segments) === 3 && $segments[0] === 'attachment') {
            $ctx = $this->authorizeVirtual((int)$segments[1], (string)$segments[2]);
        } elseif (count($segments) === 2 && $segments[0] === 'file') {
            $ctx = $this->authorizeCode((string)$segments[1]);
        } else {
            $this->abort(400, '不支持的附件地址');
        }

        $relative = AttachmentDeliveryService::safeRelativePath($ctx['row']);
        if ($relative === '') {
            $this->abort(404, '附件路径不合法');
        }

        $expiresAt = $ctx['expires_at'] ?? null;
        $maxAge = $expiresAt === null
            ? max(0, (int)config('attach.cache_ttl'))
            : max(0, $expiresAt - time());

        header('Cache-Control: public, max-age=' . $maxAge);
        header('X-Aoss-Attach-Path: ' . $relative);
        if ($expiresAt !== null) {
            header('X-Aoss-Attach-Expires: ' . $expiresAt);
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';
        exit;
    }

    /** 永久地址鉴权：Bucket 配置 + 附件记录 */
    private function authorizeVirtual(int $bucketId, string $md5): array
    {
        if ($bucketId <= 0 || preg_match('/^[0-9a-f]{32}$/', $md5) !== 1) {
            $this->abort(400, '参数错误');
        }
        $bucket = V3StorageModel::api_find_id($bucketId);
        if (empty($bucket)) {
            $this->abort(404, '存储配置不可用');
        }
        $row = AttachmentDeliveryService::findForBucket($bucketId, $md5);
        if (empty($row)) {
            $this->abort(404, '附件不存在');
        }
        return ['row' => $row, 'bucket' => $bucket, 'expires_at' => null];
    }

    /** 取件码鉴权：到期时间 + 签名 + 附件所属项目/Bucket */
    private function authorizeCode(string $code): array
    {
        $parsed = AttachmentDeliveryService::decodeCode($code);
        if ($parsed === null) {
            $this->abort(400, '取件码格式错误');
        }
        if ($parsed['end'] <= time()) {
            $this->abort(403, '取件码已过期');
        }

        $row = AttachmentDeliveryService::findById($parsed['id']);
        if (empty($row)) {
            $this->abort(404, '附件不存在');
        }
        $secret = V3ProjectModel::api_secret_by_attachment($row);
        if ($secret === '') {
            $this->abort(403, '附件所属项目不可用');
        }
        $expected = AttachmentDeliveryService::sign($parsed['end'], $parsed['id'], $secret);
        if (!hash_equals($expected, $parsed['sign'])) {
            $this->abort(403, '取件码签名无效');
        }

        $bucket = AttachmentDeliveryService::bucketForRow($row);
        if (empty($bucket)) {
            $this->abort(404, '存储配置不可用');
        }
        if (!AttachmentDeliveryService::isLocalBucket($bucket)) {
            $this->abort(403, '远程附件不支持时限分发');
        }
        return ['row' => $row, 'bucket' => $bucket, 'expires_at' => $parsed['end']];
    }

    /** check 接口的共享密钥校验：system_param 优先，env 仅作本地兜底 */
    private function requireAuthKey(): void
    {
        $configured = trim((string)SystemParamModel::api_find_key('attach_auth_key'));
        if ($configured === '') {
            $configured = trim((string)config('attach.auth_key'));
        }
        $provided = trim((string)Request::header('X-Aoss-Attach-Auth', ''));
        if ($configured === '' || $provided === '' || !hash_equals($configured, $provided)) {
            $this->abort(403, '鉴权密钥无效');
        }
    }

    private function wantDownload(?array $source = null): bool
    {
        $value = $source !== null ? ($source['download'] ?? 0) : Request::param('download', 0);
        return $value === 1 || $value === '1' || $value === true || $value === 'download';
    }

    private function abort(int $status, string $message): void
    {
        if ($this->checkMode && $status >= 400) {
            $status = 403; // nginx auth_request 只认 2xx，其余统一拒绝
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $message;
        exit;
    }
}
