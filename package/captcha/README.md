# @aoss/captcha

> AOSS 滑动拼图 & 点击验证码前端 SDK

---

## 安装

```bash
# npm
npm install @aoss/captcha

# yarn
yarn add @aoss/captcha

# pnpm
pnpm add @aoss/captcha
```

> 未发布到 npm 前，可本地链接：`cd package/captcha && npm link`，然后在你的项目里 `npm link @aoss/captcha`。

---

## 快速开始

### 方案 A：原生 HTML + `<script>`

```html
<link rel="stylesheet" href="/node_modules/@aoss/captcha/dist/slide.css">
<div class="captcha-container">
    <img class="captcha-bg" src="" alt="验证码背景">
    <img class="captcha-block" src="" alt="验证码块" style="display:none;">
    <div class="captcha-slider"><div class="slider-handle">👉</div></div>
</div>

<script src="/node_modules/@aoss/captcha/dist/aoss-captcha.umd.js"></script>
<script>
    AossCaptcha.initSlideCaptacle({
        token: 'your-project-token',
        onSuccess: function() { console.log('ok'); },
        onError: function(msg) { console.error(msg); }
    });
</script>
```

### 方案 B：ESM (Vite / Webpack / Rollup)

```javascript
import { initSlideCaptacle, initClickCaptcha } from '@aoss/captcha';
import '@aoss/captcha/dist/slide.css';
import '@aoss/captcha/dist/click.css';

initSlideCaptacle({
    token: 'your-project-token',
    onSuccess: () => console.log('ok'),
    onError: (msg) => console.error(msg)
});
```

### 方案 C：Vue 3

```vue
<template>
  <div class="captcha-container">
    <img class="captcha-bg" src="" alt="验证码背景">
    <img class="captcha-block" src="" alt="验证码块" style="display:none;">
    <div class="captcha-slider"><div class="slider-handle">👉</div></div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { initSlideCaptacle } from '@aoss/captcha';
import '@aoss/captcha/dist/slide.css';

onMounted(() => {
    initSlideCaptacle({
        token: 'your-project-token',
        onSuccess: () => console.log('ok'),
        onError: (msg) => console.error(msg)
    });
});
</script>
```

### 方案 D：React

```jsx
import { useEffect } from 'react';
import { initSlideCaptacle } from '@aoss/captcha';
import '@aoss/captcha/dist/slide.css';

function Captcha() {
    useEffect(() => {
        initSlideCaptacle({
            token: 'your-project-token',
            onSuccess: () => console.log('ok'),
            onError: (msg) => console.error(msg)
        });
    }, []);

    return (
        <div className="captcha-container">
            <img className="captcha-bg" src="" alt="验证码背景" />
            <img className="captcha-block" src="" alt="验证码块" style={{ display: 'none' }} />
            <div className="captcha-slider"><div className="slider-handle">👉</div></div>
        </div>
    );
}
```

---

## API

### `initSlideCaptacle(options)`

初始化滑动拼图验证码。DOM 中需存在 `.captcha-container / .captcha-bg / .captcha-block / .captcha-slider / .slider-handle`。

### `initClickCaptcha(options)`

初始化点击验证码。DOM 中需存在 `.click-captcha-container / .click-captcha-bg / .click-tip / .click-count / .click-status / .click-reload-btn`。

### 公共 Options

| 参数 | 类型 | 必填 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| `token` | string | 是 | - | 项目 token |
| `apiUrl` | string | 否 | `/v1/captcha` | 后端 API 前缀 |
| `ident` | string | 否 | 自动生成 | 用户唯一标识 |
| `onSuccess` | function | 否 | - | 验证成功回调 |
| `onError` | function(msg) | 否 | - | 验证失败/网络错误回调 |

### `SlideCaptcha` 类

仅做 API 调用，不做 DOM 绑定，适合完全自定义 UI 的场景：

```javascript
import { SlideCaptcha } from '@aoss/captcha';

const sc = new SlideCaptcha({ token: 'xxx', apiUrl: '/v1/captcha' });

// 生成
const { success, data } = await sc.generate('user-123');
// 校验
await sc.check('user-123', 150);
```

---

## 构建（作为包维护者）

```bash
cd package/captcha
npm run build        # 输出到 dist/
npm publish --access public
```

---

## License

MIT