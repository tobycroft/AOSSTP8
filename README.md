# AOSSTP-PHP8+

## 项目&个人看法

AOSSTP8是AOSSTP的前作

解决

- 插件在PHP版本升级后可能出现需要重接的风险
- 跨语言中转站
- 降低很不经常使用的功能重复看文档导致心态爆炸的风险
- 短时间内拥有常用能力
- 更方便的切换业务底层
- 作为新功能的测试平台
- 使用composer和gopkg互补

### SDK

- AossSdk PHP版本 (https://github.com/tobycroft/AossSdk)
    - composer require tobycroft/aoss-sdk
- AossGoSdk Golang版本 (https://github.com/tobycroft/AossGoSdk)
    - go get github.com/tobycroft/AossGoSdk

### AOSS8功能表

- Office能力
    - Excel
        - Excel解析JSON
        - JSON生成Excel
    - Word
        - 文字导入
        - 文字生成
    - PDF
        - 图文能力生成
- 文件上传
    - 上传到本地
    - OSS
        - 阿里云
        - 腾讯云
    - 上传到OSS并在本地保存（双地址）
    - 音视频识别
        - 解析音频时间长度
        - 解析视频时间长度
    - 文件识别
        - 避免重复文件
        - 文件秒传
- 微信能力
    - WXA-QR微信二维码生成
        - 自动地址（同上）
        - Base64文件返回
        - 302地址（部分小程序插件无法正常使用302）
    - SNS
        - 小程序登录
        - 公众号登录
    - 公众号
        - 用户列表
        - 获取某个用户信息
        - 公众号模板文章推送
        - 获取openid（登录）
        - 生成二维码
    - 签名鉴权（公众号）
    - 被动接收消息
        - 接收公众号用户消息（需在微信配置服务器功能）
        - 回复公众号用户消息（需在微信配置服务器功能）
            - 自动回复库
            - 关键词触发库
            - 欢迎消息
- 图形能力
    - 画图
        - 返回图片binary流
        - 返回图片下载地址
            - 自动上传OSS
            - 仅保留本地
    - 条形码
        - 条形码生成
    - 二维码
        - 生成图片流
        - 生成带LOGO的图片流
        - 生成B64
- 短信能力（ASMS）
    - 发送短信
    - 防火墙
        - 号码防火墙
        - IP防火墙
        - 业务联动防火墙（防刷）
        - 图形验证码/语音验证码
- Hook（WebHook分发平台）
    - 普通直推模式
    - Github模式
        - 单推
        - 多推
        - Branch推送模式
- Tasker
    - 自动测试工具
- LCIC（腾讯云低代码直播课堂）
    - 用户
        - 全自动模式
        - 手动新建
        - 手动修改
    - 创建直播间
        - 全自动模式
        - 修改直播
        - 生成直播推广码
- 普通直播
    - 阿里云
    - 腾讯云
        - 创建直播
            - 返回基本信息（鉴权直播）
            - 返回全部信息（鉴权直播）
        - 读取直播地址（鉴权播放）
- AI-GPT能力
    - 接入Google-Bard
        - 通过token设定某个项目使用某个训练好的模型回复
    - 接入Bing-AI
        - 通过token设定某个项目使用某个训练好的模型回复
- IP安全
    - IP地区范围查询
        - 仅IP验证模式
        - IP+验证码交互验证模式
- 验证码
    - 图形码（普通码）
        - 计算型验证码（22+5=?）
        - 中文验证码
        - 数字验证码
        - 字母验证码
        - 混合验证码
    - 智力码（图形选择）
        - 通用单验证
        - 自动威胁匹配
- 游戏支持
    - RCON
        - Palworld幻兽帕鲁
- 运维支持（独立鉴权）
    - 证书管理（Let's Encrypt）
        - 宝塔面板/AAPanel-API
            - 网站证书
                - 自动添加已配置SSL证书的网站到自动更新数据库
                - 自动更新通配符证书
            - 邮件系统
                - 自动添加已配置的SSL证书到数据库
                - 自动更新通配符证书