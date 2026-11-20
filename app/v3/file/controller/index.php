<?php

namespace app\v3\file\controller;

use app\v2\file\controller\index as V2Index;
use app\v3\common\UploadFolder;
use Input;
use Ret;
use think\facade\Db;

/**
 * v3 文件上传：直传落盘入口（复用 v1/v2 的 upload_file，不重写业务逻辑）。
 *
 * 为什么这里不是信封鉴权：
 *   文件上传由浏览器/客户端拿「一次性 token」直传，直传请求无法携带自定义鉴权头，
 *   也不能把 secret 交给前端。因此 V3 的鉴权前移到签发接口（v3/file/token），
 *   直传阶段沿用 ao_attachment_token 的一次性 token，与 v2 行为一致。
 *   这既满足「鉴权方式改为 V3」（签发环节信封化），又不破坏前端直传链路。
 *
 * 与 v2 的唯一差异：读取一次性 token 里携带的 upload_folder，覆盖 $this->proc['name']，
 * 让 V1 的 upload_file() 把文件落到 V3 项目配置的文件夹（路径结构仍为 文件夹/YYYYMMDD/md5.ext，
 * local 存本站、OSS 传远程的逻辑完全不变）。upload_folder 为空则回退存储项目名，行为同 v2。
 *
 * 可用入口（继承自 v1/v2）：
 *   POST /v3/file/index/upfull   完整上传，返回文件地址
 *   POST /v3/file/index/up       完整上传（相对路径）
 *   POST /v3/file/index/uphash   hash 模式，仅返回 md5
 *   POST /v3/file/index/up_complete  返回完整文件信息
 *
 * 一次性 token 由 v3/file/token 的 create/upload_url/upload_url_hash 签发。
 */
class index extends V2Index
{
    public function initialize()
    {
        // 先读取一次性 token 携带的文件夹配置（parent 会把 token 标记为 used，
        // 故须在此之前读取；用查询构造器取数组，规避 PHP 8.6 的 toArray 弃用告警）
        $tempToken = Input::Get('token');
        $folder = '';
        if (!empty($tempToken)) {
            $row = Db::name('ao_attachment_token')->where('token', $tempToken)->findOrEmpty();
            if (!empty($row)) {
                $folder = UploadFolder::normalize($row['upload_folder'] ?? '');
            }
        }

        // 走 V2 的完整初始化：CORS、token 校验、proc 装载、标记 used、IP 处理
        parent::initialize();

        // 用 V3 项目配置的文件夹覆盖落盘目录名；存储项目名作回退（V1 默认行为）
        if ($folder !== '' && UploadFolder::isValid($folder)) {
            $this->proc['name'] = $folder;
        }
    }
}
