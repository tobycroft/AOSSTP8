<?php

namespace app\v3\file\controller;

use app\admin\service\AttachmentFileService;
use app\v2\file\model\FileTokenModel;
use app\v3\common\V3EnvelopeAuth;
use app\v3\file\service\AttachmentDeliveryService;
use app\v3\project\model\V3ProjectModel;
use app\v3\storage\model\V3StorageModel;
use app\v3\storage\model\V3StorageProjectModel;
use BaseController\CommonController;
use Input;
use Ret;
use think\facade\Db;

/**
 * v3 文件上传：临时凭证签发接口（v3 凭证信封鉴权）。
 *
 * 存储来自「项目 ↔ Bucket」授权关系（ao_v3_storage_project）：
 *   - 请求不传 bucket：使用后台设定的默认 Bucket；未设置默认时直接拒绝
 *   - 请求传 bucket：必须是该项目已授权的 Bucket（按 name 精确匹配，兼容纯数字 id）
 *   - Path 决定落盘目录 upload/{Path}/YYYYMMDD/md5.ext（留空回退 Bucket 名称）
 *   - ext / size 决定允许的后缀与单文件大小上限
 *   - type=local 存本站，type=oss 传远程（凭据 oss_tag → ao_oss_aliyun）
 *
 * 签发的一次性 token 落 ao_attachment_token，并记录 storage_id，
 * 供 v3/file/index 直传时定位到对应 Bucket。MD5 秒传仍沿用全局去重：
 * 相同文件只保留一份，返回先命中的 path（用户确认可接受跨项目命中）。
 *
 * 六个接口（含 V1/V2 的 md5 反查能力）：
 *   POST /v3/file/token/create          换取临时 token
 *   POST /v3/file/token/upload_url      换取临时 token + 完整上传地址
 *   POST /v3/file/token/upload_url_hash 换取临时 token + hash 模式上传地址
 *   POST /v3/file/token/md5_check       秒传前置：按 md5 查系统内是否已有文件（跨项目）
 *   POST /v3/file/token/hash_query      按 md5 查询已上传文件完整信息
 *   POST /v3/file/token/md5             按 md5 反查文件完整地址（同 V1 search::md5）
 *   POST /v3/file/token/md5s            按 md5 批量反查文件完整地址（同 V1 search::md5s）
 *   POST /v3/file/token/file_url        按 id/md5 签发时限取件码（本地 Bucket）
 *   POST /v3/file/token/delete          软删文件：移入 upload/recycle/YYYYMMDD/
 *   POST /v3/file/token/restore         从回收站恢复文件
 *
 * 本地 Bucket 的 url 返回虚拟分发地址 /attachment/{bucket_path}/{md5}，
 * 另附 file_url（时限取件码）；OSS Bucket 仍返回原直链。
 */
class token extends CommonController
{
    use V3EnvelopeAuth;

    /** 本次请求解析到的 Bucket（信封鉴权通过后按 bucket 参数或默认配置解析） */
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

        // 按项目 id 解析本次 Bucket：指定则校验授权，未指定则用默认 Bucket
        $projectId = (int)($this->proc['id'] ?? 0);
        $bucketParam = (string)Input::Combi('bucket', false);
        $bucket = V3StorageProjectModel::api_resolve($projectId, $bucketParam);
        if (empty($bucket)) {
            if ($bucketParam !== '') {
                Ret::Fail(403, null, '无权使用该 Bucket，或该 Bucket 未绑定当前项目');
            }
            // 区分「一个 Bucket 都没关联」与「关联了但没设默认」，后者要引导调用方显式传 bucket
            if (!empty(V3StorageProjectModel::api_buckets($projectId))) {
                Ret::Fail(403, null, '该项目未设置默认 Bucket，请在请求中指定 bucket，或在后台「存储项目」设置默认 Bucket');
            }
            Ret::Fail(403, null, '项目未配置存储，请在后台「存储项目」为该项目关联 Bucket');
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

    /**
     * 签发时限取件码（仅本地 Bucket）
     *
     * 参数二选一：id（ao_attachment.id）或 md5/hash。附件必须属于当前项目已授权的
     * 当前 Bucket，避免借其他项目的文件签发访问凭据。expires_in 省略时用配置默认值。
     */
    public function file_url()
    {
        if (!AttachmentDeliveryService::isLocalBucket($this->bucket)) {
            Ret::Fail(403, null, '远程附件不支持时限分发');
        }

        $row = $this->findInCurrentBucket();
        if (empty($row)) {
            Ret::Fail(404, null, '未找到文件，或文件不属于当前 Bucket');
        }

        $maxTtl = max(1, (int)config('attach.max_ttl'));
        $expiresIn = trim((string)Input::Combi('expires_in', false));
        $ttl = $expiresIn === '' ? (int)config('attach.ttl') : (int)$expiresIn;
        if ($ttl < 1 || $ttl > $maxTtl) {
            Ret::Fail(400, null, 'expires_in 需在 1~' . $maxTtl . ' 秒之间');
        }

        $issued = AttachmentDeliveryService::issueTimed($row, $ttl);
        if (empty($issued)) {
            Ret::Fail(403, null, '附件所属项目不可用，无法签发取件码');
        }
        Ret::Success(0, [
            'id'           => (int)$row['id'],
            'md5'          => (string)$row['md5'],
            'url'          => $issued['url'],
            'file_url'     => $issued['url'],
            'expires_in'   => $issued['expires_in'],
            'expired_at'   => $issued['expired_at'],
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

    /**
     * 秒传前置查询：md5 是否已在本系统存在（跨项目，仅 V3 未软删文件）
     *
     * 未命中返回 exists=false（而不是 404），方便 SDK 直接分支：
     * 命中直接拿 path/url 当秒传成功，未命中再申请 token 走标准上传。
     */
    public function md5_check()
    {
        $md5 = Input::Combi('md5', false);
        if ($md5 === '') {
            $md5 = Input::Combi('hash', false);
        }
        $md5 = strtolower(trim((string)$md5));
        if ($md5 === '') {
            Ret::Fail(400, null, '需要md5字段');
        }
        if (preg_match('/^[0-9a-f]{32}$/', $md5) !== 1) {
            Ret::Fail(400, null, 'md5格式不正确');
        }

        $file = $this->findUploadedGlobal($md5);
        if (empty($file)) {
            Ret::Success(0, [
                'exists' => false,
                'path' => '',
                'url' => '',
                'surl' => '',
                'md5' => $md5,
                'file' => null,
            ]);
        }

        $decorated = $this->decorateFile($file);
        Ret::Success(0, [
            'exists' => true,
            'path' => $decorated['path'],
            'url' => $decorated['url'],
            'surl' => $decorated['surl'],
            'md5' => $decorated['md5'],
            'file' => $decorated,
        ]);
    }

    /** 按 hash（md5）查询已上传文件完整信息 */
    public function hash_query()
    {
        // 兼容 V2 的 hash 参数与 V1 的 md5 参数
        $hash = Input::Combi('hash', false);
        if ($hash === '') {
            $hash = Input::Combi('md5', false);
        }
        if (empty($hash)) {
            Ret::Fail(400, null, '需要hash字段');
        }

        $file = $this->findUploaded($hash);
        if (!$file) {
            Ret::Fail(404, null, '未找到文件');
        }

        Ret::Success(0, $this->decorateFile($file));
    }

    /** 按 md5 反查文件完整地址（对齐 V1 search::md5） */
    public function md5()
    {
        $md5 = Input::Combi('md5', false);
        if (empty($md5)) {
            Ret::Fail(400, null, '需要md5字段');
        }

        $file = $this->findUploaded($md5);
        if (!$file) {
            Ret::Fail(404, null, '未找到文件,请先上传');
        }

        Ret::Success(0, $this->decorateFile($file));
    }

    /** 按 md5 批量反查文件完整地址（对齐 V1 search::md5s） */
    public function md5s()
    {
        $md5s = Input::Combi('md5s', false);
        if (empty($md5s)) {
            Ret::Fail(400, null, '需要md5s字段');
        }
        $md5s = json_decode($md5s, true);
        if (!is_array($md5s) || empty($md5s)) {
            Ret::Fail(400, null, 'md5s字段需为JSON数组');
        }

        $rows = Db::name('ao_attachment')
            ->whereIn('md5', $md5s)
            ->where('token', $this->groupKey())
            ->where('tag', 'v3')
            ->where('is_deleted', 0)
            ->select()
            ->toArray();
        if (empty($rows)) {
            Ret::Fail(404, null, '未找到文件,请先上传');
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = $this->decorateFile($row);
        }
        Ret::Success(0, $list);
    }

    /** 软删：按 hash/md5 把文件移入回收站 */
    public function delete()
    {
        $this->changeDeleted(true);
    }

    /** 恢复：按 hash/md5 从回收站移回原路径 */
    public function restore()
    {
        $this->changeDeleted(false);
    }

    /** 删除/恢复共用的按 hash 定位逻辑 */
    private function changeDeleted(bool $delete): void
    {
        $hash = Input::Combi('hash', false);
        if ($hash === '') {
            $hash = Input::Combi('md5', false);
        }
        if (empty($hash)) {
            Ret::Fail(400, null, '需要hash字段');
        }

        $row = Db::name('ao_attachment')
            ->where('md5', $hash)
            ->where('token', $this->groupKey())
            ->where('tag', 'v3')
            ->where('is_deleted', $delete ? 0 : 1)
            ->findOrEmpty();
        if (empty($row)) {
            Ret::Fail(404, null, $delete ? '未找到文件，或文件已在回收站' : '回收站中未找到该文件');
        }

        $result = $delete
            ? AttachmentFileService::delete([(int)$row['id']], 'v3')
            : AttachmentFileService::restore([(int)$row['id']], 'v3');

        if (!empty($result['errors'])) {
            Ret::Fail(500, $result, implode('；', $result['errors']));
        }

        Ret::Success(0, [
            'md5' => $hash,
            'deleted' => $result['deleted'],
            'warnings' => $result['warnings'],
        ], $delete ? '已移入回收站' : '已恢复');
    }

    /** 当前 Bucket 在 ao_attachment 中的分组键（现为项目名称，如 stb） */
    private function groupKey(): string
    {
        return V3StorageModel::api_group_key((int)$this->bucket['id']);
    }

    /**
     * 秒传用：全局（跨项目）按 md5 取一条未软删的 V3 附件
     *
     * 用户确认相同文件只保留一份，跨项目命中也可直接返回既有 path。
     * 只认 V3 活跃行：回收站文件（is_deleted=1）不算命中，避免返回失效地址。
     *
     * @return array 附件行，未命中为 []
     */
    public static function findUploadedGlobal(string $md5): array
    {
        $md5 = strtolower(trim($md5));
        if ($md5 === '') {
            return [];
        }
        return Db::name('ao_attachment')
            ->where('md5', $md5)
            ->where('tag', 'v3')
            ->where('is_deleted', 0)
            ->order('id', 'asc')
            ->findOrEmpty();
    }

    /** 按 md5 取本 Bucket 下已上传的附件行 */
    private function findUploaded(string $md5): array
    {
        return Db::name('ao_attachment')
            ->where('md5', $md5)
            ->where('token', $this->groupKey())
            ->where('tag', 'v3')
            ->where('is_deleted', 0)
            ->findOrEmpty();
    }

    /** 按 id / md5 定位当前 Bucket 下的附件（取件码签发用） */
    private function findInCurrentBucket(): array
    {
        $id = trim((string)Input::Combi('id', false));
        $hash = trim((string)Input::Combi('md5', false));
        if ($hash === '') {
            $hash = trim((string)Input::Combi('hash', false));
        }

        $query = Db::name('ao_attachment')
            ->where('token', $this->groupKey())
            ->where('tag', 'v3')
            ->where('is_deleted', 0);
        if ($id !== '' && ctype_digit($id)) {
            $query->where('id', (int)$id);
        } elseif ($hash !== '') {
            $query->where('md5', strtolower($hash));
        } else {
            Ret::Fail(400, null, '需要 id 或 md5 字段');
            return [];
        }

        $rows = $query->select()->toArray();
        foreach ($rows as $row) {
            if (AttachmentDeliveryService::pathBelongsToBucket($this->bucket, (string)($row['path'] ?? ''))) {
                return $row;
            }
        }
        return [];
    }

    /** 补全附件行的访问地址：src / url / surl / file_url */
    private function decorateFile(array $file): array
    {
        // 跨项目命中时不能用当前 Bucket 的域名拼旧 path；按 path 前缀反查真实 Bucket。
        // 找不到时（历史数据/关联已删）才回退当前 Bucket。
        $bucket = V3StorageProjectModel::api_match_by_path(
            (string)($file['token'] ?? ''),
            (string)($file['path'] ?? '')
        );
        if (empty($bucket)) {
            $bucket = $this->bucket;
        }

        $file['src'] = $file['path'];
        $file['url'] = AttachmentDeliveryService::publicUrl($file, $bucket, true) ?: (string)$file['path'];
        $file['surl'] = $file['path'];

        $issued = AttachmentDeliveryService::isLocalBucket($bucket)
            ? AttachmentDeliveryService::issueTimed($file, (int)config('attach.ttl'))
            : [];
        $file['file_url'] = $issued['url'] ?? '';
        $file['file_expired_at'] = $issued['expired_at'] ?? 0;
        return $file;
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
