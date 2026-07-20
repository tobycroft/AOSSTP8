# 滑动拼图验证码组件

## 快速开始

### 1. 直接使用演示页面

访问 `/static/captcha/slide.html` 即可查看演示效果。

### 2. 集成到自己的页面

#### HTML 结构
```html
<div class="captcha-container">
    <img class="captcha-bg" src="" alt="验证码背景">
    <img class="captcha-block" src="" alt="验证码块" style="display: none;">
    <div class="captcha-slider">
        <div class="slider-handle">👉</div>
    </div>
</div>
```

#### 引入样式和脚本
```html
<link rel="stylesheet" href="/static/captcha/slide.css">
<script src="/static/captcha/slide.js"></script>
```

#### 初始化组件
```javascript
initSlideCaptacle({
    token: 'your-project-token',
    ident: 'user-unique-identifier', // 可选，自动生成
    onSuccess: function() {
        console.log('验证成功');
        // 这里可以处理验证成功后的逻辑，比如提交表单
    },
    onError: function(msg) {
        console.error('验证失败:', msg);
    }
});
```

## API 接口

### 生成验证码
```
POST /v1/captcha/slide/create
Content-Type: multipart/form-data

参数:
- token: 项目token
- ident: 用户唯一标识（可选）

返回:
{
    "code": 0,
    "data": {
        "bg": "data:image/png;base64,...",
        "block": "data:image/png;base64,...",
        "y": 50,
        "bg_width": 300,
        "bg_height": 150,
        "block_size": 40
    }
}
```

### 验证验证码
```
POST /v1/captcha/slide/check
Content-Type: multipart/form-data

参数:
- token: 项目token
- ident: 用户唯一标识
- x: 用户拖动到的x坐标

返回:
{
    "code": 0,
    "data": null
}
```

## SDK 使用

### PHP SDK
```php
use Tobycroft\AossSdk\
Captcha;

$captcha = new Captcha("项目token");
$data = $captcha->slide("用户唯一标识");
if ($data) {
    echo $data["bg"]; // base64背景图
    echo $data["block"]; // base64拼图块
}

// 验证
$ret = $captcha->slide_check("用户唯一标识", 150);
if ($ret->isSuccess()) {
    echo "验证成功";
}
```

### Go SDK
```go
import "github.com/tobycroft/AossGoSdk"

captcha := AossGoSdk.Captcha{Token: "项目token"}
data, err := captcha.Slide("用户唯一标识")
if err != nil {
    panic(err)
}
fmt.Println(data.Bg, data.Block)

// 验证
err = captcha.SlideCheck("用户唯一标识", 150)
if err != nil {
    fmt.Println("验证失败")
}
```

## 自定义样式

可以通过修改 CSS 来自定义外观：

```css
.captcha-container {
    /* 容器样式 */
}
.captcha-block {
    /* 拼图块样式 */
}
.slider-handle {
    /* 滑块样式 */
}
```

## 注意事项

1. **token 安全**：在生产环境中，建议通过后端接口获取验证码，避免直接在前端暴露 token
2. **用户标识**：ident 应该使用用户的唯一标识（如 sessionId 或 userId），防止验证码被复用
3. **错误处理**：合理处理网络错误和验证失败的情况，提供友好的用户提示
4. **移动端支持**：组件已支持触摸事件，可在移动设备上使用