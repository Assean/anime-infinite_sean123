# Anime Infinite 整合遊戲平台
## 全端部署指南 & 技術文件

---

## 📁 專案結構

```
anime-infinite/
├── index.html                  # 首頁
├── auth.html                   # 登入 / 註冊
├── store.html                  # 儲值商城
├── news.html                   # 資訊佈告欄
├── .htaccess                   # Apache 路由 & 安全設定
│
├── dashboard/
│   └── index.html              # 玩家會員中心
│
├── admin/
│   ├── index.html              # 管理後台主頁
│   └── login.html              # 三層式管理員登入
│
├── maintenance/
│   └── index.html              # 維護模式頁面
│
├── assets/
│   ├── css/main.css            # 全站樣式（Dark Anime Cyberpunk）
│   └── js/main.js              # 全站 JS（Auth、Toast、Modal、i18n）
│
├── api/
│   ├── auth.php                # 登入、註冊、SSO、JWT
│   ├── user.php                # 玩家資料、綁定、OTP、通知
│   ├── store.php               # 商品、訂單、金流 Webhook
│   ├── redeem.php              # 兌換碼驗證與使用
│   ├── news.php                # 文章 CRUD、留言、按讚
│   └── admin.php               # 後台全功能管理 API
│
├── config/
│   ├── config.php              # 環境設定、JWT、加密、Rate Limit
│   └── database.php            # PDO 單例連線管理
│
├── cron/
│   └── cron_jobs.php           # 排程任務（生日禮包、備份、清理）
│
└── database/
    └── schema.sql              # 完整 MySQL Schema + Seed Data
```

---

## 🚀 部署步驟

### 1. 環境需求

| 項目 | 版本 |
|------|------|
| PHP  | 8.1+ |
| MySQL | 8.0+ 或 MariaDB 10.6+ |
| Apache | 2.4+ (`mod_rewrite`, `mod_headers`, `mod_deflate`) |
| PHP Extensions | `pdo_mysql`, `openssl`, `curl`, `json`, `mbstring`, `fileinfo` |

---

### 2. 推薦免費託管平台

#### 🥇 Oracle Cloud Free Tier（首選）
```bash
# 1. 申請 Oracle Cloud 帳號（永久免費 ARM VM）
# 2. 建立 Ubuntu 22.04 Instance（2 OCPU + 12GB RAM）
# 3. 安裝 LAMP 環境

sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 mysql-server php8.2 php8.2-pdo \
  php8.2-mysql php8.2-curl php8.2-mbstring php8.2-json \
  php8.2-fileinfo php8.2-zip libapache2-mod-php8.2

sudo a2enmod rewrite headers deflate expires
sudo systemctl restart apache2
```

#### 🥈 InfinityFree（備選）
- 免費 PHP/MySQL 主機，無廣告
- 限制：500MB 空間，不支援 exec()（備份需另外處理）

---

### 3. 資料庫設定

```sql
-- 建立資料庫與使用者
CREATE DATABASE anime_infinite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ai_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';

-- 最小權限原則
GRANT SELECT, INSERT, UPDATE, DELETE ON anime_infinite.* TO 'ai_user'@'localhost';
-- 稽核日誌：唯讀防竄改
REVOKE DELETE ON anime_infinite.audit_logs FROM 'ai_user'@'localhost';

FLUSH PRIVILEGES;

-- 匯入 Schema
mysql -u root -p anime_infinite < database/schema.sql
```

---

### 4. 環境變數設定

複製 `.env.example` 為 `.env` 並填入真實值：

```bash
cp .env.example .env
nano .env
```

```env
# ── App ───────────────────────────────────────────────────
APP_ENV=production
APP_URL=https://your-domain.com

# ── Database ──────────────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=anime_infinite
DB_USER=ai_user
DB_PASS=STRONG_PASSWORD_HERE

# ── Security（請使用 openssl rand -hex 32 生成）────────────
JWT_SECRET=CHANGE_THIS_32_CHAR_SECRET_KEY_HERE
AES_KEY=CHANGE_THIS_32_BYTE_AES_KEY_HERE!
AES_IV=16BYTESIVHERE123

# ── Roblox Open Cloud ─────────────────────────────────────
ROBLOX_API_KEY=your_roblox_api_key
ROBLOX_UNIVERSE_ID=your_universe_id
ROBLOX_PLACE_ID=your_place_id

# ── Discord ───────────────────────────────────────────────
DISCORD_BOT_TOKEN=your_bot_token
DISCORD_CLIENT_ID=your_client_id
DISCORD_CLIENT_SECRET=your_client_secret
DISCORD_GUILD_ID=your_guild_id
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/xxx/yyy

# ── Google OAuth ──────────────────────────────────────────
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret

# ── Payment（綠界 ECPay 或其他）──────────────────────────
PAYMENT_MERCHANT_ID=your_merchant_id
PAYMENT_HASH_KEY=your_hash_key
PAYMENT_HASH_IV=your_hash_iv
PAYMENT_WEBHOOK_SECRET=your_webhook_secret

# ── Email SMTP ────────────────────────────────────────────
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your@gmail.com
SMTP_PASS=your_app_password
SMTP_FROM=noreply@your-domain.com

# ── SMS ───────────────────────────────────────────────────
SMS_API_KEY=your_sms_api_key
```

---

### 5. Apache VirtualHost 設定

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /var/www/anime-infinite

    <Directory /var/www/anime-infinite>
        Options -Indexes -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    # 禁止直接訪問 config / database 目錄
    <Directory /var/www/anime-infinite/config>
        Require all denied
    </Directory>

    <Directory /var/www/anime-infinite/database>
        Require all denied
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/ai_error.log
    CustomLog ${APACHE_LOG_DIR}/ai_access.log combined
</VirtualHost>
```

```bash
# 啟用 SSL（Let's Encrypt）
sudo apt install certbot python3-certbot-apache -y
sudo certbot --apache -d your-domain.com -d www.your-domain.com
```

---

### 6. Cron Job 設定

```bash
# 編輯 crontab
crontab -e

# 加入以下排程
# 每日 00:00 — 生日禮包發放
0 0 * * * php /var/www/anime-infinite/cron/cron_jobs.php birthday >> /var/log/ai_cron.log 2>&1

# 每日 03:00 — 資料庫備份
0 3 * * * php /var/www/anime-infinite/cron/cron_jobs.php backup >> /var/log/ai_cron.log 2>&1

# 每日 00:00 — 每日登入獎勵派發
0 0 * * * php /var/www/anime-infinite/cron/cron_jobs.php daily_reset >> /var/log/ai_cron.log 2>&1

# 每週一 00:00 — 週常任務重置
0 0 * * 1 php /var/www/anime-infinite/cron/cron_jobs.php weekly_reset >> /var/log/ai_cron.log 2>&1

# 每 5 分鐘 — Roblox 資料同步
*/5 * * * * php /var/www/anime-infinite/cron/cron_jobs.php roblox_sync >> /var/log/ai_cron.log 2>&1

# 每日 01:00 — 系統清理
0 1 * * * php /var/www/anime-infinite/cron/cron_jobs.php cleanup >> /var/log/ai_cron.log 2>&1
```

---

### 7. 雲端備份設定（rclone + Google Drive）

```bash
# 安裝 rclone
curl https://rclone.org/install.sh | sudo bash

# 設定 Google Drive
rclone config
# 選擇 n (new remote) → 名稱輸入 gdrive → 選 drive → 完成 OAuth 授權

# 測試
rclone ls gdrive:
```

---

### 8. 首次使用 — 建立超級管理員

```sql
-- 替換 ARGON2ID Hash（PHP 生成：password_hash('your_password', PASSWORD_ARGON2ID)）
UPDATE users
SET password_hash = 'YOUR_ARGON2ID_HASH',
    admin_verify_hash = 'YOUR_VERIFY_PASS_HASH',
    safety_code_hash = 'YOUR_SAFETY_CODE_HASH'
WHERE id = 1;
```

```bash
# 快速生成 Hash
php -r "echo password_hash('Admin@2024!', PASSWORD_ARGON2ID);"
```

---

## 🔐 安全性架構

### OWASP Top 10 防護措施

| 威脅 | 防護 |
|------|------|
| SQL Injection | PDO 預備語句（所有查詢） |
| XSS | `escHtml()` + CSP Header |
| CSRF | SameSite Cookie + Referer 驗證 |
| Auth 弱點 | JWT + Argon2id + 三層管理員驗證 |
| 敏感資料暴露 | AES-256-CBC 加密手機號碼 |
| 速率限制 | APCu / 檔案型 Rate Limiter |
| 設定錯誤 | `.htaccess` 封鎖敏感目錄 |
| 稽核日誌 | `audit_logs` 唯讀 DB 紀錄 |

---

## 🎮 Roblox 整合說明

### 遊戲內 Luau Script（概念）

```lua
-- BindCode.lua（Server Script）
local DataStoreService = game:GetService("DataStoreService")
local bindDS = DataStoreService:GetDataStore("BindCodes")

game.Players.PlayerAdded:Connect(function(player)
    -- /bind 指令處理
    player.Chatted:Connect(function(msg)
        if msg:lower() == "/bind" then
            local code = generateCode(6)  -- 6位數
            bindDS:SetAsync("bind_" .. player.Name, {
                code = code, expires = os.time() + 300
            })
            -- 顯示 code 給玩家
        end
    end)
end)

-- RewardQueue 監聽（從 Web 收取獎勵）
local MessagingService = game:GetService("MessagingService")
MessagingService:SubscribeAsync("RewardQueue", function(msg)
    local data = game:GetService("HttpService"):JSONDecode(msg.Data)
    local player = game.Players:FindFirstChild(data.roblox_name)
    if player then
        -- 發放 gems / points 等
    end
end)
```

---

## 📊 資料庫設計概覽

```
users (主表)
  ├── email_verifications
  ├── password_resets
  ├── phone_otps
  ├── login_logs
  ├── user_titles
  ├── task_completions
  ├── article_likes
  ├── article_comments
  ├── notification_reads (← notifications)
  ├── redeem_usages      (← redeem_codes)
  └── orders             (← store_products)
       └── transactions

audit_logs        (唯讀稽核)
game_configs      (遊戲參數)
articles          (資訊佈告)
tickets           (客服工單)
  └── ticket_replies
notifications
```

---

## 🔑 API 端點總覽

| 端點 | 方法 | 說明 |
|------|------|------|
| `/api/auth` | POST | login, register, logout, sso, admin_login |
| `/api/user` | POST | profile, assets, tasks, bindings, OTP |
| `/api/store` | POST | products, create_order, payment_webhook |
| `/api/redeem` | POST | redeem, check |
| `/api/news` | GET/POST | list, get, like, comment, create |
| `/api/admin` | POST | dashboard, players, RBAC, codes, tickets... |

---

## 📞 技術支援

- Email：support@anime-infinite.com
- Discord：https://discord.gg/anime-infinite
- 文件版本：v1.0.0
- 最後更新：2024-01-15

---

*© 2024 Anime Infinite. Built with ❤️ using PHP · MySQL · JavaScript*
