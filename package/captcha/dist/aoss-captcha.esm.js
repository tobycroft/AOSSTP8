// @aoss/captcha - ESM build
function initSlideCaptacle(options) {
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

    const bgImg = document.querySelector('.captcha-bg');
    const blockImg = document.querySelector('.captcha-block');
    const sliderHandle = document.querySelector('.slider-handle');
    const reloadBtn = document.querySelector('.reload-btn');

    let captchaData = null;
    let eventListenersAdded = false;

    generateCaptcha();

    if (!eventListenersAdded) {
        sliderHandle.addEventListener('mousedown', startDrag);
        document.addEventListener('mousemove', drag);
        document.addEventListener('mouseup', endDrag);
        sliderHandle.addEventListener('touchstart', startDrag);
        document.addEventListener('touchmove', drag);
        document.addEventListener('touchend', endDrag);

        if (reloadBtn) {
            reloadBtn.addEventListener('click', function () {
                if (reloadBtn.disabled) return;
                reloadBtn.disabled = true;
                generateCaptcha();
                setTimeout(function () { reloadBtn.disabled = false; }, 500);
            });
        }

        eventListenersAdded = true;
    }

    function generateCaptcha() {
        opts.ident = 'captcha_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

        const formData = new FormData();
        formData.append('token', opts.token);
        formData.append('ident', opts.ident);

        const url = opts.apiUrl + '/slide/create?t=' + Date.now();

        fetch(url, { method: 'POST', body: formData, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code === 0) {
                    captchaData = data.data;
                    captchaData.pad_top = captchaData.pad_top || 0;
                    captchaData.pad_left = captchaData.pad_left || 0;
                    captchaData.block_width = captchaData.block_width || captchaData.block_size;
                    captchaData.block_height = captchaData.block_height || captchaData.block_size;

                    bgImg.src = captchaData.bg;
                    blockImg.style.width = captchaData.block_width + 'px';
                    blockImg.style.height = captchaData.block_height + 'px';
                    blockImg.style.top = (captchaData.y - captchaData.pad_top) + 'px';
                    blockImg.style.left = (-captchaData.pad_left) + 'px';
                    blockImg.onload = function () { blockImg.style.display = 'block'; };
                    blockImg.src = captchaData.block;

                    sliderHandle.style.left = '0px';
                } else {
                    opts.onError(data.echo || '生成验证码失败');
                }
            })
            .catch(function (err) { opts.onError('网络错误: ' + err.message); });
    }

    function startDrag(e) {
        if (!captchaData) return;
        startX = e.clientX || (e.touches && e.touches[0].clientX);
        startLeft = parseInt(sliderHandle.style.left) || 0;
        sliderHandle.style.background = '#66b1ff';
    }
    let startX = 0, startLeft = 0;

    function drag(e) {
        if (!captchaData) return;
        const clientX = e.clientX || (e.touches && e.touches[0].clientX);
        const deltaX = clientX - startX;
        const newLeft = Math.max(0, Math.min(startLeft + deltaX, captchaData.bg_width - captchaData.block_size));
        sliderHandle.style.left = newLeft + 'px';
        blockImg.style.left = (newLeft - captchaData.pad_left) + 'px';
    }

    function endDrag(e) {
        if (!captchaData) return;
        sliderHandle.style.background = '#409eff';
        const finalX = parseInt(sliderHandle.style.left) || 0;

        const formData = new FormData();
        formData.append('token', opts.token);
        formData.append('ident', opts.ident);
        formData.append('x', finalX);

        fetch(opts.apiUrl + '/slide/check', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code === 0) {
                    sliderHandle.classList.add('success');
                    opts.onSuccess();
                } else {
                    sliderHandle.classList.add('error');
                    opts.onError(data.echo || '验证失败');
                    setTimeout(function () {
                        generateCaptcha();
                        sliderHandle.classList.remove('error');
                    }, 1500);
                }
            })
            .catch(function (err) {
                sliderHandle.classList.add('error');
                opts.onError('网络错误: ' + err.message);
                setTimeout(function () { sliderHandle.classList.remove('error'); }, 1500);
            });
    }
}

class SlideCaptcha {
    constructor(options) {
        this.options = Object.assign({}, { token: '', apiUrl: '/v1/captcha' }, options);
    }
    generate(ident) {
        const formData = new FormData();
        formData.append('token', this.options.token);
        formData.append('ident', ident || 'captcha_' + Date.now() + Math.random().toString(36).substr(2, 9));
        return fetch(this.options.apiUrl + '/slide/create', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code === 0) return { success: true, data: data.data, ident: ident };
                return { success: false, message: data.echo || '生成验证码失败' };
            })
            .catch(function (err) { return { success: false, message: '网络错误: ' + err.message }; });
    }
    check(ident, x) {
        const formData = new FormData();
        formData.append('token', this.options.token);
        formData.append('ident', ident);
        formData.append('x', x);
        return fetch(this.options.apiUrl + '/slide/check', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.code === 0) return { success: true };
                return { success: false, message: data.echo || '验证失败' };
            })
            .catch(function (err) { return { success: false, message: '网络错误: ' + err.message }; });
    }
}
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
export { initSlideCaptacle, SlideCaptcha } from './slide.js';
export { initClickCaptcha } from './click.js';
