<?php

namespace app\v3\file\controller;

use app\v3\file\service\AttachmentDeliveryService;
use app\v3\project\model\V3ProjectModel;
use app\v3\storage\model\V3StorageModel;
use BaseController\CommonController;
use think\facade\Request;

/**
 * V3 附件分发入口（PHP 鉴权 + Nginx X-Accel-Redirect）
 *
 *   GET /attachment/{bucket_id}/{file_md5}  永久虚拟地址
 *   GET /file/{code}                        时限取件码地址
 *   ?download=1                             强制下载（默认仅安全图片类型 inline）
 *
 * 两个入口都不经过 V3 信封鉴权：永久地址本身不携带凭证，时限地址用签名+到期时间
 * 作为授权凭据。PHP 只校验记录状态、签名和路径，随后交给 Nginx 输出正文。
 */
class delivery extends CommonController
{
    /** 永久虚拟地址：Bucket id + 文件 MD5 */
    public function attachment()
    {
        $bucketId = (int)Request::param('bucket_id', 0);
        $md5 = strtolower(trim((string)Request::param('file_md5', '')));
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

        AttachmentDeliveryService::deliver($row, $bucket, $this->wantDownload());
    }

    /** 时限取件码地址：校验到期时间 + 签名后交付 */
    public function file()
    {
        $code = (string)Request::param('code', '');
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

        AttachmentDeliveryService::deliver($row, $bucket, $this->wantDownload(), $parsed['end']);
    }

    private function wantDownload(): bool
    {
        $value = Request::param('download', 0);
        return $value === 1 || $value === '1' || $value === true || $value === 'download';
    }

    private function abort(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $message;
        exit;
    }
}
