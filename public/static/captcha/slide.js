/**
 * 滑动拼图验证码组件
 * 使用示例:
 * initSlideCaptacle({
 *     token: 'your-project-token',
 *     ident: 'user-unique-id', // 可选，自动生成
 *     onSuccess: function() {},
 *     onError: function(msg) {}
 * });
 */

function initSlideCaptacle(options) {
    const defaults = {
        token: '',
        ident: 'captcha_' + Date.now() + Math.random().toString(36).substr(2, 9),
        apiUrl: '/v1/captcha',
        onSuccess: function() {},
        onError: function(msg) {}
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
    let isDragging = false;
    let startX = 0;
    let startLeft = 0;
    let isVerified = false; // 是否已验证（无论成功或失败）

    // 生成验证码
    generateCaptcha();

    // 事件监听
    sliderHandle.addEventListener('mousedown', startDrag);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', endDrag);
    sliderHandle.addEventListener('touchstart', startDrag);
    document.addEventListener('touchmove', drag);
    document.addEventListener('touchend', endDrag);

    function generateCaptcha() {
        const formData = new FormData();
        formData.append('token', opts.token);
        formData.append('ident', opts.ident);

        fetch(opts.apiUrl + '/slide/create', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.code === 0) {
                captchaData = data.data;
                bgImg.src = captchaData.bg;
                blockImg.src = captchaData.block;
                blockImg.style.top = captchaData.y + 'px';
                blockImg.style.left = '0px';
                blockImg.style.display = 'block';
                sliderHandle.style.left = '0px';
                reloadBtn.style.display = 'inline-block';
                isVerified = false; // 新验证码，重置验证状态
            } else {
                opts.onError(data.echo || '生成验证码失败');
            }
        })
        .catch(error => {
            opts.onError('网络错误: ' + error.message);
        });
    }

    function startDrag(e) {
        if (isVerified) return; // 已验证过，不允许再次操作
        isDragging = true;
        startX = e.clientX || e.touches[0].clientX;
        startLeft = parseInt(sliderHandle.style.left) || 0;
        sliderHandle.style.background = '#66b1ff';
    }

    function drag(e) {
        if (!isDragging || !captchaData) return;

        const clientX = e.clientX || e.touches[0].clientX;
        const deltaX = clientX - startX;
        const newLeft = Math.max(0, Math.min(startLeft + deltaX, captchaData.bg_width - captchaData.block_size));

        sliderHandle.style.left = newLeft + 'px';
        blockImg.style.left = newLeft + 'px';
    }

    function endDrag(e) {
        if (!isDragging || !captchaData) return;

        isDragging = false;
        sliderHandle.style.background = '#409eff';

        const finalX = parseInt(blockImg.style.left) || 0;

        const formData = new FormData();
        formData.append('token', opts.token);
        formData.append('ident', opts.ident);
        formData.append('x', finalX);

        fetch(opts.apiUrl + '/slide/check', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.code === 0) {
                sliderHandle.classList.add('success');
                opts.onSuccess();
                blockImg.style.pointerEvents = 'none';
                sliderHandle.style.pointerEvents = 'none';
            } else {
                isVerified = true; // 标记为已验证，禁止继续操作
                sliderHandle.classList.add('error');
                blockImg.style.pointerEvents = 'none'; // 禁用拼图块
                sliderHandle.style.pointerEvents = 'none'; // 禁用滑块
                opts.onError(data.echo || '验证失败');
                
                // 验证失败，自动重新加载新的验证码
                setTimeout(() => {
                    generateCaptcha();
                    sliderHandle.classList.remove('error');
                    blockImg.style.pointerEvents = 'auto'; // 恢复拼图块
                    sliderHandle.style.pointerEvents = 'auto'; // 恢复滑块
                }, 1500);
            }
        })
        .catch(error => {
            sliderHandle.classList.add('error');
            opts.onError('网络错误: ' + error.message);
            resetPosition();
            setTimeout(() => {
                sliderHandle.classList.remove('error');
            }, 1500);
        });
    }

    function resetPosition() {
        blockImg.style.left = '0px';
        sliderHandle.style.left = '0px';
    }
}

/**
 * 滑动验证码工具类
 * 提供更灵活的调用方式
 */
class SlideCaptcha {
    constructor(options) {
        this.options = Object.assign({}, {
            token: '',
            apiUrl: '/v1/captcha'
        }, options);
    }

    generate(ident) {
        const formData = new FormData();
        formData.append('token', this.options.token);
        formData.append('ident', ident || 'captcha_' + Date.now() + Math.random().toString(36).substr(2, 9));

        return fetch(this.options.apiUrl + '/slide/create', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.code === 0) {
                return { success: true, data: data.data, ident: ident };
            } else {
                return { success: false, message: data.echo || '生成验证码失败' };
            }
        })
        .catch(error => {
            return { success: false, message: '网络错误: ' + error.message };
        });
    }

    check(ident, x) {
        const formData = new FormData();
        formData.append('token', this.options.token);
        formData.append('ident', ident);
        formData.append('x', x);

        return fetch(this.options.apiUrl + '/slide/check', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.code === 0) {
                return { success: true };
            } else {
                return { success: false, message: data.echo || '验证失败' };
            }
        })
        .catch(error => {
            return { success: false, message: '网络错误: ' + error.message };
        });
    }
}