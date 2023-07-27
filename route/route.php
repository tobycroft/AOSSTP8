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


\think\facade\Route::any('up', '\app\v1\file\controller\index@up');

\think\facade\Route::any('upfull', '\app\v1\file\controller\index@upfull');

\think\facade\Route::any('hook', '\app\v1\hook\controller\push@single');


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
    return 'AOSS is a private platform! </br> Contact oss@tuuz.cc with your reason to join us! </br> Aoss is now support GPT-APIs';
});

