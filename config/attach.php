<?php

// V3 附件分发服务（虚拟地址 + X-Accel-Redirect）
//
// nginx 侧需要配置一个 internal location，把 internal_prefix 映射到 upload 根目录：
//   location /_aoss_internal_attachment/ {
//       internal;
//       alias /path/to/public/upload/;
//   }
// 域名可任意：只要指向本站 PHP + 上述 internal location 即可，不绑定 attach.tuuz.cc。

return [
    // 对外虚拟地址域名前缀（不带尾斜杠）
    'domain'          => rtrim((string)env('ATTACH_DOMAIN', 'https://attach.tuuz.cc'), '/'),

    // Nginx 内部跳转前缀，必须以 / 开头和结尾；与 nginx internal location 一致
    'internal_prefix' => env('ATTACH_INTERNAL_PREFIX', '/_aoss_internal_attachment/'),

    // 本地文件根目录，留空则使用 public_path() . 'upload'
    'upload_root'     => env('ATTACH_UPLOAD_ROOT', ''),

    // 取件码默认有效期（秒）
    'ttl'             => max(1, (int)env('ATTACH_TTL', 3600)),

    // 取件码允许的最大有效期（秒）
    'max_ttl'         => max(1, (int)env('ATTACH_MAX_TTL', 604800)),

    // 虚拟地址的浏览器/CDN 缓存时间（秒）
    'cache_ttl'       => max(0, (int)env('ATTACH_CACHE_TTL', 86400)),

    // image 站 auth_request 回调 upload 鉴权接口时携带的共享密钥
    // 必须配置；未配置时 check 接口一律拒绝，避免鉴权端点被任意调用
    'auth_key'        => (string)env('ATTACH_AUTH_KEY', ''),
];
