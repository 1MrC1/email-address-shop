# A.Email — 个性化邮箱注册与销售

**🌐 在线演示：** https://1mrc1.github.io/email-address-shop/ · **语言：** [English](README.md) · 简体中文 · [繁體中文](README.zh-TW.md)

> 在线演示是托管在 GitHub Pages 上的**纯前端**静态版本（使用虚拟数据，无后端）——详见 [演示](#演示)。

一个小型 PHP 网页应用，用于**注册并销售 `username@a.email` 电子邮箱地址**。访客可实时查询用户名是否可用
及其价格，完成注册后，要么立即获得一个免费邮箱，要么通过 Stripe Checkout 付款购买。邮箱随后会通过
[mailcow](https://mailcow.email/) 服务器的 API 进行开通。

> ⚠️ **早期原型／初稿。** 这只是地址分配流程的快速初版，并非经过加固的成品。在任何正式环境中运行前，
> 请先阅读 **[状态与已知限制](#状态与已知限制)**。本项目仅供参考与学习而公开。

## 演示

一个**纯前端**静态演示（真实界面 + 在浏览器端用虚拟数据模拟 PHP 接口）已通过 GitHub Pages 发布：
**https://1mrc1.github.io/email-address-shop/**

它复用了真实的着陆页；可用性／价格查询与注册请求都在浏览器中被拦截，因此不会保存任何数据，也不会创建
任何邮箱。可试试 `bob`（免费）、`hi`（付费）、`x`（高级）、`admin`（被屏蔽）。演示源码位于 [`docs/`](docs/)。

## 工作原理

```
index.php ──▶ pricing.php        查询用户名是否可用 + 价格（按长度）
   │
   └─ register.php               创建用户 + 交易记录
        ├─ 免费用户名 ──────▶ 立即开通邮箱 ──▶ success.php
        └─ 付费用户名 ──────▶ payment.php ──▶ Stripe Checkout
                                      ├─ success.php   （校验会话，开通邮箱）
                                      └─ webhook.php   （checkout.session.completed → 开通邮箱）
```

- **按长度定价：** 用户名越长越可能免费；越短则需付费（可配置）。
- **禁用词**与对免费账户的**每 IP 每日上限**在服务器端强制执行。
- **多域名：** 同一套代码／数据库可服务多个邮件域名（`users` 表中的 `domain` 列加以区分）。

## 技术栈

- **PHP**（7.4–8.x），无框架——每个顶层 `.php` 文件都是一个可直接访问的页面／接口。
- **MySQL**（mysqli，预处理语句）。
- **Stripe Checkout**（[`stripe/stripe-php`](https://github.com/stripe/stripe-php)）用于付款 + webhook。
- **PHPMailer** 通过 SMTP 发送事务性邮件。
- **mailcow** HTTP API 用于实际创建邮箱。

## 安装

需要：PHP、Composer、MySQL、一个 Stripe 账户、一个 SMTP 账户，以及一个可访问的 mailcow API。

```bash
# 1. 依赖
composer install

# 2. 配置——复制模板并填入真实凭据
cp config.example.php config.php
#   编辑 config.php：DB_*、STRIPE_* 密钥、MAILBOX_API_KEY / MAILBOX_API_URL、SMTP 设置
#   config.php 已被 gitignore——切勿提交

# 3. 数据库
mysql -u <user> -p <database> < db.sql

# 4. 运行（生产：nginx + PHP-FPM；下面是本地快速测试）
php -S localhost:8000
```

然后将 Stripe **webhook** 指向 `https://your-domain/webhook.php`，监听 `checkout.session.completed`
事件，并把生成的签名密钥设为 `STRIPE_WEBHOOK_SECRET`。

### 配置参考（`config.php`）

| 设置项 | 用途 |
|---|---|
| `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` | MySQL 连接 |
| `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` / `STRIPE_WEBHOOK_SECRET` | Stripe Checkout + webhook |
| `MAILBOX_API_URL` / `MAILBOX_API_KEY` | mailcow 邮箱开通 API |
| SMTP 主机／用户／密码（位于 `sendEmailNotification`） | 发送事务性邮件 |
| `FREE_ACCOUNT_MIN_LENGTH`、`SHORT_NAME_PRICE`、`SINGLE_CHAR_PRICE`、`DAILY_FREE_LIMIT_PER_IP` | 定价与上限 |
| `$FORBIDDEN_WORDS` | 被屏蔽的用户名 |

## 项目结构

```
index.php            着陆页 + 客户端 JS
pricing.php          可用性 + 定价接口
register.php         注册接口（创建用户，为免费用户开通邮箱）
payment.php          创建 Stripe Checkout 会话
webhook.php          Stripe webhook 处理
success.php/fail.php 结账后页面
admin.php            后台管理面板（登录、仪表盘、用户／交易管理）
manage.php           按用户的账户管理页（管理员限定；manage.php?user_id=N）
config.example.php   配置模板（复制为 config.php）
db.sql               数据库结构（表、存储过程、视图）
css/ img/            静态资源
```

## 后台管理面板

`admin.php` 是一个基于会话保护的后台面板（对 `admin_users` 表进行 bcrypt 认证）。当尚无管理员时，
首次访问会提供一次性的“创建管理员”表单。它展示注册／收入统计，并支持搜索用户、重新开通邮箱、将账户
标记为完成／取消或删除账户。正式环境请在其前面再加一层访问控制（IP 白名单／VPN／HTTP 认证）。

> ⚠️ **尚未测试：** 后台管理面板为新增功能，**尚未**进行端到端的功能测试。

## 状态与已知限制

这是一个原型。读者／贡献者应了解的已知问题：

- **`ENVIRONMENT` 默认为 `development`**，这会禁用限流与 CSRF 检查。
- **`monthly`（月付）计划目前为一次性收费**（Stripe `mode: payment`）；尚未实现真正的周期性订阅。
- 没有自动化测试。

## 许可证

基于 [MIT 许可证](LICENSE) 发布。
