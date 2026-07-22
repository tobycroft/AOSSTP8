# AOSS 验证码 · 前端集成文档

本目录包含 **滑动拼图验证码** 和 **点击验证码** 的前端实现。

两种集成方式任选：
- [方式一：直接在 HTML 中引入（零依赖）](#方式一直接在-html-中引入零依赖)
- [方式二：通过 npm 包集成（工程化项目推荐）](#方式二通过-npm-包集成工程化项目推荐)

---

## 组件一览

| 类型 | 演示页 | JS | CSS | 后端接口前缀 |
| --- | --- | --- | --- | --- |
| 滑动拼图 | `slide.html` | `slide.js` | `slide.css` | `/v1/captcha/slide` |
| 点击验证码 | `click.html` | `click.js` | `click.css` | `/v1/captcha/click` |

---

## 方式一：直接在 HTML 中引入（零依赖）

### 滑动拼图验证码

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="/static/captcha/slide.css">
    <style>
        body { display:flex; justify-content:center; align-items:center; height:100vh; margin:0; background:#f5f5f5; }
        .captcha-container { position:relative; width:300px; height:190px; border:1px solid #ccc; background:white; box-shadow:0 0 10px rgba(0,0,0,0.1); overflow:hidden; }
        .captcha-bg { width:300px; height:150px; display:block; position:absolute; top:0; left:0; }
        .captcha-block { position:absolute; left:0; top:0; cursor:grab; z-index:10; }
        .captcha-slider { position:absolute; bottom:0; left:0; width:100%; height:40px; background:#f5f5f5; border-top:1px solid #eee; }
        .slider-handle { width:40px; height:100%; background:#409eff; color:white; text-align:center; line-height:40px; cursor:grab; position:absolute; font-size:16px; border-radius:2px; }
    </style>
</head>
<body>
    <div class="captcha-container">
        <img class="captcha-bg" src="" alt="验证码背景">
        <img class="captcha-block" src="" alt="验证码块" style="display:none;">
        <div class="captcha-slider"><div class="slider-handle">👉</div></div>
    </div>

    <script src="/static/captcha/slide.js"></script>
    <script>
        initSlideCaptacle({
            token: 'your-project-token',
            apiUrl: '/v1/captcha',               // 可选，默认 /v1/captcha
            ident: 'user-unique-identifier',      // 可选，不传则自动生成
            onSuccess: function() {
                console.log('验证成功');
            },
            onError: function(msg) {
                console.error('验证失败:', msg);
            }
        });
    </script>
</body>
</html>
```

### 点击验证码

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="/static/captcha/click.css">
    <style>
        body { display:flex; justify-content:center; align-items:center; height:100vh; margin:0; background:#f5f5f5; }
        .click-captcha-wrapper { text-align:center; }
    </style>
</head>
<body>
    <div class="click-captcha-wrapper">
        <div class="click-captcha-container">
            <img class="click-captcha-bg" src="" alt="验证码背景">
        </div>
        <div class="click-tip"></div>
        <div class="click-count"></div>
        <div class="click-status"></div>
        <button class="click-reload-btn">刷新验证码</button>
    </div>

    <script src="/static/captcha/click.js"></script>
    <script>
        initClickCaptcha({
            token: 'your-project-token',
            apiUrl: '/v1/captcha',               // 可选，默认 /v1/captcha
            ident: 'user-unique-identifier',      // 可选，不传则自动生成
            onSuccess: function() {
                console.log('验证成功');
            },
            onError: function(msg) {
                console.error('验证失败:', msg);
            }
        });
    </script>
</body>
</html>
```

### 参数说明（公共）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `token` | string | 是 | 项目 token，用于身份识别 |
| `apiUrl` | string | 否 | 后端 API 路径前缀，默认 `/v1/captcha` |
| `ident` | string | 否 | 用户唯一标识，不传则自动随机生成 |
| `onSuccess` | function | 否 | 验证成功回调 |
| `onError` | function(msg) | 否 | 验证失败/网络错误回调 |

> ⚠️ **生产环境提示**：token 建议通过后端代理转发，不要直接暴露在前端代码中。

---

## 方式二：通过 npm 包集成（工程化项目推荐）

### 包结构（本地开发）

项目根目录下的 `package/captcha/` 已准备好一个可发布的 npm 包结构。

```
package/captcha/
├── package.json          # 包声明
├── README.md             # npm 包文档
├── index.js              # ESM 入口
├── src/
│   ├── slide.js          # 滑动验证码
│   ├── click.js          # 点击验证码
│   ├── slide.css
│   └── click.css
└── dist/                 # 构建产物（执行 build 后生成）
```

### 在工程中使用

**安装**
```bash
# 方式 A：从本地目录链接（开发调试）
npm link /path/to/AOSSTP8/package/captcha

# 方式 B：发布到 npm 后
npm install @aoss/captcha
```

**Vite / Webpack 项目**
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

**Vue 组件示例**
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
        onSuccess: () => console.log('验证成功'),
        onError: (msg) => console.error(msg)
    });
});
</script>
```

**React 组件示例**
```jsx
import { useEffect, useRef } from 'react';
import { initSlideCaptacle } from '@aoss/captcha';
import '@aoss/captcha/dist/slide.css';

function Captcha() {
    const containerRef = useRef(null);

    useEffect(() => {
        initSlideCaptacle({
            token: 'your-project-token',
            onSuccess: () => console.log('验证成功'),
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

### 构建 & 发布

```bash
cd package/captcha
npm run build          # 构建到 dist/
npm publish --access public   # 发布到 npm
```

> 注意：发布前请将 `package.json` 中的 `name` 改为你的 npm 用户名（如 `@yourname/aoss-captcha`），或直接发布私有包。

---

## 后端 API 参考

### 滑动拼图

| 接口 | 方法 | 路径 |
| --- | --- | --- |
| 创建 | POST | `/v1/captcha/slide/create` |
| 校验 | POST | `/v1/captcha/slide/check` |

**create 请求体**
```
token=xxx&ident=xxx
```

**create 返回**
```json
{
  "code": 0,
  "data": {
    "bg": "data:image/png;base64,...",
    "block": "data:image/png;base64,...",
    "y": 50,
    "bg_width": 300,
    "bg_height": 150,
    "block_size": 40,
    "pad_top": 5,
    "pad_left": 5
  }
}
```

**check 请求体**
```
token=xxx&ident=xxx&x=150
```

---

### 点击验证码

| 接口 | 方法 | 路径 |
| --- | --- | --- |
| 创建 | POST | `/v1/captcha/click/create` |
| 校验 | POST | `/v1/captcha/click/check` |

**create 返回**
```json
{
  "code": 0,
  "data": {
    "bg": "data:image/png;base64,...",
    "targets_count": 3,
    "tip": "请依次点击：A、B、C",
    "bg_width": 300,
    "bg_height": 200
  }
}
```

**check 请求体**
```
token=xxx&ident=xxx&clicks=[{"x":50,"y":80},{"x":120,"y":60},...]
```

---

## 注意事项

1. **ident 唯一**：每次调用 `create` 都应该用新的 `ident`，防止验证码被复用
2. **token 安全**：生产环境建议走后端代理，不在前端直接暴露项目 token
3. **响应式**：CSS 已内置移动端适配（`@media (max-width: 400px)`）
4. **触摸事件**：slide.js / click.js 已内置 `touchstart/touchmove/touchend` 支持