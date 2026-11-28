<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

\think\facade\Route::get('think', function () {
    return 'hello,ThinkPHP5!';
});

// V3 附件虚拟地址（PHP 鉴权 + X-Accel-Redirect；必须注册在四段式通用路由之前）
// bucket_path 为 Bucket 可读路径（如 stb_forum）；code 为标准 base64，可含 / + =
// md5/code 末尾允许带安全后缀（.gif/.jpg…），用于让 nginx 按扩展名输出正确 MIME
\think\facade\Route::get('attachment/:bucket_path/:file_md5', '\app\v3\file\controller\delivery@attachment')
    ->pattern(['bucket_path' => '[A-Za-z0-9._\-]+', 'file_md5' => '[0-9a-fA-F]{32}(\.[A-Za-z0-9]{1,10})?']);
\think\facade\Route::get('file/:code', '\app\v3\file\controller\delivery@file')
    ->pattern(['code' => '.+']);

// 四段式通用路由。
// 各段加白名单：version 必须 v1/v2/v3，module/controller 限标识符，function 必须以字母开头。
// 这样 __construct / __destruct / __call 等魔术方法、以及 _ 开头的内部方法都不会被 URL 直接触达。
\think\facade\Route::any(':version/:module/:controller/:function',
    '\app\:version\:module\controller\:controller@:function')
    ->pattern([
        'version'    => 'v[0-9]+',
        'module'     => '[A-Za-z0-9_]+',
        'controller' => '[A-Za-z0-9_]+',
        'function'   => '[A-Za-z][A-Za-z0-9_]*',
    ])
    ->before(function () {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Max-Age: 86400');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    if (\think\facade\Request::isOptions()) {
        return false;
    }
    return true;
});


// 后台管理路由（绑定 aoss.tuuz.cc 域名，仅该主机头可访问；域名根路径直接打开后台）
\think\facade\Route::domain('aoss.tuuz.cc', function () {
    \think\facade\Route::any('', '\app\admin\controller\Index@index');
    \think\facade\Route::any('admin/captcha/gif', '\app\admin\controller\Captcha@gif');
    \think\facade\Route::any('admin/login/index', '\app\admin\controller\Login@index');
    \think\facade\Route::any('admin/login', '\app\admin\controller\Index@index');
    \think\facade\Route::any('admin/login/logout', '\app\admin\controller\Login@logout');
    \think\facade\Route::any('admin/login/info', '\app\admin\controller\Login@info');
    \think\facade\Route::any('admin/user', '\app\admin\controller\User@index');
    \think\facade\Route::any('admin/role', '\app\admin\controller\Role@index');
    \think\facade\Route::any('admin/role/all', '\app\admin\controller\Role@all');
    \think\facade\Route::any('admin/role/bind', '\app\admin\controller\Role@bind');
    \think\facade\Route::any('admin/role/getUserRoles', '\app\admin\controller\Role@getUserRoles');
    \think\facade\Route::any('admin/menu', '\app\admin\controller\Menu@index');
    \think\facade\Route::any('admin/login_log', '\app\admin\controller\LoginLog@index');
    \think\facade\Route::any('admin/portal_login_log', '\app\admin\controller\PortalLoginLog@index');
    \think\facade\Route::any('admin/cert/updateSite', '\app\admin\controller\Cert@updateSite');
    \think\facade\Route::any('admin/cert', '\app\admin\controller\Cert@index');
    \think\facade\Route::any('admin/cert_url/updateSSL', '\app\admin\controller\CertUrl@updateSSL');
    \think\facade\Route::any('admin/cert_url/updateAllSSL', '\app\admin\controller\CertUrl@updateAllSSL');
    \think\facade\Route::any('admin/cert_url/getKey', '\app\admin\controller\CertUrl@getKey');
    \think\facade\Route::any('admin/cert_url/autoSSL', '\app\admin\controller\CertUrl@autoSSL');
    \think\facade\Route::any('admin/cert_url/autoMailSSL', '\app\admin\controller\CertUrl@autoMailSSL');
    \think\facade\Route::any('admin/cert_url/deployIkuai', '\app\admin\controller\CertUrl@deployIkuai');
    \think\facade\Route::any('admin/cert_url/deployPve', '\app\admin\controller\CertUrl@deployPve');
    \think\facade\Route::any('admin/cert_url', '\app\admin\controller\CertUrl@index');
    \think\facade\Route::any('admin/cert_website/batchDelete', '\app\admin\controller\CertWebsite@batchDelete');
    \think\facade\Route::any('admin/cert_website/toggleStatus', '\app\admin\controller\CertWebsite@toggleStatus');
    \think\facade\Route::any('admin/cert_website', '\app\admin\controller\CertWebsite@index');
    \think\facade\Route::any('admin/cert_ikuai/deploy', '\app\admin\controller\CertIkuai@deploy');
    \think\facade\Route::any('admin/cert_ikuai/deployAll', '\app\admin\controller\CertIkuai@deployAll');
    \think\facade\Route::any('admin/cert_ikuai/testLogin', '\app\admin\controller\CertIkuai@testLogin');
    \think\facade\Route::any('admin/cert_ikuai/toggleStatus', '\app\admin\controller\CertIkuai@toggleStatus');
    \think\facade\Route::any('admin/cert_ikuai/batchDelete', '\app\admin\controller\CertIkuai@batchDelete');
    \think\facade\Route::any('admin/cert_ikuai', '\app\admin\controller\CertIkuai@index');
    \think\facade\Route::any('admin/cert_pve/testConnection', '\app\admin\controller\CertPve@testConnection');
    \think\facade\Route::any('admin/cert_pve/deploy', '\app\admin\controller\CertPve@deploy');
    \think\facade\Route::any('admin/cert_pve/deployAll', '\app\admin\controller\CertPve@deployAll');
    \think\facade\Route::any('admin/cert_pve/toggleStatus', '\app\admin\controller\CertPve@toggleStatus');
    \think\facade\Route::any('admin/cert_pve/batchDelete', '\app\admin\controller\CertPve@batchDelete');
    \think\facade\Route::any('admin/cert_pve/certificate', '\app\admin\controller\CertPve@certificate');
    \think\facade\Route::any('admin/cert_pve', '\app\admin\controller\CertPve@index');
    \think\facade\Route::any('admin/cert_log', '\app\admin\controller\CertLog@index');
    \think\facade\Route::any('admin/attachment', '\app\admin\controller\Attachment@index');
    // 回收站附件受控读取（预览/下载）：必须放在 admin/recycle_bin 通配路由之前
    \think\facade\Route::get('admin/recycle_bin/file', '\app\admin\controller\RecycleBin@file');
    \think\facade\Route::any('admin/recycle_bin', '\app\admin\controller\RecycleBin@index');
    \think\facade\Route::any('admin/attachment_token', '\app\admin\controller\AttachmentToken@index');
    \think\facade\Route::any('admin/oss', '\app\admin\controller\Oss@index');
    \think\facade\Route::any('admin/oss_aliyun', '\app\admin\controller\OssAliyun@index');
    \think\facade\Route::any('admin/project', '\app\admin\controller\Project@index');
    \think\facade\Route::any('admin/hook', '\app\admin\controller\Hook@index');
    \think\facade\Route::any('admin/hook_log', '\app\admin\controller\HookLog@index');
    \think\facade\Route::any('admin/excel', '\app\admin\controller\Excel@index');
    \think\facade\Route::any('admin/doc', '\app\admin\controller\Doc@index');

    // 实际文件管理（独立 menu：按 attachment.path 比对扫描无主文件，软删进 recycle / 硬删彻底清除）
    \think\facade\Route::any('admin/real_file/sync', '\app\admin\controller\RealFile@sync');
    \think\facade\Route::any('admin/real_file/soft_delete', '\app\admin\controller\RealFile@softDelete');
    \think\facade\Route::any('admin/real_file/hard_delete', '\app\admin\controller\RealFile@hardDelete');
    \think\facade\Route::any('admin/real_file', '\app\admin\controller\RealFile@index');

    // 系统诊断（功能检测）
    \think\facade\Route::any('admin/diagnose', '\app\admin\controller\Diagnose@index');

    // SMS管理
    \think\facade\Route::any('admin/sms', '\app\admin\controller\Sms@index');
    \think\facade\Route::any('admin/sms_aliyun', '\app\admin\controller\SmsAliyun@index');
    \think\facade\Route::any('admin/sms_tencent', '\app\admin\controller\SmsTencent@index');
    \think\facade\Route::any('admin/sms_lc', '\app\admin\controller\SmsLc@index');
    \think\facade\Route::any('admin/sms_wlwx', '\app\admin\controller\SmsWlwx@index');
    \think\facade\Route::any('admin/sms_blacklist', '\app\admin\controller\SmsBlacklist@index');
    \think\facade\Route::any('admin/sms_intercept', '\app\admin\controller\SmsIntercept@index');
    \think\facade\Route::any('admin/sms_log', '\app\admin\controller\SmsLog@index');

    // 门户邀请码管理
    \think\facade\Route::any('admin/invite_code/toggleStatus', '\app\admin\controller\InviteCode@toggleStatus');
    \think\facade\Route::any('admin/invite_code/updateMaxUses', '\app\admin\controller\InviteCode@updateMaxUses');
    \think\facade\Route::any('admin/invite_code/batchDelete', '\app\admin\controller\InviteCode@batchDelete');
    \think\facade\Route::any('admin/invite_code', '\app\admin\controller\InviteCode@index');

    // Agnes API 管理（密钥独立存储）
    \think\facade\Route::any('admin/agnes/copy', '\app\admin\controller\Agnes@copy');
    \think\facade\Route::any('admin/agnes', '\app\admin\controller\Agnes@index');

    // AI 授权（将 agnes AI key 分配给 v3 项目，多对多）
    \think\facade\Route::any('admin/v3_grant/test', '\app\admin\controller\V3Grant@test');
    \think\facade\Route::any('admin/v3_grant/grants', '\app\admin\controller\V3Grant@grants');
    \think\facade\Route::any('admin/v3_grant', '\app\admin\controller\V3Grant@index');

    // v3 项目管理（只维护鉴权信息；AI 授权、存储配置在各自独立 menu 维护）
    \think\facade\Route::any('admin/v3_project/resetSecret', '\app\admin\controller\V3Project@resetSecret');
    \think\facade\Route::any('admin/v3_project', '\app\admin\controller\V3Project@index');

    // v3 存储管理（Bucket 自身配置；项目与 Bucket 的一对多关系在「存储项目」维护）
    \think\facade\Route::any('admin/v3_storage/credential_options', '\app\admin\controller\V3Storage@credential_options');
    \think\facade\Route::any('admin/v3_storage', '\app\admin\controller\V3Storage@index');

    // v3 存储项目（项目(secret) ↔ 多个 Bucket + 默认 Bucket）
    \think\facade\Route::any('admin/v3_storage_project', '\app\admin\controller\V3StorageProject@index');

    // v3 附件列表（存储管理 V3 → 附件列表；按项目/Bucket 过滤，图片受控预览）
    \think\facade\Route::any('admin/v3_attachment/file', '\app\admin\controller\V3Attachment@file');
    \think\facade\Route::any('admin/v3_attachment/preview_urls', '\app\admin\controller\V3Attachment@preview_urls');
    \think\facade\Route::any('admin/v3_attachment', '\app\admin\controller\V3Attachment@index');

    // v3 目录压力（存储管理 V3 → 目录压力；只读查看每 Bucket+日期的上传计数与落盘子目录）
    \think\facade\Route::any('admin/v3_upload_shard', '\app\admin\controller\V3UploadShard@index');

    // 邮件管理（供 v3 邮件接口使用：服务器配置 → 项目授权 → 内容模板 → 发送日志）
    \think\facade\Route::any('admin/mail_server/test', '\app\admin\controller\MailServer@test');
    \think\facade\Route::any('admin/mail_server', '\app\admin\controller\MailServer@index');
    \think\facade\Route::any('admin/mail_grant/grants', '\app\admin\controller\MailGrant@grants');
    \think\facade\Route::any('admin/mail_grant/test', '\app\admin\controller\MailGrant@test');
    \think\facade\Route::any('admin/mail_grant', '\app\admin\controller\MailGrant@index');
    \think\facade\Route::any('admin/mail_template', '\app\admin\controller\MailTemplate@index');
    \think\facade\Route::any('admin/mail_log', '\app\admin\controller\MailLog@index');

    // DNSPod 管理（密钥独立存储，接入 dnspod 官方接口）
    \think\facade\Route::any('admin/dnspod/test', '\app\admin\controller\Dnspod@test');
    \think\facade\Route::any('admin/dnspod/domains', '\app\admin\controller\Dnspod@domains');
    \think\facade\Route::any('admin/dnspod/records', '\app\admin\controller\Dnspod@records');
    \think\facade\Route::any('admin/dnspod', '\app\admin\controller\Dnspod@index');

    // DNSPod 域名缓存（同步展示）
    \think\facade\Route::any('admin/dnspod_domain/sync', '\app\admin\controller\DnspodDomain@sync');
    \think\facade\Route::any('admin/dnspod_domain', '\app\admin\controller\DnspodDomain@index');

    // DNSPod 子域名（按 name+domain 聚合展示）
    \think\facade\Route::any('admin/dnspod_subdomain/data', '\app\admin\controller\DnspodSubdomain@data');
    \think\facade\Route::any('admin/dnspod_subdomain/records', '\app\admin\controller\DnspodSubdomain@records');
    \think\facade\Route::any('admin/dnspod_subdomain', '\app\admin\controller\DnspodSubdomain@index');

    // DNSPod 解析记录缓存
    \think\facade\Route::any('admin/dnspod_record/status', '\app\admin\controller\DnspodRecord@status');
    \think\facade\Route::any('admin/dnspod_record', '\app\admin\controller\DnspodRecord@index');

    \think\facade\Route::any('admin', '\app\admin\controller\Index@index');
});

// v1 上传入口（up / upfull）已下线，统一使用 v3 文件接口；此处不再注册别名路由。

\think\facade\Route::any('hook', '\app\v1\hook\controller\push@single');
\think\facade\Route::any('github', '\app\v1\hook\controller\push@github');


// 门户用户
\think\facade\Route::any('captcha/gif', '\app\index\controller\Captcha@gif');
\think\facade\Route::any('login/info', '\app\index\controller\Login@info');
\think\facade\Route::any('login/logout', '\app\index\controller\Login@logout');
\think\facade\Route::any('login', '\app\index\controller\Login@index');
\think\facade\Route::any('register', '\app\index\controller\Register@index');
\think\facade\Route::any('en', '\app\index\controller\Index@indexEn');

// 门户图床已下线（随 v1/v2 上传功能一并移除），/image 不再注册路由。

\think\facade\Route::any(':any', function () {
    header("Access-Control-Allow-Origin: *", true);
    header("Access-Control-Max-Age: 86400", true);
    header("Access-Control-Allow-Credentials: true", true);
    header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS", true);
    header("Access-Control-Allow-Headers: *", true);
    if (\think\facade\Request::isOptions()) {
        return false;
    }
    return \think\facade\Request::url();
});

// 首页
\think\facade\Route::any('', '\app\index\controller\Index@index');