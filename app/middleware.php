<?php
// 全局中间件定义文件
return [
    // aoss.tuuz.cc 仅允许 10.0.0.0/8 内网访问
    \app\middleware\AllowIntranet::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    // \think\middleware\SessionInit::class
];
