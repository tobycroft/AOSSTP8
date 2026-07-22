function initClickCaptcha(options) {
    const defaults = {
        token: '',
        ident: 'captcha_' + Date.now() + Math.random().toString(36).substr(2, 9),
        apiUrl: '/v1/captcha',
        onSuccess: function () { },
        onError: function (msg) { }
    };

    const opts = Object.assign({}, defaults, options);

    if (!opts.token) {
        opts.onError('请提供项目token');
        return;
    }

    const bgImg = document.querySelector('.click-captcha-bg');
    const tipEl = document.querySelector('.click-tip');
    const clickCountEl = document.querySelector('.click-count');
    const reloadBtn = document.querySelector('.click-reload-btn');
    const statusEl = document.querySelector('.click-status');
    const container = document.querySelector('.click-captcha-container');

    let captchaData = null;
    let userClicks = [];
    let targetCount = 0;

    generateCaptcha();

    if (container) {
        container.addEventListener('click', function (e) {
            if (!captchaData) return;
            const rect = container.getBoundingClientRect();
            const x = Math.round(e.clientX - rect.left);
            const y = Math.round(e.clientY - rect.top);
            if (y >= captchaData.bg_height) return;

            userClicks.push({ x: x, y: y });
            drawClickMarker(x, y, userClicks.length);

            const remaining = targetCount - userClicks.length;
            if (clickCountEl) clickCountEl.textContent = remaining > 0 ? '还需点击 ' + remaining + ' 个' : '正在验证...';

            if (userClicks.length >= targetCount) verifyClicks();
        });
    }

    if (reloadBtn) {
        reloadBtn.addEventListener('click', function () {
            if (reloadBtn.disabled) return;
            reloadBtn.disabled = true;
            generateCaptcha();
            setTimeout(function () { reloadBtn.disabled = false; }, 500);
        });
    }

    function generateCaptcha() {
        opts.ident = 'captcha_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        userClicks = [];
        clearMarkers();

        const formData = new FormData();
        formData.append('token', opts.token);
        formData.append('ident', opts.ident);

        fetch(opts.apiUrl + '/click/create?t=' + Date.now(), { method: 'POST', body: formData, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code === 0) {
                    captchaData = data.data;
                    targetCount = captchaData.targets_count;
                    bgImg.src = captchaData.bg;
                    if (tipEl) tipEl.textContent = captchaData.tip;
                    if (clickCountEl) clickCountEl.textContent = '还需点击 ' + targetCount + ' 个';
                    if (statusEl) { statusEl.className = 'click-status'; statusEl.textContent = ''; }
                } else {
                    opts.onError(data.echo || '生成验证码失败');
                }
            })
            .catch(function (err) { opts.onError('网络错误: ' + err.message); });
    }

    function drawClickMarker(x, y, num) {
        const marker = document.createElement('div');
        marker.className = 'click-marker';
        marker.style.left = x + 'px';
        marker.style.top = y + 'px';
        marker.textContent = num;
        container.appendChild(marker);
    }

    function clearMarkers() {
        const markers = container.querySelectorAll('.click-marker');
        markers.forEach(function (m) { m.remove(); });
    }

    function verifyClicks() {
        const formData = new FormData();
        formData.append('token', opts.token);
        formData.append('ident', opts.ident);
        formData.append('clicks', JSON.stringify(userClicks));

        fetch(opts.apiUrl + '/click/check', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code === 0) {
                    if (statusEl) { statusEl.className = 'click-status success'; statusEl.textContent = '验证成功！'; }
                    opts.onSuccess();
                } else {
                    if (statusEl) { statusEl.className = 'click-status error'; statusEl.textContent = (data.echo || '验证失败') + '，正在自动刷新...'; }
                    opts.onError(data.echo || '验证失败');
                    setTimeout(function () { generateCaptcha(); }, 1500);
                }
            })
            .catch(function (err) {
                if (statusEl) { statusEl.className = 'click-status error'; statusEl.textContent = '网络错误，正在自动刷新...'; }
                opts.onError('网络错误: ' + err.message);
                setTimeout(function () { generateCaptcha(); }, 1500);
            });
    }
}

export { initClickCaptcha };
export default initClickCaptcha;