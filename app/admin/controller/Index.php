<?php

namespace app\admin\controller;

use app\admin\utils\AdminAuth;
use app\admin\utils\LoginGuard;
use BaseController\CommonController;

class Index extends CommonController
{
    public function index()
    {
        // 已登录（admin_token 有效）直接进控制台，不再重复输入密码
        if (!empty(AdminAuth::getLoginUser())) {
            header('Location: /admin/console');
            exit;
        }

        // 风控：该 IP 存在失败登录记录才展示验证码；最近一次登录成功则免验证码
        $isNeedCaptcha = LoginGuard::needCaptcha();
        $captchaStyle = $isNeedCaptcha ? '' : ' style="display:none"';
        $needCaptchaJs = $isNeedCaptcha ? 'true' : 'false';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AOSS 后台管理</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
.login-box { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); padding: 40px; width: 380px; }
.login-box h2 { text-align: center; color: #333; margin-bottom: 30px; font-size: 24px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 6px; color: #555; font-size: 14px; }
.form-group input { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.3s; }
.form-group input:focus { border-color: #4096ff; }
.captcha-row { display: flex; gap: 10px; }
.captcha-row input { flex: 1; }
.captcha-row img { height: 42px; border-radius: 4px; cursor: pointer; border: 1px solid #d9d9d9; }
.btn { width: 100%; padding: 10px; background: #1677ff; color: #fff; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; transition: background 0.3s; }
.btn:hover { background: #4096ff; }
.error { color: #ff4d4f; font-size: 13px; margin-top: 10px; display: none; }
.success { color: #52c41a; font-size: 13px; margin-top: 10px; display: none; }
.tip { color: #faad14; font-size: 13px; margin-top: -12px; margin-bottom: 12px; display: none; }
</style>
</head>
<body>
<div class="login-box">
    <h2>AOSS 后台管理</h2>
    <form id="loginForm" onsubmit="return false;">
        <div class="form-group">
            <label>用户名</label>
            <input type="text" id="username" name="username" placeholder="请输入用户名" required>
        </div>
        <div class="form-group">
            <label>密码</label>
            <input type="password" id="password" name="password" placeholder="请输入密码" required>
        </div>
        <div class="tip" id="captchaTip">检测到该网络存在登录失败记录，请输入验证码</div>
        <div class="form-group" id="captchaGroup"{$captchaStyle}>
            <label>验证码</label>
            <div class="captcha-row">
                <input type="text" id="code" name="code" placeholder="请输入验证码" maxlength="4" autocomplete="off">
                <img id="captchaImg" alt="验证码" title="点击刷新验证码">
            </div>
        </div>
        <button class="btn" onclick="doLogin()">登 录</button>
        <div id="errorMsg" class="error"></div>
        <div id="successMsg" class="success"></div>
    </form>
</div>
<script>
var needCaptcha = {$needCaptchaJs};

function refreshCaptcha() {
    document.getElementById('captchaImg').src = '/admin/captcha/gif?_=' + Date.now();
}

// refresh=true 时强制重新加载验证码图片（服务端会同时写入新的校验 hash）
function applyCaptchaState(refresh) {
    var group = document.getElementById('captchaGroup');
    var tip = document.getElementById('captchaTip');
    var input = document.getElementById('code');
    var img = document.getElementById('captchaImg');
    if (needCaptcha) {
        group.style.display = '';
        tip.style.display = 'block';
        input.required = true;
        if (refresh || !img.getAttribute('src')) {
            refreshCaptcha();
        }
    } else {
        group.style.display = 'none';
        tip.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

document.getElementById('captchaImg').onclick = function () { refreshCaptcha(); };
applyCaptchaState(true);

function doLogin() {
    var username = document.getElementById('username').value;
    var password = document.getElementById('password').value;
    var code = document.getElementById('code').value;
    if (!username || !password) { alert('请填写完整信息'); return; }
    if (needCaptcha && !code) { alert('请输入验证码'); return; }
    document.getElementById('errorMsg').style.display = 'none';
    document.getElementById('successMsg').style.display = 'none';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/login/index', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                document.getElementById('errorMsg').style.display = 'block';
                document.getElementById('errorMsg').textContent = '服务器响应异常，请稍后重试';
                return;
            }
            if (res.code == 0) {
                localStorage.setItem('admin_token', res.data.token);
                window.location.href = '/admin/console';
            } else {
                document.getElementById('errorMsg').style.display = 'block';
                document.getElementById('errorMsg').textContent = res.echo;
                // 登录失败后由服务端下发 need_captcha，前端据此动态展示验证码
                if (res.data && res.data.need_captcha) {
                    needCaptcha = true;
                    applyCaptchaState(true);
                } else if (needCaptcha) {
                    refreshCaptcha();
                }
            }
        }
    };
    xhr.send('username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password) + '&code=' + encodeURIComponent(code));
}
</script>
</body>
</html>
HTML;
        return response($html)->contentType('text/html');
    }
}
