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

\think\facade\Route::any(':version/:module/:controller/:function',
    '\app\:version\:module\controller\:controller@:function')->before(function () {
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


// 后台管理路由
\think\facade\Route::any('admin/captcha/gif', '\app\admin\controller\Captcha@gif');
\think\facade\Route::any('admin/login/index', '\app\admin\controller\Login@index');
\think\facade\Route::any('admin/login/logout', '\app\admin\controller\Login@logout');
\think\facade\Route::any('admin/login/info', '\app\admin\controller\Login@info');
\think\facade\Route::any('admin/user', '\app\admin\controller\User@index');
\think\facade\Route::any('admin/role', '\app\admin\controller\Role@index');
\think\facade\Route::any('admin/role/all', '\app\admin\controller\Role@all');
\think\facade\Route::any('admin/role/bind', '\app\admin\controller\Role@bind');
\think\facade\Route::any('admin/role/getUserRoles', '\app\admin\controller\Role@getUserRoles');
\think\facade\Route::any('admin/menu', '\app\admin\controller\Menu@index');
\think\facade\Route::any('admin/cert', '\app\admin\controller\Cert@index');
\think\facade\Route::any('admin/cert_url/updateSSL', '\app\admin\controller\CertUrl@updateSSL');
\think\facade\Route::any('admin/cert_url/getKey', '\app\admin\controller\CertUrl@getKey');
\think\facade\Route::any('admin/cert_url/autoSSL', '\app\admin\controller\CertUrl@autoSSL');
\think\facade\Route::any('admin/cert_url/autoMailSSL', '\app\admin\controller\CertUrl@autoMailSSL');
\think\facade\Route::any('admin/cert_url', '\app\admin\controller\CertUrl@index');
\think\facade\Route::any('admin/cert_website', '\app\admin\controller\CertWebsite@index');
\think\facade\Route::any('admin/cert_log', '\app\admin\controller\CertLog@index');
\think\facade\Route::any('admin/attachment', '\app\admin\controller\Attachment@index');
\think\facade\Route::any('admin/attachment_token', '\app\admin\controller\AttachmentToken@index');
\think\facade\Route::any('admin/oss', '\app\admin\controller\Oss@index');
\think\facade\Route::any('admin/oss_aliyun', '\app\admin\controller\OssAliyun@index');
\think\facade\Route::any('admin/project', '\app\admin\controller\Project@index');
\think\facade\Route::any('admin/hook', '\app\admin\controller\Hook@index');
\think\facade\Route::any('admin/hook_log', '\app\admin\controller\HookLog@index');
\think\facade\Route::any('admin/console', '\app\admin\controller\Console@index');
\think\facade\Route::any('admin', '\app\admin\controller\Index@index');

\think\facade\Route::any('up', '\app\v1\file\controller\index@up');

\think\facade\Route::any('upfull', '\app\v1\file\controller\index@upfull');

\think\facade\Route::any('hook', '\app\v1\hook\controller\push@single');
\think\facade\Route::any('github', '\app\v1\hook\controller\push@github');


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

\think\facade\Route::any('', function () {
    header("Access-Control-Allow-Origin: *", true);
    header("Access-Control-Max-Age: 86400", true);
    header("Access-Control-Allow-Credentials: true", true);
    header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS", true);
    header("Access-Control-Allow-Headers: *", true);
    if (\think\facade\Request::isOptions()) {
        return false;
    }
    return 'AOSS is a private platform! </br> Aoss is now support GPT-APIs </br> Contact oss@tuuz.cc with your reason to join us! ';
});

