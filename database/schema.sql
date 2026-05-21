-- ============================================================
-- ANIME INFINITE — Complete Database Schema
-- MySQL 8.0+ / MariaDB 10.6+
-- UTF8MB4 / INNODB
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS `anime_infinite`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `anime_infinite`;

-- ── Users ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`                  VARCHAR(255)    NOT NULL,
  `password_hash`          VARCHAR(255)    DEFAULT NULL COMMENT 'NULL for pure SSO accounts',
  `nickname`               VARCHAR(50)     NOT NULL,
  `avatar`                 VARCHAR(500)    DEFAULT NULL,
  `birthday`               DATE            DEFAULT NULL,

  -- Role & status
  `role_level`             TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=User 12=SuperAdmin',
  `status`                 ENUM('active','banned','suspended') NOT NULL DEFAULT 'active',
  `points`                 INT UNSIGNED    NOT NULL DEFAULT 0,

  -- Game data (synced from Roblox)
  `roblox_name`            VARCHAR(50)     DEFAULT NULL,
  `roblox_gems`            INT UNSIGNED    NOT NULL DEFAULT 0,
  `roblox_level`           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `roblox_wins`            INT UNSIGNED    NOT NULL DEFAULT 0,
  `roblox_kills`           INT UNSIGNED    NOT NULL DEFAULT 0,
  `battle_pass_expires_at` DATETIME        DEFAULT NULL,

  -- SSO
  `sso_provider`           ENUM('discord','google') DEFAULT NULL,
  `sso_id`                 VARCHAR(100)    DEFAULT NULL,
  `discord_tag`            VARCHAR(100)    DEFAULT NULL,
  `google_email`           VARCHAR(255)    DEFAULT NULL,

  -- Phone / Security
  `phone`                  VARCHAR(500)    DEFAULT NULL COMMENT 'AES-256 encrypted',
  `phone_exempt`           TINYINT(1)      NOT NULL DEFAULT 0,
  `sec_question`           VARCHAR(200)    DEFAULT NULL,
  `sec_answer_hash`        VARCHAR(255)    DEFAULT NULL,

  -- Admin security
  `admin_verify_hash`      VARCHAR(255)    DEFAULT NULL COMMENT 'Layer-2 admin pass (per-user override)',
  `safety_code_hash`       VARCHAR(255)    DEFAULT NULL COMMENT 'Layer-3 safety code',
  `corporate_email`        VARCHAR(255)    DEFAULT NULL COMMENT '@anime-infinite-corp.com provisioned',

  -- Verification flags (JSON: {"email":true,"discord":true,...})
  `verifications`          JSON            DEFAULT NULL,

  -- Birthday claimed
  `birthday_claimed_year`  SMALLINT UNSIGNED DEFAULT NULL,

  -- Timestamps & meta
  `last_login_at`          DATETIME        DEFAULT NULL,
  `last_login_ip`          VARCHAR(45)     DEFAULT NULL,
  `created_at`             DATETIME        NOT NULL,
  `updated_at`             DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`             DATETIME        DEFAULT NULL COMMENT 'soft delete',

  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_email`     (`email`),
  UNIQUE KEY `ux_nickname`  (`nickname`),
  UNIQUE KEY `ux_roblox`    (`roblox_name`),
  UNIQUE KEY `ux_sso`       (`sso_provider`, `sso_id`),
  KEY `idx_role_level`      (`role_level`),
  KEY `idx_status`          (`status`),
  KEY `idx_created_at`      (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Email Verifications ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token`      CHAR(64)        NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `used`       TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Password Resets ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token`      CHAR(64)        NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `used`       TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_user` (`user_id`),
  UNIQUE KEY `ux_token` (`token`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phone OTPs ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `phone_otps` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `phone`      VARCHAR(500)    NOT NULL COMMENT 'AES encrypted',
  `otp_hash`   VARCHAR(255)    NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `used`       TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Login Logs ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `login_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`         VARCHAR(45)     DEFAULT NULL,
  `user_agent` VARCHAR(500)    DEFAULT NULL,
  `status`     ENUM('success','failed') NOT NULL DEFAULT 'success',
  PRIMARY KEY (`id`),
  KEY `idx_user_id`  (`user_id`),
  KEY `idx_login_at` (`login_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── User Titles ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_titles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `title_key`   VARCHAR(100)    NOT NULL,
  `is_equipped` TINYINT(1)      NOT NULL DEFAULT 0,
  `obtained_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_user_title` (`user_id`, `title_key`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Store Products ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `store_products` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `category`            ENUM('points','pass','title','bundle') NOT NULL DEFAULT 'points',
  `name`                VARCHAR(100)    NOT NULL,
  `sub_title`           VARCHAR(100)    DEFAULT NULL,
  `description`         TEXT            DEFAULT NULL,
  `icon`                VARCHAR(10)     DEFAULT '💰',
  `price_twd`           SMALLINT UNSIGNED NOT NULL,
  `original_price_twd`  SMALLINT UNSIGNED DEFAULT NULL,
  `discount_pct`        TINYINT UNSIGNED  DEFAULT 0,
  `reward_json`         JSON            NOT NULL COMMENT '{"points":500,"battle_pass_days":30,...}',
  `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
  `is_popular`          TINYINT(1)      NOT NULL DEFAULT 0,
  `is_recommended`      TINYINT(1)      NOT NULL DEFAULT 0,
  `stock`               INT             DEFAULT NULL COMMENT 'NULL = unlimited',
  `sort_order`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category`   (`category`),
  KEY `idx_is_active`  (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Orders ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `orders` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`          VARCHAR(30)     NOT NULL,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `product_id`        INT UNSIGNED    NOT NULL,
  `product_name`      VARCHAR(100)    NOT NULL,
  `amount_twd`        SMALLINT UNSIGNED NOT NULL,
  `pay_method`        VARCHAR(20)     NOT NULL DEFAULT 'credit',
  `status`            ENUM('pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
  `gateway_trade_no`  VARCHAR(50)     DEFAULT NULL,
  `paid_at`           DATETIME        DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_order_no`  (`order_no`),
  KEY `idx_user_id`         (`user_id`),
  KEY `idx_status`          (`status`),
  KEY `idx_created_at`      (`created_at`),
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`product_id`) REFERENCES `store_products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Transactions ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `tx_type`      ENUM('topup','redeem','reward','refund','admin_adjust') NOT NULL,
  `description`  VARCHAR(200)    NOT NULL,
  `points_delta` INT             NOT NULL DEFAULT 0,
  `ref_id`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'order_id or code_id',
  `status`       ENUM('success','failed','pending') NOT NULL DEFAULT 'success',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_tx_type`    (`tx_type`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Redeem Codes ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `redeem_codes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        CHAR(10)        NOT NULL,
  `reward_json` JSON            NOT NULL,
  `expires_at`  DATETIME        DEFAULT NULL,
  `max_uses`    INT UNSIGNED    NOT NULL DEFAULT 1,
  `used_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_by`  BIGINT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_code` (`code`),
  KEY `idx_is_active` (`is_active`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Redeem Usages ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `redeem_usages` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`  BIGINT UNSIGNED NOT NULL,
  `ip`       VARCHAR(45)     DEFAULT NULL,
  `used_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_code_user` (`code_id`, `user_id`),
  FOREIGN KEY (`code_id`) REFERENCES `redeem_codes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Redeem Attempt Logs ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `redeem_attempt_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED DEFAULT NULL,
  `code`         VARCHAR(10)     NOT NULL,
  `result`       VARCHAR(20)     NOT NULL,
  `ip`           VARCHAR(45)     DEFAULT NULL,
  `attempted_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`      (`user_id`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Task Completions ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `task_completions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `task_key`    VARCHAR(50)     NOT NULL,
  `task_group`  ENUM('daily','weekly','event') NOT NULL DEFAULT 'daily',
  `period_key`  VARCHAR(10)     NOT NULL COMMENT 'YYYY-MM-DD or YYYY-WW',
  `reward`      INT             NOT NULL DEFAULT 0,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_task` (`user_id`,`task_key`,`period_key`,`task_group`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notifications ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_type`     ENUM('all','single','role_min','vip') NOT NULL DEFAULT 'all',
  `target_uid`      BIGINT UNSIGNED DEFAULT NULL,
  `target_role_min` TINYINT UNSIGNED DEFAULT NULL,
  `title`           VARCHAR(200)    NOT NULL,
  `content`         TEXT            NOT NULL,
  `notif_type`      ENUM('general','promotion','warning','reward','system') NOT NULL DEFAULT 'general',
  `sender_id`       BIGINT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target_uid`  (`target_uid`),
  KEY `idx_created_at`  (`created_at`),
  FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notification Reads ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_reads` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `read_at`         DATETIME        DEFAULT NULL,
  `reply_content`   TEXT            DEFAULT NULL,
  `replied_at`      DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_notif_user` (`notification_id`, `user_id`),
  KEY `idx_user_id` (`user_id`),
  FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)         REFERENCES `users`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tickets ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tickets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no`   VARCHAR(10)     NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `subject`     VARCHAR(200)    NOT NULL,
  `content`     TEXT            NOT NULL,
  `status`      ENUM('open','inprogress','resolved','closed') NOT NULL DEFAULT 'open',
  `priority`    ENUM('urgent','high','normal','low') NOT NULL DEFAULT 'normal',
  `category`    VARCHAR(50)     DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_ticket_no` (`ticket_no`),
  KEY `idx_user_id`  (`user_id`),
  KEY `idx_status`   (`status`),
  KEY `idx_priority` (`priority`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ticket Replies ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ticket_replies` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `content`    TEXT            NOT NULL,
  `is_staff`   TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Game Configs ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `game_configs` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_key`   VARCHAR(100) NOT NULL,
  `config_value` VARCHAR(500) NOT NULL,
  `description`  VARCHAR(300) DEFAULT NULL,
  `updated_by`   BIGINT UNSIGNED DEFAULT NULL,
  `updated_at`   DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_key` (`config_key`),
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── News / Articles ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `articles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author_id`   BIGINT UNSIGNED NOT NULL,
  `category`    ENUM('update','event','guide','patch') NOT NULL DEFAULT 'update',
  `title`       VARCHAR(200)    NOT NULL,
  `content`     LONGTEXT        NOT NULL,
  `cover_emoji` VARCHAR(10)     DEFAULT '📰',
  `status`      ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `views`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `likes`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `shares`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `published_at`DATETIME        DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_author_id`   (`author_id`),
  KEY `idx_category`    (`category`),
  KEY `idx_status`      (`status`),
  KEY `idx_published_at`(`published_at`),
  FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Article Likes ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `article_likes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_article_user` (`article_id`, `user_id`),
  FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Article Comments ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `article_comments` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `content`    VARCHAR(1000)   NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_article_id` (`article_id`),
  FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Audit Logs ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`   BIGINT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100)    NOT NULL,
  `context`    JSON            DEFAULT NULL,
  `ip`         VARCHAR(45)     DEFAULT NULL,
  `ua`         VARCHAR(500)    DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor_id`   (`actor_id`),
  KEY `idx_action`     (`action`),
  KEY `idx_created_at` (`created_at`)
  -- NOTE: No FK on actor_id intentionally (allow log even if user deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Read-only audit trail — do NOT add UPDATE/DELETE permissions to app user';

-- ══════════════════════════════════════════════════════════════
-- SEED DATA
-- ══════════════════════════════════════════════════════════════

-- Default game configs
INSERT IGNORE INTO `game_configs` (`config_key`, `config_value`, `description`) VALUES
('exp_multiplier',     '1.5',    '經驗值倍率'),
('drop_rate_bonus',    '100',    '掉落率加成 (%)'),
('max_players_per_room','50',    '每局最大玩家數'),
('pvp_enabled',        '1',      'PvP 模式啟用'),
('exchange_rate',      '10',     '1 TWD = N P'),
('daily_login_bonus',  '10',     '每日登入獎勵 (P)'),
('birthday_bonus',     '500',    '生日禮包點數'),
('shop_enabled',       '1',      '商城功能啟用'),
('season_number',      '3',      '當前賽季'),
('season_end_date',    '2024-02-15', '賽季結束日期');

-- Default store products
INSERT IGNORE INTO `store_products`
  (`category`, `name`, `sub_title`, `description`, `icon`, `price_twd`, `original_price_twd`, `discount_pct`, `reward_json`, `is_active`, `is_popular`, `is_recommended`, `sort_order`)
VALUES
  ('points','入門點數包','200 P',NULL,'💰',19,NULL,0,'{"points":200}',1,0,0,1),
  ('points','標準點數包','500 P',NULL,'💎',49,NULL,0,'{"points":500}',1,0,0,2),
  ('points','超值點數包','1100 P','限時優惠','⭐',99,109,9,'{"points":1100}',1,1,0,3),
  ('points','大量點數包','2800 P',NULL,'🔥',199,219,9,'{"points":2800}',1,0,0,4),
  ('points','土豪點數包','7000 P',NULL,'👑',499,549,9,'{"points":7000}',1,0,0,5),
  ('pass','Battle Pass S3','60天通行證',NULL,'🎫',199,NULL,0,'{"battle_pass_days":60}',1,0,1,6),
  ('title','稱號：黑夜領主','永久稱號',NULL,'🌙',149,NULL,0,'{"title":"night_lord"}',1,0,0,7),
  ('title','稱號：雷霆戰神','限量 500 份',NULL,'⚡',299,NULL,0,'{"title":"thunder_god"}',1,0,0,8),
  ('bundle','新手大禮包','超值組合',NULL,'🎁',129,199,35,'{"points":500,"battle_pass_days":30}',1,1,0,9);

-- Demo Super Admin account
-- Password: Admin@2024! (CHANGE IN PRODUCTION)
INSERT IGNORE INTO `users`
  (`id`, `email`, `password_hash`, `nickname`, `role_level`, `status`, `verifications`, `created_at`)
VALUES (
  1,
  'superadmin@anime-infinite-corp.com',
  '$argon2id$v=19$m=65536,t=4,p=1$PLACEHOLDER_HASH_CHANGE_IN_PROD',
  'SuperAdmin',
  12,
  'active',
  '{"email":true,"discord":true,"google":true,"roblox":true,"phone":true}',
  NOW()
);

SET FOREIGN_KEY_CHECKS = 1;

-- ══════════════════════════════════════════════════════════════
-- RECOMMENDED MYSQL USER PERMISSIONS
-- ══════════════════════════════════════════════════════════════
-- CREATE USER 'ai_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON anime_infinite.* TO 'ai_user'@'localhost';
-- GRANT SELECT ON anime_infinite.audit_logs TO 'ai_user'@'localhost';
-- -- Do NOT grant DELETE on audit_logs
-- FLUSH PRIVILEGES;
