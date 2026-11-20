<?php

namespace app\v3\file\controller;

use app\v2\file\controller\index as V2Index;

/**
 * v3 文件上传：直传落盘入口（复用 v1/v2 的 upload_file，不重写业务逻辑）。
 *
 * 为什么这里不是信封鉴权：
 *   文件上传由浏览器/客户端拿「一次性 token」直传，直传请求无法携带自定义鉴权头，
 *   也不能把 secret 交给前端。因此 V3 的鉴权前移到签发接口（v3/file/token），
 *   直传阶段沿用 ao_attachment_token 的一次性 token，与 v2 行为一致。
 *   这既满足「鉴权方式改为 V3」（签发环节信封化），又不破坏前端直传链路。
 *
 * 可用入口（继承自 v1/v2）：
 *   POST /v3/file/index/upfull   完整上传，返回文件地址
 *   POST /v3/file/index/up       完整上传（相对路径）
 *   POST /v3/file/index/uphash   hash 模式，仅返回 md5
 *   POST /v3/file/index/up_complete  返回完整文件信息
 *
 * 一次性 token 由 v3/file/token 的 create/upload_url/upload_url_hash 签发，
 * 其 oss_token 列写入 V3 项目绑定到的 ao_oss.token，故父类 initialize 能定位到存储配置。
 */
class index extends V2Index
{
}
