<?php

namespace app\admin\controller;

use BaseController\CommonController;

class Index extends CommonController
{
    public function index()
    {
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
        <div class="form-group">
            <label>验证码</label>
            <div class="captcha-row">
                <input type="text" id="code" name="code" placeholder="请输入验证码" maxlength="4" required>
                <img id="captchaImg" src="/admin/captcha/gif" alt="验证码" title="点击刷新验证码">
            </div>
        </div>
        <button class="btn" onclick="doLogin()">登 录</button>
        <div id="errorMsg" class="error"></div>
        <div id="successMsg" class="success"></div>
    </form>
</div>
<script>
document.getElementById('captchaImg').onclick = function() { this.src = '/admin/captcha/gif?_=' + Date.now(); };

function doLogin() {
    var username = document.getElementById('username').value;
    var password = document.getElementById('password').value;
    var code = document.getElementById('code').value;
    if (!username || !password || !code) { alert('请填写完整信息'); return; }
    document.getElementById('errorMsg').style.display = 'none';
    document.getElementById('successMsg').style.display = 'none';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/login/index', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                localStorage.setItem('admin_token', res.data.token);
                window.location.href = '/admin/console';
            } else {
                document.getElementById('errorMsg').style.display = 'block';
                document.getElementById('errorMsg').textContent = res.echo;
                document.getElementById('captchaImg').src = '/admin/captcha/gif?_=' + Date.now();
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