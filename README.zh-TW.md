# A.Email — 個人化電郵註冊與銷售

**🌐 線上示範：** https://1mrc1.github.io/email-address-shop/ · **語言：** [English](README.md) · [简体中文](README.zh-CN.md) · 繁體中文

> 線上示範是託管於 GitHub Pages 的**純前端**靜態版本（使用虛擬資料，無後端）——詳見 [示範](#示範)。

一個小型 PHP 網頁應用程式，用於**註冊並銷售 `username@a.email` 電郵地址**。訪客可即時查詢使用者名稱是否
可用及其價格，完成註冊後，會即時取得一個免費信箱，或透過 Stripe Checkout 付款購買。信箱隨後會透過
[mailcow](https://mailcow.email/) 伺服器的 API 開通。

> ⚠️ **早期原型／初稿。** 這只是地址分配流程的快速初版，並非經過強化的成品。在任何正式環境中執行前，
> 請先閱讀 **[狀態與已知限制](#狀態與已知限制)**。本專案僅供參考與學習而公開。

## 示範

一個**純前端**靜態示範（真實介面 + 在瀏覽器端以虛擬資料模擬 PHP 介面）已透過 GitHub Pages 發佈：
**https://1mrc1.github.io/email-address-shop/**

它沿用了真實的首頁；可用性／價格查詢與註冊請求都在瀏覽器中被攔截，因此不會儲存任何資料，也不會建立
任何信箱。可試試 `bob`（免費）、`hi`（付費）、`x`（高級）、`admin`（被封鎖）。示範原始碼位於 [`docs/`](docs/)。

## 運作原理

```
index.php ──▶ pricing.php        查詢使用者名稱是否可用 + 價格（依長度）
   │
   └─ register.php               建立使用者 + 交易紀錄
        ├─ 免費名稱 ──────▶ 立即開通信箱 ──▶ success.php
        └─ 付費名稱 ──────▶ payment.php ──▶ Stripe Checkout
                                      ├─ success.php   （驗證工作階段，開通信箱）
                                      └─ webhook.php   （checkout.session.completed → 開通信箱）
```

- **依長度定價：** 名稱越長越可能免費；越短則需付費（可設定）。
- **封鎖字詞**與對免費帳戶的**每 IP 每日上限**於伺服器端強制執行。
- **多網域：** 同一套程式碼／資料庫可服務多個郵件網域（`users` 表中的 `domain` 欄加以區分）。

## 技術堆疊

- **PHP**（7.4–8.x），無框架——每個頂層 `.php` 檔案都是可直接存取的頁面／端點。
- **MySQL**（mysqli，預備語句）。
- **Stripe Checkout**（[`stripe/stripe-php`](https://github.com/stripe/stripe-php)）用於付款 + webhook。
- **PHPMailer** 透過 SMTP 寄送交易郵件。
- **mailcow** HTTP API 用於實際建立信箱。

## 安裝

需要：PHP、Composer、MySQL、一個 Stripe 帳戶、一個 SMTP 帳戶，以及一個可存取的 mailcow API。

```bash
# 1. 相依套件
composer install

# 2. 設定——複製範本並填入真實憑證
cp config.example.php config.php
#   編輯 config.php：DB_*、STRIPE_* 金鑰、MAILBOX_API_KEY / MAILBOX_API_URL、SMTP 設定
#   config.php 已被 gitignore——切勿提交

# 3. 資料庫
mysql -u <user> -p <database> < db.sql

# 4. 執行（正式：nginx + PHP-FPM；以下為本機快速測試）
php -S localhost:8000
```

接著將 Stripe **webhook** 指向 `https://your-domain/webhook.php`，監聽 `checkout.session.completed`
事件，並將產生的簽署密鑰設為 `STRIPE_WEBHOOK_SECRET`。

### 設定參考（`config.php`）

| 設定項 | 用途 |
|---|---|
| `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` | MySQL 連線 |
| `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` / `STRIPE_WEBHOOK_SECRET` | Stripe Checkout + webhook |
| `MAILBOX_API_URL` / `MAILBOX_API_KEY` | mailcow 信箱開通 API |
| SMTP 主機／使用者／密碼（位於 `sendEmailNotification`） | 寄送交易郵件 |
| `FREE_ACCOUNT_MIN_LENGTH`、`SHORT_NAME_PRICE`、`SINGLE_CHAR_PRICE`、`DAILY_FREE_LIMIT_PER_IP` | 定價與上限 |
| `$FORBIDDEN_WORDS` | 被封鎖的使用者名稱 |

## 專案結構

```
index.php            首頁 + 用戶端 JS
pricing.php          可用性 + 定價 API
register.php         註冊 API（建立使用者，為免費使用者開通信箱）
payment.php          建立 Stripe Checkout 工作階段
webhook.php          Stripe webhook 處理
success.php/fail.php 結帳後頁面
admin.php            後台管理面板（登入、儀表板、使用者／交易管理）
manage.php           依使用者的帳戶管理頁（管理員限定；manage.php?user_id=N）
config.example.php   設定範本（複製為 config.php）
db.sql               資料庫結構（資料表、預存程序、檢視）
css/ img/            靜態資源
```

## 後台管理面板

`admin.php` 是一個以工作階段保護的後台面板（對 `admin_users` 表進行 bcrypt 驗證）。當尚無管理員時，
首次造訪會提供一次性的「建立管理員」表單。它顯示註冊／收入統計，並支援搜尋使用者、重新開通信箱、將
帳戶標記為完成／取消或刪除帳戶。正式環境請在其前面再加一層存取控制（IP 白名單／VPN／HTTP 驗證）。

> ⚠️ **尚未測試：** 後台管理面板為新增功能，**尚未**進行端到端的功能測試。

## 狀態與已知限制

這是一個原型。讀者／貢獻者應了解的已知問題：

- **`ENVIRONMENT` 預設為 `development`**，這會停用限流與 CSRF 檢查。
- **`monthly`（月費）方案目前為一次性收費**（Stripe `mode: payment`）；尚未實作真正的週期性訂閱。
- 沒有自動化測試。

## 授權

依 [MIT 授權條款](LICENSE) 發佈。
