<?php

namespace app\v3\file\controller;

use app\v2\file\model\FileTokenModel;
use app\v3\common\UploadFolder;
use app\v3\common\V3EnvelopeAuth;
use app\v3\project\model\V3ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * v3 文件上传：临时凭证签发接口，鉴权改为 v3 凭证信封。
 *
 * 与 v2/file/token 功能一致（换取一次性上传 token / 上传地址 / hash 查询），
 * 区别有二：
 *   1. 鉴权走 V3EnvelopeAuth（X-Aoss-Ts/Kid/Auth），不再用 oss token + md5 签名；
 *   2. 存储配置不来自请求参数，而来自 v3 项目绑定的 ao_oss.token（ao_v3_project.oss_token）；
 *      落盘文件夹来自 v3 项目的 upload_folder（为空回退存储项目名）。
 *
 * 签发的一次性 token 仍落 ao_attachment_token：oss_token 列写入绑定到的 ao_oss.token，
 * upload_folder 列写入项目配置的文件夹，供 v3/file/index 直传时定位存储与落盘目录。
 *
 * 四个接口：
 *   POST /v3/file/token/create          换取临时 token
 *   POST /v3/file/token/upload_url      换取临时 token + 完整上传地址
 *   POST /v3/file/token/upload_url_hash 换取临时 token + hash 模式上传地址
 *   POST /v3/file/token/hash_query      按 md5/hash 查询已上传文件完整信息
 */
class token extends CommonController
{
    use V3EnvelopeAuth;

    /** 绑定到的 ao_oss 存储配置（信封鉴权通过后由 oss_token 解析） */
    protected array $storage = [];

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        // 信封鉴权：凭证来自 ao_v3_project；限流前缀独立计数
        $this->envelopeAuth(
            fn(string $kid) => V3ProjectModel::api_find_kid($kid),
            'v3_file_rl:'
        );

        // 按项目绑定解析存储配置；未绑定或存储不可用时直接拒绝
        $ossToken = (string)($this->proc['oss_token'] ?? '');
        if ($ossToken === '') {
            Ret::Fail(403, null, '项目未绑定存储项目，请在后台「项目管理 v3」配置 oss_token');
        }
        // 用查询构造器直接取数组，避免 Model::toArray() 在 PHP 8.6 下的弃用告警
        $storage = \think\facade\Db::name('ao_oss')
            ->where('token', $ossToken)
            ->where('status', 1)
            ->findOrEmpty();
        if (empty($storage) || (string)($storage['type'] ?? 'none') === 'none') {
            Ret::Fail(403, null, '绑定的存储项目不可用');
        }
        $this->storage = $storage;
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

        $file = \think\facade\Db::name('ao_attachment')->where('md5', $hash)->find();
        if (!$file) {
            Ret::Fail(404, null, '未找到文件');
        }

        $file['src'] = $file['path'];
        $file['url'] = $this->storage['url'] . '/' . $file['path'];
        $file['surl'] = $file['path'];

        Ret::Success(0, $file);
    }

    /**
     * 签发一次性上传 token 并落 ao_attachment_token
     *
     * 与 v2/file/token 逻辑一致；oss_token 写入项目绑定到的 ao_oss.token，
     * upload_folder 写入项目配置的落盘文件夹（为空时留空，直传阶段回退存储项目名），
     * 使直传阶段（v3/file/index）能复用 V1 的落盘逻辑并使用配置的文件夹。
     *
     * @return array{token:string, expired_at:string}
     */
    private function issueToken(): array
    {
        $ossToken = (string)$this->storage['token'];
        $uploadFolder = UploadFolder::normalize($this->proc['upload_folder'] ?? '');
        $ip = Input::Post('ip', false);

        $now = time();
        $tempToken = md5(uniqid('ft_', true) . $ossToken . microtime());
        $expiredAt = date('Y-m-d H:i:s', $now + 300);

        $data = [
            'token' => $tempToken,
            'oss_token' => $ossToken,
            'upload_folder' => $uploadFolder,
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
