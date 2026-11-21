<?php

namespace app\v3\file\controller;

use app\v2\file\model\FileTokenModel;
use app\v3\common\V3EnvelopeAuth;
use app\v3\project\model\V3ProjectModel;
use app\v3\storage\model\V3StorageModel;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * v3 文件上传：临时凭证签发接口（v3 凭证信封鉴权）。
 *
 * 存储来自本项目绑定的 Bucket（ao_v3_storage.project_id）：
 *   - Path 决定落盘目录 upload/{Path}/YYYYMMDD/md5.ext（留空回退 Bucket 名称）
 *   - ext / size 决定允许的后缀与单文件大小上限
 *   - type=local 存本站，type=oss 传远程（凭据 oss_tag → ao_oss_aliyun）
 *
 * 签发的一次性 token 落 ao_attachment_token，并记录 storage_id，
 * 供 v3/file/index 直传时定位到对应 Bucket。
 *
 * 四个接口：
 *   POST /v3/file/token/create          换取临时 token
 *   POST /v3/file/token/upload_url      换取临时 token + 完整上传地址
 *   POST /v3/file/token/upload_url_hash 换取临时 token + hash 模式上传地址
 *   POST /v3/file/token/hash_query      按 md5 查询已上传文件完整信息
 */
class token extends CommonController
{
    use V3EnvelopeAuth;

    /** 本项目绑定的 Bucket（信封鉴权通过后按项目 id 解析） */
    protected array $bucket = [];

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        // 信封鉴权：凭证来自 ao_v3_project；限流前缀独立计数
        $this->envelopeAuth(
            fn(string $kid) => V3ProjectModel::api_find_kid($kid),
            'v3_file_rl:'
        );

        // 按项目 id 解析 Bucket；未配置或已停用时直接拒绝
        $projectId = (int)($this->proc['id'] ?? 0);
        $bucket = V3StorageModel::api_find_project($projectId);
        if (empty($bucket)) {
            Ret::Fail(403, null, '项目未配置存储，请在后台「存储管理 V3」为该项目创建 Bucket');
        }
        $this->bucket = $bucket;
    }

    /** 换取一次性上传 token */
    public function create()
    {
        $data = $this->issueToken();
        Ret::Success(0, [
            'token' => $data['token'],
            'expired_at' => $data['expired_at'],
        ]);
    }

    /** 换取一次性 token + 完整上传地址 */
    public function upload_url()
    {
        $data = $this->issueToken();
        $baseUrl = request()->domain() . '/v3/file/index/upfull';
        Ret::Success(0, [
            'token' => $data['token'],
            'expired_at' => $data['expired_at'],
            'url' => $baseUrl,
            'upload_url' => $baseUrl . '?token=' . $data['token'],
        ]);
    }

    /** 换取一次性 token + hash 模式上传地址（上传后仅返回 md5） */
    public function upload_url_hash()
    {
        $data = $this->issueToken();
        $baseUrl = request()->domain() . '/v3/file/index/uphash';
        Ret::Success(0, [
            'token' => $data['token'],
            'expired_at' => $data['expired_at'],
            'url' => $baseUrl,
            'upload_url' => $baseUrl . '?token=' . $data['token'],
        ]);
    }

    /** 按 hash（md5）查询已上传文件完整信息 */
    public function hash_query()
    {
        $hash = Input::Post('hash');
        if (empty($hash)) {
            Ret::Fail(400, null, '需要hash字段');
        }

        $file = \think\facade\Db::name('ao_attachment')
            ->where('md5', $hash)
            ->where('token', V3StorageModel::api_group_key((int)$this->bucket['id']))
            ->find();
        if (!$file) {
            Ret::Fail(404, null, '未找到文件');
        }

        $url = rtrim((string)$this->bucket['url'], '/');
        $file['src'] = $file['path'];
        $file['url'] = $url !== '' ? $url . '/' . $file['path'] : $file['path'];
        $file['surl'] = $file['path'];

        Ret::Success(0, $file);
    }

    /**
     * 签发一次性上传 token 并落 ao_attachment_token
     *
     * 只记录 storage_id：直传阶段据此重新查出 Bucket（含最新 Path/后缀/大小），
     * 因此后台改了 Bucket 配置后，已签发但未使用的 token 也按新配置落盘。
     *
     * @return array{token:string, expired_at:string}
     */
    private function issueToken(): array
    {
        $storageId = (int)$this->bucket['id'];
        $ip = Input::Post('ip', false);

        $now = time();
        $tempToken = md5(uniqid('ft_', true) . $storageId . microtime());
        $expiredAt = date('Y-m-d H:i:s', $now + 300);

        $data = [
            'token' => $tempToken,
            // oss_token 是 V1/V2 的存储标识列（NOT NULL），V3 不使用，显式留空
            'oss_token' => '',
            'storage_id' => $storageId,
            'created_at' => date('Y-m-d H:i:s', $now),
            'expired_at' => $expiredAt,
            'is_used' => 0,
        ];
        if (!empty($ip)) {
            $data['ip'] = $ip;
        }

        FileTokenModel::create($data);
        return ['token' => $tempToken, 'expired_at' => $expiredAt];
    }
}
