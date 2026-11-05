-- ============================================================
-- 战队入驻平台 + 游戏战队系统 数据库初始化脚本 v5（全新安装）
-- 架构: 目录制多租户 —— 主域名根路径为入驻平台，/{slug}/ 目录为各战队系统
-- 适配: MySQL 5.7.43 / utf8mb4 / InnoDB
-- 用法: mysql -u root -p < game_team.sql
-- 宝塔/phpMyAdmin 用户请改用 game_team_phpmyadmin.sql
-- 说明: v5 与 v4 结构不兼容（v4 为二级域名方案，已作废），无升级脚本，全新安装
-- ============================================================
CREATE DATABASE IF NOT EXISTS `game_team`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE `game_team`;
-- ------------------------------------------------------------
-- 平台账号表（v5.0.0：入驻平台独立登录体系）
--   role=admin 内置平台超管（审核入驻/管理租户）
--   role=team  入驻战队账号（注册登录、提交入驻、管理自己的站点）
-- 注意：与战队系统 users 表完全分离，互不影响
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `platform_users`;
CREATE TABLE `platform_users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(32)  NOT NULL                COMMENT '平台登录名(3-20位字母数字下划线)',
  `password`   VARCHAR(255) NOT NULL                COMMENT '密码哈希(bcrypt)',
  `nickname`   VARCHAR(32)  NOT NULL DEFAULT ''     COMMENT '昵称/战队称呼',
  `role`       VARCHAR(16)  NOT NULL DEFAULT 'team' COMMENT 'admin平台超管 team入驻战队',
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'normal' COMMENT 'normal正常 disabled停用',
  `reg_ip`     VARCHAR(45)  NOT NULL DEFAULT ''     COMMENT '注册IP',
  `login_alert_seen_at` DATETIME    DEFAULT NULL   COMMENT '登录安全提醒已读游标(v5.17.0)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='入驻平台账号表';
-- 内置平台超管: admin / admin123  (登录后请立即修改密码)
INSERT INTO `platform_users` (`username`, `password`, `nickname`, `role`, `status`, `created_at`, `updated_at`)
VALUES ('admin', '$2y$10$j/Kfqxd5qKY0C5h//t5c8Og.G6AYXZcOxsVpjMtzh/OY/cbIzpP.a', '平台管理员', 'admin', 'normal', NOW(), NOW());
-- ------------------------------------------------------------
-- 租户表（v5.0.0：入驻战队，一个租户 = 主域名下一个目录站点 /{slug}/）
-- 审核通过才会建行；status: approved正常 banned停用
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenants`;
CREATE TABLE `tenants` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_id`  INT UNSIGNED NOT NULL              COMMENT '申请人(平台账号ID)',
  `slug`          VARCHAR(30)  NOT NULL              COMMENT '目录名(小写字母数字连字符)',
  `name`          VARCHAR(64)  NOT NULL              COMMENT '战队名称',
  `logo`          VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '战队logo(存储相对路径,可选)',
  `description`   VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '战队简介',
  `admin_username` VARCHAR(32) NOT NULL DEFAULT ''   COMMENT '战队系统超管用户名快照',
  `admin_user_id` INT UNSIGNED          DEFAULT NULL COMMENT '战队系统超管用户ID(users表)',
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'approved' COMMENT 'approved正常 banned封禁paused战队主暂停transferring转移冷静期',
  `banned_at`     DATETIME              DEFAULT NULL COMMENT '最后一次被封禁的时间(v5.36.32,恢复即置空;封满15天平台超管才可销毁)',
  `storage_quota` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '存储配额字节数(v5.1.0,0=默认2GB)',
  `created_at`    DATETIME              DEFAULT NULL,
  `updated_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_applicant` (`applicant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户表(入驻战队)';
-- ------------------------------------------------------------
-- 战队转移单（v5.1.0：双方同意后 7 天冷静期，期间站点不可访问）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenant_transfers`;
CREATE TABLE `tenant_transfers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL              COMMENT '被转移的租户',
  `from_uid`      INT UNSIGNED NOT NULL              COMMENT '发起方(平台账号ID)',
  `to_uid`        INT UNSIGNED NOT NULL              COMMENT '接收方(平台账号ID)',
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待确认cooling冷静期completed已交接rejected已拒绝cancelled已撤回',
  `cooling_until` DATETIME              DEFAULT NULL COMMENT '冷静期截止(接受后+7天)',
  `created_at`    DATETIME              DEFAULT NULL,
  `updated_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_to` (`to_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='战队转移单(v5.1.0)';
-- ------------------------------------------------------------
-- 平台登录日志（v5.1.0：平台账号可查本人最近登录记录）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `platform_login_logs`;
CREATE TABLE `platform_login_logs` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平台账号ID(账号不存在时为0)',
  `username`         VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '登录名快照',
  `ip`               VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '登录IP',
  `region`           VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'IP归属地',
  `result`           TINYINT      NOT NULL DEFAULT 0  COMMENT '1成功0失败',
  `via`              VARCHAR(12)  NOT NULL DEFAULT 'pwd' COMMENT 'pwd密码 login/cookie免登录恢复(v5.17.0)',
  `flagged`          TINYINT      NOT NULL DEFAULT 0  COMMENT '1=新归属地登录，需提醒本人(v5.17.0)',
  `created_at`       DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`platform_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台登录日志(v5.1.0)';
-- ------------------------------------------------------------
-- 平台通知（v5.1.0：平台超管定向发给某战队管理员的只读公告；v5.2.0：已阅标记）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenant_notices`;
CREATE TABLE `tenant_notices` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '目标租户',
  `content`    VARCHAR(500) NOT NULL              COMMENT '通知内容',
  `read_at`    DATETIME              DEFAULT NULL COMMENT '已阅时间(v5.2.0,NULL=未读)',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台通知(v5.1.0)';
-- ------------------------------------------------------------
-- 隐身巡查令牌（v5.2.0：数据库签发制，仅平台超管可签发）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `stealth_tokens`;
CREATE TABLE `stealth_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '目标租户',
  `token_hash` CHAR(64)     NOT NULL              COMMENT '令牌sha256(原值不落库)',
  `created_by` INT UNSIGNED NOT NULL              COMMENT '签发人(平台超管ID)',
  `created_at` DATETIME              DEFAULT NULL,
  `expires_at` DATETIME              DEFAULT NULL COMMENT '过期时间(v5.16.0起为30分钟)',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='隐身巡查令牌(v5.2.0)';
-- ------------------------------------------------------------
-- 平台协作授权（v5.36.13：战队队长授权平台超管进入本队管理中心）
--   一个租户只有一条记录；revoked_at 为空表示当前有效。
--   授权长期有效，队长可随时撤销；撤销后所有在用协作会话立即失效。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `support_grants`;
CREATE TABLE `support_grants` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `granted_by` INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '授权人(租户站长 users.id)',
  `granted_at` DATETIME              DEFAULT NULL,
  `revoked_at` DATETIME              DEFAULT NULL COMMENT '撤销时间(NULL=当前有效)',
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_support_grant_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台协作授权(v5.36.13)';
-- ------------------------------------------------------------
-- 平台协作会话（v5.36.13：平台超管每次进入本队管理中心生成一条）
--   真正生效还要求 support_grants 当前有效、平台账号仍是超管；每条请求都会回库校验。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `support_sessions`;
CREATE TABLE `support_sessions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED    NOT NULL              COMMENT '目标租户',
  `platform_user_id` INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '平台超管ID(platform_users.id)',
  `started_at`       DATETIME                 DEFAULT NULL,
  `last_seen_at`     DATETIME                 DEFAULT NULL,
  `expires_at`       DATETIME                 DEFAULT NULL COMMENT '会话到期时间(4小时)',
  `ended_at`         DATETIME                 DEFAULT NULL COMMENT '主动退出/撤销时间(NULL=进行中)',
  `ip`               VARCHAR(45)     NOT NULL DEFAULT ''   COMMENT '进入时的客户端IP',
  `ua`               VARCHAR(200)    NOT NULL DEFAULT ''   COMMENT '进入时的UA',
  PRIMARY KEY (`id`),
  KEY `idx_support_session_tenant` (`tenant_id`),
  KEY `idx_support_session_platform` (`platform_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台协作会话(v5.36.13)';
-- ------------------------------------------------------------
-- 平台协作一次性入口票据（v5.36.13：平台点「进入协作」签发，5分钟内兑一次）
--   只存 sha256；兑票后立即作废，令牌不再出现在浏览器历史/日志/Referer 里。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED    NOT NULL              COMMENT '目标租户',
  `platform_user_id` INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '签发人(平台超管ID)',
  `token_hash`       CHAR(64)        NOT NULL              COMMENT '票据sha256(原值不落库)',
  `created_at`       DATETIME                 DEFAULT NULL,
  `expires_at`       DATETIME                 DEFAULT NULL,
  `used_at`          DATETIME                 DEFAULT NULL COMMENT '兑票时间(NULL=未使用)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_support_ticket_hash` (`token_hash`),
  KEY `idx_support_ticket_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台协作入口票据(v5.36.13)';
-- ------------------------------------------------------------
-- 管理员登录日志（v5.17.0：只记 role=admin 的成功与失败尝试）
--   成员登录不落这张表——量大且没有取证价值，爆破防护沿用 blocked_ips 与账号锁定
--   flagged=1 表示该次成功登录的归属地与上一条成功记录不同，当事人与队长各有一条未读提醒
--   保留策略：每支战队最多 5000 行，写入时顺带裁剪最旧的（无 cron）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE `login_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '账号ID(账号不存在时为0)',
  `username`   VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '登录名快照',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '客户端IP(client_ip 口径)',
  `region`     VARCHAR(128) NOT NULL DEFAULT ''   COMMENT 'IP归属地(查询失败为空串)',
  `ua`         VARCHAR(200) NOT NULL DEFAULT ''   COMMENT 'User-Agent 截断',
  `via`        VARCHAR(12)  NOT NULL DEFAULT 'pwd' COMMENT 'pwd密码 cookie免登录静默恢复',
  `result`     VARCHAR(8)   NOT NULL DEFAULT 'success' COMMENT 'success成功 fail失败',
  `flagged`    TINYINT      NOT NULL DEFAULT 0    COMMENT '1=新归属地登录，需提醒',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`, `id`),
  KEY `idx_user` (`tenant_id`, `user_id`),
  KEY `idx_flag` (`tenant_id`, `flagged`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员登录日志(v5.17.0)';
-- ------------------------------------------------------------
-- 对外授权登录（v5.18.0 引入，v5.36.7 已整体下线）
--   代码、路由、授权页、平台配置与个人中心入口都已删除；下面五张表只保留历史数据，
--   新部署不会再被代码读写，可以整组删掉，不影响成员密码登录与未晞通行证。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `oauth_apps`;
CREATE TABLE `oauth_apps` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_id`        VARCHAR(32)  NOT NULL              COMMENT '对外标识(随机16进制,唯一)',
  `secret_hash`   CHAR(64)     NOT NULL              COMMENT 'AppSecret的sha256(明文只在生成或重置时显示一次)',
  `secret_prefix` VARCHAR(8)   NOT NULL DEFAULT ''   COMMENT 'Secret明文前8位(辨认用,不足以还原)',
  `name`          VARCHAR(64)  NOT NULL              COMMENT '网站名称(授权页大字显示)',
  `domain`        VARCHAR(128) NOT NULL DEFAULT ''   COMMENT '主域名(仅展示,不参与校验)',
  `redirect_uris` TEXT                               COMMENT '回调地址白名单:一行一个,全等匹配,必须https',
  `contact`       VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '联系人/QQ',
  `remark`        VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '备注(超管自用)',
  `status`        TINYINT      NOT NULL DEFAULT 1    COMMENT '1启用/0停用(停用即拒绝新授权并作废未过期令牌)',
  `created_by`    INT UNSIGNED          DEFAULT NULL COMMENT '登记人(平台超管ID)',
  `last_used_at`  DATETIME              DEFAULT NULL COMMENT '最近一次换令牌时间',
  `created_at`    DATETIME              DEFAULT NULL,
  `updated_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_app_id` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方授权登录应用(v5.18.0)';
-- ------------------------------------------------------------
-- 第三方授权同意关系（账号 × 应用 一行；「记住同意」靠它，撤销只是置 revoked_at 不删行）
--   保留 UNIQUE 是为了同一账号重复同意只留一行，used_at 记录最近一次发码
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `oauth_grants`;
CREATE TABLE `oauth_grants` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_ref`    INT UNSIGNED NOT NULL              COMMENT 'oauth_apps.id',
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '用户所选战队(租户ID)',
  `user_id`    INT UNSIGNED NOT NULL              COMMENT '同意的账号',
  `created_at` DATETIME              DEFAULT NULL COMMENT '首次同意时间',
  `used_at`    DATETIME              DEFAULT NULL COMMENT '最近一次凭此授权发码时间',
  `revoked_at` DATETIME              DEFAULT NULL COMMENT '非空=已撤销(用户或平台撤销)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_grant` (`app_ref`, `tenant_id`, `user_id`),
  KEY `idx_oauth_grant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方授权同意关系(v5.18.0)';
-- ------------------------------------------------------------
-- 第三方授权码（一次性：兑换靠「带条件的 DELETE」把校验与作废并成一步）
--   redirect_uri 落库是为了换令牌时再比一次，防止授权码被截走后改投别的回调地址
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `oauth_auth_codes`;
CREATE TABLE `oauth_auth_codes` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash`    CHAR(64)     NOT NULL              COMMENT '授权码sha256(原值只在跳转里出现一次,不落库)',
  `app_ref`      INT UNSIGNED NOT NULL              COMMENT 'oauth_apps.id',
  `tenant_id`    INT UNSIGNED NOT NULL              COMMENT '用户所选战队',
  `user_id`      INT UNSIGNED NOT NULL              COMMENT '授权的账号',
  `redirect_uri` VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '发码时的回调地址(换令牌须与之全等)',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '同意授权时的客户端IP',
  `created_at`   DATETIME              DEFAULT NULL,
  `expires_at`   DATETIME              DEFAULT NULL COMMENT '签发后5分钟',
  `used_at`      DATETIME              DEFAULT NULL COMMENT '非空=已兑换',
  PRIMARY KEY (`id`),
  KEY `idx_oauth_code_hash` (`code_hash`),
  KEY `idx_oauth_code_app` (`app_ref`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方授权码(v5.18.0,一次性5分钟)';
-- ------------------------------------------------------------
-- 第三方访问令牌（短期 10 分钟，只给服务端到服务端取用户信息用，不发 refresh）
--   撤销授权 / 停用应用时把未过期的令牌一并置 revoked_at，避免"撤销了还能取一次"
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `oauth_access_tokens`;
CREATE TABLE `oauth_access_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash`   CHAR(64)     NOT NULL              COMMENT 'access_token的sha256(原值不落库)',
  `app_ref`      INT UNSIGNED NOT NULL              COMMENT 'oauth_apps.id',
  `tenant_id`    INT UNSIGNED NOT NULL              COMMENT '用户所选战队',
  `user_id`      INT UNSIGNED NOT NULL              COMMENT '授权的账号',
  `code_id`      BIGINT UNSIGNED     DEFAULT NULL   COMMENT '来源授权码id(可追溯到那次同意)',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '换令牌时的客户端IP(对方服务器出网IP)',
  `created_at`   DATETIME              DEFAULT NULL,
  `expires_at`   DATETIME              DEFAULT NULL COMMENT '签发后10分钟',
  `revoked_at`   DATETIME              DEFAULT NULL COMMENT '非空=已作废',
  `last_used_at` DATETIME              DEFAULT NULL COMMENT '最近一次取用户信息时间',
  PRIMARY KEY (`id`),
  KEY `idx_oauth_token_hash` (`token_hash`),
  KEY `idx_oauth_token_user` (`tenant_id`, `user_id`),
  KEY `idx_oauth_token_app` (`app_ref`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方访问令牌(v5.18.0,10分钟)';
-- ------------------------------------------------------------
-- 第三方授权调用审计（谁在什么时候同意/拒绝/换了令牌/取了用户信息）
--   凭据原文一律不入 detail；保留策略：全站最多 5000 行，写入时顺带裁最旧的（无 cron）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `oauth_logs`;
CREATE TABLE `oauth_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_ref`     INT UNSIGNED NOT NULL DEFAULT 0    COMMENT 'oauth_apps.id(0=平台侧动作)',
  `app_name`    VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '应用名快照',
  `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '相关战队(0=无)',
  `tenant_name` VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '战队名快照',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '相关账号(0=无)',
  `qq`          VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '登录名快照(QQ号)',
  `action`      VARCHAR(24)  NOT NULL DEFAULT ''   COMMENT '同意授权 拒绝授权 换令牌 取用户信息 撤销授权 登记应用 重置密钥 启停应用 删除应用',
  `result`      VARCHAR(8)   NOT NULL DEFAULT 'success' COMMENT 'success成功 fail失败',
  `detail`      VARCHAR(300) NOT NULL DEFAULT ''   COMMENT '详情(不含任何凭据原文)',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '客户端IP',
  `created_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oauth_log_app` (`app_ref`, `id`),
  KEY `idx_oauth_log_tenant` (`tenant_id`, `id`),
  KEY `idx_oauth_log_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方授权调用审计(v5.18.0)';
-- ------------------------------------------------------------
-- 一对一私聊（v5.19.0：聊天室拆成「战队群 + 私聊」，战队群就是原来的聊天室）
--   正文只有当事双方可读。管理员默认只读元数据（谁和谁、多少条、最后时间），
--   必须先有一条被处理的举报(chat_reports) 才允许展开那个会话的正文，展开要写操作日志。
--   pair_key = 双方 user_id 按小-大拼接(如 7-23)，会话聚合/未读/元数据分组都靠它
--   私聊不跨战队：用户身份本来就是 (tenant_id, user_id)，跨队发是另一个产品
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `chat_dm_messages`;
CREATE TABLE `chat_dm_messages` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED  NOT NULL              COMMENT '所属租户(私聊只在本队内)',
  `from_user_id` INT UNSIGNED  NOT NULL              COMMENT '发送者',
  `to_user_id`   INT UNSIGNED  NOT NULL              COMMENT '接收者',
  `pair_key`     VARCHAR(32)   NOT NULL DEFAULT ''    COMMENT '会话键(小id-大id)',
  `content`      VARCHAR(500)  NOT NULL              COMMENT '正文(仅双方可读;管理员只在被举报会话里可读)',
  `recalled`     TINYINT       NOT NULL DEFAULT 0     COMMENT '1=发送者本人撤回(管理员不能撤别人的私聊)',
  `recalled_at`  DATETIME               DEFAULT NULL,
  `created_at`   DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dm_pair` (`tenant_id`, `pair_key`, `id`),
  KEY `idx_dm_to`   (`tenant_id`, `to_user_id`, `recalled`, `id`),
  KEY `idx_dm_pair_g` (`pair_key`, `id`),
  KEY `idx_dm_from`   (`from_user_id`, `id`),
  KEY `idx_dm_to_g`   (`to_user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一对一私聊消息(v5.19.0)';
-- 未读游标：每人对每个对方记一个「已读到哪条」，未读数 = 该会话里对方发的、比它新的条数
DROP TABLE IF EXISTS `chat_dm_reads`;
CREATE TABLE `chat_dm_reads` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED  NOT NULL              COMMENT '所属租户',
  `user_id`      INT UNSIGNED  NOT NULL              COMMENT '阅读者',
  `peer_id`      INT UNSIGNED  NOT NULL              COMMENT '对方',
  `last_read_id` BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '已读到的消息id(0=一条没读)',
  `updated_at`   DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dm_read` (`tenant_id`, `user_id`, `peer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='私聊已读游标(v5.19.0)';
-- 成员自己的「是否接受私聊」开关。用一张表而不是给 users 加列：
-- 升级脚本就不用 ALTER，MySQL 5.7 那套 1060 的坑也就不存在了
DROP TABLE IF EXISTS `chat_dm_prefs`;
CREATE TABLE `chat_dm_prefs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`    INT UNSIGNED NOT NULL              COMMENT '账号',
  `allow_dm`   TINYINT      NOT NULL DEFAULT 1     COMMENT '1=接受私聊 0=不接受(v5.30.1起默认开)',
  `allow_stranger` TINYINT  NOT NULL DEFAULT 1     COMMENT '1=允许其它战队的人先开口 私聊 0=只接本队(v5.30.1)',
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dm_pref` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='私聊接收开关(v5.19.0,本人自管)';
-- 私聊举报：管理员读正文的唯一钥匙。
-- 只有会话当事方能举报；管理员展开正文必须挂在一个 pending/report 上，且逐次写 admin_logs
DROP TABLE IF EXISTS `chat_reports`;
CREATE TABLE `chat_reports` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `pair_key`    VARCHAR(32)  NOT NULL              COMMENT '被举报的会话键',
  `message_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '举报锚点(那一条私聊消息)',
  `reporter_id` INT UNSIGNED NOT NULL              COMMENT '举报人(必须是会话当事方)',
  `peer_id`     INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '被举报的一方',
  `reason`      VARCHAR(200) NOT NULL DEFAULT ''    COMMENT '举报理由(不写正文)',
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'pending' COMMENT 'pending待处理 opened已展开看过 dismissed已忽略',
  `created_at`  DATETIME              DEFAULT NULL,
  `reviewed_by` INT UNSIGNED           DEFAULT NULL COMMENT '处理的管理员',
  `reviewed_at` DATETIME              DEFAULT NULL,
  `result_note` VARCHAR(200) NOT NULL DEFAULT ''    COMMENT '处理结论(留痕用,不含正文)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report_msg` (`tenant_id`, `message_id`),
  KEY `idx_report_status` (`tenant_id`, `status`, `id`),
  KEY `idx_report_pair` (`tenant_id`, `pair_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='私聊举报(v5.19.0,管理员读正文的唯一入口)';
-- ------------------------------------------------------------
-- v5.20.0 战队对外展示页（/{slug}/show）与平台公示大厅
-- 与 database/upgrade_v5.19.0_to_v5.20.1.sql 保持一致（增量脚本与这里的 DDL 逐字相同）
--   showcase_items  ：展示页条目——kind=gallery 图集 / honor 荣誉墙
--                     （图片物理文件在 uploads/showcase 下，计入本队存储配额）
--   show_submissions：访客投递的两个箱子——type=post 内容投稿（通过才上墙）
--                     type=intent 入队意向（只是昵称+QQ+自我介绍，不等于入队资格，
--                     仍需在本队注册并通过「注册审核」）
-- 页面开关/口号/精选勾选等一律放 settings 键值，不再建列；
-- 成员能否出现在展示页 = users.show_public（本人勾）+ 队长的成员模块开关，双层。
-- 战绩是第三档：users.stats_public（本人勾）只放开给本队成员看，与对外展示页互不影响(v5.30.0)。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `showcase_items`;
CREATE TABLE `showcase_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `kind`       VARCHAR(12)  NOT NULL              COMMENT 'gallery图集 honor荣誉墙',
  `title`      VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '标题(荣誉名/图注)',
  `note`       VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '说明',
  `image`      VARCHAR(160) NOT NULL DEFAULT ''   COMMENT '图片(/storage/uploads/showcase/下相对路径)',
  `sort`       INT          NOT NULL DEFAULT 0    COMMENT '排序(小的在前)',
  `visible`    TINYINT      NOT NULL DEFAULT 1     COMMENT '1=展示页可见 0=隐藏(留着不删)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_show_item` (`tenant_id`, `kind`, `visible`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='展示页条目(v5.20.0,图集/荣誉)';
DROP TABLE IF EXISTS `show_submissions`;
CREATE TABLE `show_submissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL              COMMENT '投递到哪个战队站点',
  `type`        VARCHAR(8)   NOT NULL DEFAULT 'intent' COMMENT 'post内容投稿(通过后上墙) intent入队意向',
  `qq`          VARCHAR(16)  NOT NULL DEFAULT ''   COMMENT '访客QQ号(意向必填,供队长联系)',
  `nick`        VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '游戏昵称',
  `position`    VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '意向岗位(取自该队招募岗位,可为其他)',
  `content`     VARCHAR(500) NOT NULL              COMMENT '投稿说明/自我介绍',
  `image`       VARCHAR(160) NOT NULL DEFAULT ''   COMMENT '投稿截图(仅 post 采纳后上墙)',
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'pending' COMMENT 'pending待处理 approved已采纳 rejected已驳回',
  `result_note` VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '管理员结论/驳回理由',
  `reviewed_by` INT UNSIGNED          DEFAULT NULL COMMENT '处理的管理员',
  `reviewed_at` DATETIME              DEFAULT NULL,
  `ip`          VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '提交者IP',
  `region`      VARCHAR(128) NOT NULL DEFAULT ''   COMMENT 'IP归属地(与注册同一口径)',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_show_sub` (`tenant_id`, `type`, `status`, `id`),
  KEY `idx_show_sub_ip` (`tenant_id`, `ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='展示页投稿与入队意向(v5.20.0)';
-- ------------------------------------------------------------
-- 站点数据导入导出任务（v5.2.0：提交审核制，每天各1次）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenant_data_tasks`;
CREATE TABLE `tenant_data_tasks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL              COMMENT '申请站点',
  `type`        VARCHAR(8)   NOT NULL              COMMENT 'export导出/import导入',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待审核approved导出就绪done导入完成rejected驳回failed失败cancelled撤回',
  `file`        VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '包文件(runtime/data_tasks下相对路径)',
  `note`        VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '驳回原因/失败原因',
  `created_at`  DATETIME              DEFAULT NULL,
  `reviewed_at` DATETIME              DEFAULT NULL,
  `reviewed_by` INT UNSIGNED          DEFAULT NULL COMMENT '审核人(平台超管ID)',
  `finished_at` DATETIME              DEFAULT NULL COMMENT '导入完成时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点数据导入导出任务(v5.2.0)';
-- ------------------------------------------------------------
-- 战队入驻申请表（v5.0.0：平台登录后提交，平台超管审核）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenant_applications`;
CREATE TABLE `tenant_applications` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_id`  INT UNSIGNED NOT NULL              COMMENT '申请人(平台账号ID)',
  `slug`          VARCHAR(30)  NOT NULL              COMMENT '期望目录名',
  `name`          VARCHAR(64)  NOT NULL              COMMENT '战队名称',
  `logo`          VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '战队logo(存储相对路径,可选)',
  `description`   VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '战队简介',
  `admin_username` VARCHAR(32) NOT NULL              COMMENT '战队系统超管用户名',
  `admin_password` VARCHAR(255) NOT NULL             COMMENT '战队系统超管密码哈希(bcrypt)',
  `screenshot`    VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '游戏主页截图',
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待审 approved已通过 rejected已驳回',
  `reject_reason` VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '驳回原因',
  `created_at`    DATETIME              DEFAULT NULL,
  `updated_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_applicant` (`applicant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='战队入驻申请表';
-- ------------------------------------------------------------
-- 以下均为战队系统业务表（tenant_id 隔离各战队数据）
-- ------------------------------------------------------------
-- 用户表（战队系统成员，在各 /{slug}/ 系统内注册）
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `username`   VARCHAR(32)  NOT NULL                COMMENT '登录名(QQ号)',
  `password`   VARCHAR(255) NOT NULL                COMMENT '密码哈希(bcrypt)',
  `nickname`   VARCHAR(32)  NOT NULL DEFAULT ''     COMMENT '昵称',
  `email`      VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '邮箱(QQ号@qq.com)',
  `profile_image` VARCHAR(255) NOT NULL DEFAULT ''  COMMENT '注册时上传的游戏主页截图',
  `reg_ip`     VARCHAR(45)  NOT NULL DEFAULT ''     COMMENT '注册IP',
  `reg_region` VARCHAR(128) NOT NULL DEFAULT ''     COMMENT 'IP归属地(省市区)',
  `role`       VARCHAR(16)  NOT NULL DEFAULT 'user' COMMENT '角色: user普通用户 admin管理员',
  `team_id`    INT UNSIGNED          DEFAULT NULL   COMMENT '所属战队小队ID',
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待审核 approved已通过 banned已封禁',
  `muted`      TINYINT      NOT NULL DEFAULT 0      COMMENT '禁言: 1禁言 0正常',
  `muted_by`   VARCHAR(8)   NOT NULL DEFAULT ''     COMMENT '禁言来源: team本队自设 platform平台所设(队长解不掉,v5.30.1)',
  `admin_perms` VARCHAR(512) NOT NULL DEFAULT ''    COMMENT '管理员权限JSON(v3.9:超管分配)',
  `role_tags`  VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '职责标签ID数组JSON,第一个为默认展示(v5.3.0)',
  `session_epoch` INT UNSIGNED NOT NULL DEFAULT 0   COMMENT '密码纪元:改密+1,旧会话强制登出(v5.7.1)',
  `last_active_at` DATETIME          DEFAULT NULL   COMMENT '最后活跃时间(在线统计)',
  `login_alert_seen_at` DATETIME     DEFAULT NULL   COMMENT '登录安全提醒已读游标(v5.17.0)',
  `show_public`  TINYINT      NOT NULL DEFAULT 1      COMMENT '1=同意在本队对外展示页出现(昵称+职责标签,v5.20.0;v5.30.1起默认开)',
  `stats_public` TINYINT      NOT NULL DEFAULT 1      COMMENT '1=同意把本人战绩展示给本队成员(v5.30.0;v5.30.1起默认开)',
  `game_side`      VARCHAR(8) NOT NULL DEFAULT ''     COMMENT '端别: pc端游 mobile手游 空=老数据未分类(升级后必须补选)(v5.36.0)',
  `game_side_lock` VARCHAR(8) NOT NULL DEFAULT ''     COMMENT '跨端可见: 空=跟随全队 1=只能看本端 0=允许看两端(v5.36.0)',
  `created_at` DATETIME              DEFAULT NULL   COMMENT '注册时间',
  `updated_at` DATETIME              DEFAULT NULL   COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_username` (`tenant_id`, `username`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_team` (`team_id`),
  KEY `idx_status` (`status`),
  KEY `idx_active` (`last_active_at`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表(战队系统成员)';
-- 未晞通行证绑定表（v5.36.6：通行证 sub 与本队账号一对一；密码登录不受影响）
DROP TABLE IF EXISTS `passport_links`;
CREATE TABLE `passport_links` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`      INT UNSIGNED NOT NULL              COMMENT '本站用户ID',
  `passport_sub` VARCHAR(64)  NOT NULL              COMMENT '通行证稳定用户标识 sub',
  `created_at`   DATETIME              DEFAULT NULL,
  `updated_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_passport_tenant_sub` (`tenant_id`, `passport_sub`),
  UNIQUE KEY `uk_passport_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_passport_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='未晞通行证绑定';
-- 战队小队表
DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `name`        VARCHAR(64)  NOT NULL              COMMENT '小队名称',
  `description` VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '小队简介',
  `leader_id`   INT UNSIGNED          DEFAULT NULL COMMENT '队长用户ID',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name` (`tenant_id`, `name`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='战队小队表';
-- 游戏图片表
DROP TABLE IF EXISTS `images`;
CREATE TABLE `images` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `user_id`     INT UNSIGNED NOT NULL              COMMENT '上传者',
  `title`       VARCHAR(128) NOT NULL DEFAULT ''   COMMENT '图片标题',
  `file`        VARCHAR(255) NOT NULL              COMMENT '存储相对路径',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待审核 approved已通过 rejected已拒绝',
  `reviewed_by` INT UNSIGNED          DEFAULT NULL COMMENT '审核人(管理员)',
  `reviewed_at` DATETIME              DEFAULT NULL COMMENT '审核时间',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='游戏图片表';
-- 改枪码表
DROP TABLE IF EXISTS `gun_codes`;
CREATE TABLE `gun_codes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `user_id`     INT UNSIGNED NOT NULL              COMMENT '上传者',
  `title`       VARCHAR(128) NOT NULL              COMMENT '枪械名字',
  `code`        TEXT         NOT NULL              COMMENT '枪码内容',
  `description` VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '说明',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'published' COMMENT 'published已发布 offline已下架',
  `category`    VARCHAR(32)  NOT NULL DEFAULT 'other'  COMMENT '武器类别 other未分类 smg冲锋枪 ar突击步枪 pistol手枪 special特殊武器 sniper狙击步枪 dmr精准射手步枪 lmg轻机枪 shotgun霰弹枪',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`tenant_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='改枪码表';
-- 改枪码评论表（v3.2）
DROP TABLE IF EXISTS `gun_code_comments`;
CREATE TABLE `gun_code_comments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `gun_code_id` INT UNSIGNED NOT NULL              COMMENT '所属枪码ID',
  `user_id`     INT UNSIGNED NOT NULL              COMMENT '评论者',
  `content`     VARCHAR(300) NOT NULL              COMMENT '评论内容',
  `created_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_gc` (`gun_code_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='改枪码评论表';
-- IP 封禁表（v3.2：登录/注册防爆破，自动+手动封禁；平台级全局生效）
DROP TABLE IF EXISTS `blocked_ips`;
CREATE TABLE `blocked_ips` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)  NOT NULL               COMMENT '被封禁的IP',
  `reason`     VARCHAR(128) NOT NULL DEFAULT ''    COMMENT '封禁原因',
  `blocked_by` INT UNSIGNED          DEFAULT NULL  COMMENT '操作管理员ID(NULL=系统自动封禁)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP封禁表';
-- 聊天消息表
DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED  NOT NULL              COMMENT '所属租户',
  `user_id`    INT UNSIGNED  NOT NULL     COMMENT '发送者',
  `content`    VARCHAR(500)  NOT NULL     COMMENT '消息内容',
  `recalled`   TINYINT       NOT NULL DEFAULT 0 COMMENT '1已被管理员撤回',
  `recalled_by` INT UNSIGNED          DEFAULT NULL COMMENT '撤回人(管理员)',
  `created_at` DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_created` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='聊天消息表';
-- 系统设置表（按租户隔离；新租户开通时由审核通过逻辑写入默认值）
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '所属租户',
  `key`   VARCHAR(64) NOT NULL,
  `value` TEXT,
  PRIMARY KEY (`tenant_id`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表';
-- 邮箱验证码表
DROP TABLE IF EXISTS `email_codes`;
CREATE TABLE `email_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(64) NOT NULL,
  `code`       VARCHAR(8)  NOT NULL,
  `used`       TINYINT     NOT NULL DEFAULT 0,
  `expired_at` DATETIME    NOT NULL,
  `created_at` DATETIME             DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`, `used`, `expired_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码表';
-- 公告表
DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `title`      VARCHAR(128) NOT NULL,
  `content`    TEXT         NOT NULL,
  `image`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '配图(可选)',
  `need_receipt` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否需要已读回执(v5.6.0)',
  `deadline`     DATETIME              DEFAULT NULL COMMENT '回执截止(可选,v5.6.0)',
  `reminded_at`  DATETIME              DEFAULT NULL COMMENT '一键提醒未读时间(v5.6.0)',
  `scheduled_at` DATETIME              DEFAULT NULL COMMENT '定时发布时间(为空=立即发,v5.36.34)',
  `revoked_at`   DATETIME              DEFAULT NULL COMMENT '撤回时间(非空=成员端不再展示,v5.36.34)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告表';
-- 撞车比赛时间表
DROP TABLE IF EXISTS `race_events`;
CREATE TABLE `race_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `title`      VARCHAR(128) NOT NULL DEFAULT '撞车比赛',
  `race_time`  DATETIME     NOT NULL COMMENT '比赛时间点',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_time` (`race_time`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='撞车比赛时间表';
-- 自建房比赛日程表（v3.9.6）
DROP TABLE IF EXISTS `custom_race_events`;
CREATE TABLE `custom_race_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `title`      VARCHAR(128) NOT NULL DEFAULT '自建房比赛',
  `race_time`  DATETIME     NOT NULL COMMENT '比赛时间点',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_time` (`race_time`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自建房比赛日程表';
-- 游戏攻略表（v3.7：用户自发布文章）
DROP TABLE IF EXISTS `guides`;
CREATE TABLE `guides` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `user_id`    INT UNSIGNED NOT NULL              COMMENT '作者',
  `title`      VARCHAR(128) NOT NULL              COMMENT '攻略标题',
  `content`    TEXT         NOT NULL              COMMENT '攻略正文',
  `image`      VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '封面图(可选)',
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'published' COMMENT 'published已发布 offline已下架',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='游戏攻略表';
-- 管理操作日志表（v3.7）
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `admin_id`   INT UNSIGNED NOT NULL              COMMENT '管理员用户ID',
  `admin_name` VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '管理员名称快照',
  `action`     VARCHAR(64)  NOT NULL              COMMENT '操作类型',
  `detail`     VARCHAR(300) NOT NULL DEFAULT ''   COMMENT '操作详情',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理操作日志表';
-- 轮换地图表（v3.8：今天轮换地图）
DROP TABLE IF EXISTS `rotation_maps`;
CREATE TABLE `rotation_maps` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `name`       VARCHAR(64)  NOT NULL              COMMENT '地图名称',
  `image`      VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '地图图片',
  `sort`       INT          NOT NULL DEFAULT 0    COMMENT '轮换顺序(小在前)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮换地图表(v5.1.0:平台统一配置,数据落tenant_id=0)';
-- 轮换每日组合表（v3.8.1：一天一组多张地图，每天 9 点切换）
DROP TABLE IF EXISTS `rotation_days`;
CREATE TABLE `rotation_days` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `name`       VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '组合名称(可选)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮换每日组合表';
-- 每日组合-地图关联表（v3.8.1：多选）
DROP TABLE IF EXISTS `rotation_day_maps`;
CREATE TABLE `rotation_day_maps` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `day_id` INT UNSIGNED NOT NULL              COMMENT '每日组合',
  `map_id` INT UNSIGNED NOT NULL              COMMENT '地图',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_day_map` (`day_id`,`map_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_map` (`map_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日组合-地图关联表';
-- 职责标签库（v5.3.0：管理员维护，名称+自定义颜色）
DROP TABLE IF EXISTS `role_tags`;
CREATE TABLE `role_tags` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `name`       VARCHAR(16)  NOT NULL              COMMENT '标签名称',
  `color`      CHAR(7)      NOT NULL DEFAULT '#0f766e' COMMENT '展示颜色(#rrggbb)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name` (`tenant_id`, `name`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='职责标签库(v5.3.0)';
-- 7天免密码登录令牌（v5.3.0：令牌原值不落库，只存 sha256）
DROP TABLE IF EXISTS `remember_tokens`;
CREATE TABLE `remember_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`    INT UNSIGNED NOT NULL              COMMENT '用户ID',
  `token_hash` CHAR(64)     NOT NULL              COMMENT '令牌sha256',
  `created_at` DATETIME              DEFAULT NULL,
  `expires_at` DATETIME              DEFAULT NULL COMMENT '过期时间(签发后7天)',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_hash` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='7天免登录令牌(v5.3.0)';
-- 忘记密码申请表（v5.7.1：新密码密文+主页截图佐证，管理员审核后生效；每用户同时仅一条待审）
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`      INT UNSIGNED NOT NULL              COMMENT '申请用户',
  `new_password` VARCHAR(255) NOT NULL              COMMENT '新密码哈希(bcrypt)',
  `screenshot`   VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '游戏主页截图(身份佐证)',
  `request_ip`   VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '申请IP',
  `created_at`   DATETIME              DEFAULT NULL COMMENT '申请时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='忘记密码申请(v5.7.1:截图佐证+管理员审核)';
-- 每日签到表（v5.3.0：每人每天一条，含连续天数与今日运势表情包）
DROP TABLE IF EXISTS `checkins`;
CREATE TABLE `checkins` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL            COMMENT '所属租户',
  `user_id`      INT UNSIGNED NOT NULL            COMMENT '签到用户',
  `checkin_date` DATE         NOT NULL            COMMENT '签到日期',
  `streak`       INT          NOT NULL DEFAULT 1  COMMENT '当日连续签到天数',
  `fortune_img`  TINYINT      NOT NULL DEFAULT 0  COMMENT '今日运势表情包编号(1..N)',
  `created_at`   DATETIME              DEFAULT NULL COMMENT '签到时间(当日先后排名)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user_date` (`tenant_id`, `user_id`, `checkin_date`),
  KEY `idx_tenant_date` (`tenant_id`, `checkin_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日签到表(v5.3.0)';
-- 跨战队约战单（v5.4.0：平台级总线，参赛方=租户|外部战队，a=发起方）
DROP TABLE IF EXISTS `battles`;
CREATE TABLE `battles` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`             VARCHAR(50)  NOT NULL DEFAULT ''   COMMENT '约战标题',
  `game_mode`         VARCHAR(50)  NOT NULL DEFAULT ''   COMMENT '模式说明',
  `battle_time`       DATETIME     NOT NULL              COMMENT '约定开赛时间',
  `room_info`         VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '房间/地点',
  `remark`            VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '备注',
  `challenger_type`   VARCHAR(8)   NOT NULL              COMMENT 'tenant租户/external外部战队',
  `challenger_id`     INT UNSIGNED NOT NULL,
  `defender_type`     VARCHAR(8)   NOT NULL              COMMENT 'tenant租户/external外部战队',
  `defender_id`       INT UNSIGNED NOT NULL,
  `status`            VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待接受accepted报名中finished已完赛rejected被拒canceled已取消expired过期',
  `battle_type`       VARCHAR(10)  NOT NULL DEFAULT 'official' COMMENT 'official正式赛事(平台抽签裁决)/training训练赛(自动抽签自裁决)',
  `series_format`     VARCHAR(8)   NOT NULL DEFAULT 'bo3' COMMENT '赛制:bo1/bo3/bo5/r2一图两把攻防互换/r4两图四把',
  `roster_limit`      INT UNSIGNED NOT NULL DEFAULT 20   COMMENT '每方出战上限,0=不限',
  `ban_a_map_id`      INT UNSIGNED          DEFAULT NULL  COMMENT 'a方禁用地图',
  `ban_b_map_id`      INT UNSIGNED          DEFAULT NULL  COMMENT 'b方禁用地图',
  `drawn_at`          DATETIME              DEFAULT NULL  COMMENT '抽签时间(空=未抽签)',
  `adv_side`          CHAR(1)               DEFAULT NULL  COMMENT '决胜局优势方a/b(bo3判定/bo5率先两胜)',
  `adv_reason`        VARCHAR(120) NOT NULL DEFAULT ''    COMMENT '优势方判定依据',
  `adv_role`          VARCHAR(10)           DEFAULT NULL  COMMENT '优势方决胜选择:pick_map选图/pick_side选边',
  `result`            VARCHAR(8)            DEFAULT NULL  COMMENT 'a_win/b_win/draw(r赛制可平局),自动结算',
  `result_note`       VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '比分说明',
  `result_by`         INT UNSIGNED          DEFAULT NULL  COMMENT '确认人平台用户ID',
  `created_by`        VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '发起人描述',
  `accepted_by`       VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '接受人描述',
  `canceled_by`       VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '取消人描述',
  `finished_at`       DATETIME              DEFAULT NULL,
  `created_at`        DATETIME              DEFAULT NULL,
  `updated_at`        DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_battles_status` (`status`),
  KEY `idx_battles_challenger` (`challenger_type`,`challenger_id`),
  KEY `idx_battles_defender` (`defender_type`,`defender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='跨战队约战单(v5.4.0)';
-- 约战出战名单（v5.4.0：平台侧用户 user_id 关联；外部战队昵称上报）
DROP TABLE IF EXISTS `battle_signups`;
CREATE TABLE `battle_signups` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `battle_id`        INT UNSIGNED NOT NULL,
  `side`             CHAR(1)      NOT NULL              COMMENT 'a发起方/b应战方',
  `signup_type`      VARCHAR(8)   NOT NULL DEFAULT 'user' COMMENT 'user平台用户/name外部昵称',
  `user_id`          INT UNSIGNED          DEFAULT NULL,
  `display_name`     VARCHAR(32)  NOT NULL DEFAULT '',
  `tenant_id`        INT UNSIGNED          DEFAULT NULL,
  `external_team_id` INT UNSIGNED          DEFAULT NULL,
  `manual`           TINYINT      NOT NULL DEFAULT 0    COMMENT '0自行报名/1管理员添加',
  `created_at`       DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_signup_user` (`battle_id`,`side`,`user_id`),
  KEY `idx_bsignups_battle` (`battle_id`),
  KEY `idx_bsignups_display` (`display_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='约战出战名单(v5.4.0)';
-- 约战系列赛对局（v5.4.0：抽签一次性生成全部小局，含地图名快照与攻守方；
-- bo3第3局/bo5第5局为决胜局：地图与攻守在优势方「选图/选边」前为空）
DROP TABLE IF EXISTS `battle_rounds`;
CREATE TABLE `battle_rounds` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `battle_id`     INT UNSIGNED NOT NULL,
  `round_no`      INT UNSIGNED NOT NULL              COMMENT '局序(1起)',
  `map_id`        INT UNSIGNED          DEFAULT NULL COMMENT '抽签地图(决胜局选图前为空)',
  `map_name`      VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '地图名快照',
  `attacker_side` CHAR(1)               DEFAULT NULL COMMENT '攻方a/b(决胜局选边前为空)',
  `result`        VARCHAR(8)            DEFAULT NULL COMMENT 'a_win/b_win(无平局)',
  `result_by`     INT UNSIGNED          DEFAULT NULL,
  `evidence`      VARCHAR(200)          DEFAULT NULL COMMENT '训练赛小局结果截图',
  `attack_secs`   INT                   DEFAULT NULL COMMENT '攻方进攻用时秒(bo3决胜判定)',
  `occupy_count`  INT                   DEFAULT NULL COMMENT '占领区域总数(bo3决胜判定)',
  `occupy_ratio`  INT                   DEFAULT NULL COMMENT '占点比例%(bo3决胜判定)',
  `created_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_battle_round` (`battle_id`,`round_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='约战系列赛对局(v5.4.0)';
-- 外部战队登记（v5.4.0：开源单租户站接入身份，平台超管维护）
DROP TABLE IF EXISTS `external_teams`;
CREATE TABLE `external_teams` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(50)  NOT NULL              COMMENT '队名',
  `contact_name` VARCHAR(50)  NOT NULL DEFAULT ''   COMMENT '联系人',
  `remark`       VARCHAR(200) NOT NULL DEFAULT '',
  `status`       TINYINT      NOT NULL DEFAULT 1    COMMENT '1正常/0停用',
  `created_at`   DATETIME              DEFAULT NULL,
  `updated_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外部战队登记(v5.4.0)';
-- 私有接口密钥（v5.4.0：明文仅生成时显示一次，库中只存 sha256）
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_hash`         CHAR(64)     NOT NULL              COMMENT '密钥sha256',
  `key_prefix`       VARCHAR(8)   NOT NULL DEFAULT ''   COMMENT '明文前8位(辨认用)',
  `name`             VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '备注名',
  `scope`            VARCHAR(16)  NOT NULL              COMMENT 'platform/tenant/external',
  `tenant_id`        INT UNSIGNED          DEFAULT NULL,
  `external_team_id` INT UNSIGNED          DEFAULT NULL,
  `bound_user_id`    INT UNSIGNED          DEFAULT NULL COMMENT '绑定身份用户(平台侧)',
  `status`           TINYINT      NOT NULL DEFAULT 1    COMMENT '1启用/0停用',
  `created_by`       INT UNSIGNED          DEFAULT NULL,
  `last_used_at`     DATETIME              DEFAULT NULL,
  `created_at`       DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_apikeys_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='私有接口密钥(v5.4.0)';
-- ============================================================
-- v5.6.0 六项功能：统一日程 / 公告送达确认 / 队内训练赛 / 黑名单 / 值班表 / 轻量投票
-- （与 database/game_team_upgrade_v5_5_1_to_v5_6_0.sql 保持一致）
-- ============================================================
-- 本队黑名单（注册审核自动预警）
DROP TABLE IF EXISTS `blacklist`;
CREATE TABLE `blacklist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `qq`         VARCHAR(16)  NOT NULL                COMMENT 'QQ号(唯一)',
  `nickname`   VARCHAR(32)  NOT NULL DEFAULT ''     COMMENT '昵称(选填)',
  `reason`     VARCHAR(200) NOT NULL                COMMENT '拉黑原因',
  `created_by` VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '添加人快照',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_qq` (`tenant_id`, `qq`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='本队黑名单(v5.6.0)';
-- 公告已读回执
DROP TABLE IF EXISTS `announcement_reads`;
CREATE TABLE `announcement_reads` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `announcement_id` INT UNSIGNED NOT NULL              COMMENT '公告ID',
  `user_id`       INT UNSIGNED NOT NULL                COMMENT '已读用户',
  `reminded`      TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '是否被提醒后已读',
  `created_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ann_user` (`announcement_id`, `user_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告已读回执(v5.6.0)';
-- 日程自定义活动（类别隐藏开关存 settings.key=schedule_hide）
DROP TABLE IF EXISTS `custom_events`;
CREATE TABLE `custom_events` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `title`        VARCHAR(128) NOT NULL                COMMENT '活动标题',
  `start_time`   DATETIME     NOT NULL                COMMENT '开始时间',
  `duration_min` INT UNSIGNED NOT NULL DEFAULT 60     COMMENT '时长(分钟)',
  `note`         VARCHAR(500) NOT NULL DEFAULT ''     COMMENT '说明(地点/房间号/备注)',
  `created_at`   DATETIME              DEFAULT NULL,
  `updated_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_time` (`start_time`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日程自定义活动(v5.6.0)';
-- 值班配置
DROP TABLE IF EXISTS `duty_configs`;
CREATE TABLE `duty_configs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `duty_type`  VARCHAR(16)  NOT NULL DEFAULT 'room_host' COMMENT '值班类型(首期room_host房主值班)',
  `cycle`      VARCHAR(8)   NOT NULL DEFAULT 'day'  COMMENT '轮换周期 day按天/week按周',
  `anchor_date` DATE        NOT NULL                COMMENT '轮换锚点日期(第0周期)',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '是否启用',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_type` (`tenant_id`, `duty_type`, `game_side`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='值班配置(v5.6.0)';
-- 值班成员名单
DROP TABLE IF EXISTS `duty_members`;
CREATE TABLE `duty_members` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `config_id`  INT UNSIGNED NOT NULL                COMMENT '值班配置',
  `user_id`    INT UNSIGNED NOT NULL                COMMENT '轮值成员',
  `sort`       INT          NOT NULL DEFAULT 0      COMMENT '轮值顺序(小在前)',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_user` (`config_id`, `user_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='值班成员名单(v5.6.0)';
-- 值班换班记录
DROP TABLE IF EXISTS `duty_swaps`;
CREATE TABLE `duty_swaps` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `config_id`   INT UNSIGNED NOT NULL                COMMENT '值班配置',
  `duty_date`   DATE         NOT NULL                COMMENT '值班日',
  `from_uid`    INT UNSIGNED NOT NULL                COMMENT '原值班人(发起人)',
  `to_uid`      INT UNSIGNED NOT NULL                COMMENT '换班对象',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending待确认/accepted已对调/rejected已拒绝',
  `created_at`  DATETIME              DEFAULT NULL,
  `resolved_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_date` (`duty_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='值班换班记录(v5.6.0)';
-- 队内训练赛主表
DROP TABLE IF EXISTS `training_matches`;
CREATE TABLE `training_matches` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`       INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `title`           VARCHAR(50)  NOT NULL DEFAULT ''     COMMENT '标题',
  `mode`            VARCHAR(16)  NOT NULL DEFAULT 'squad' COMMENT 'squad小队对抗/free自由分组',
  `team_a_id`       INT UNSIGNED          DEFAULT NULL   COMMENT '小队A(squad模式)',
  `team_b_id`       INT UNSIGNED          DEFAULT NULL   COMMENT '小队B(squad模式)',
  `match_time`      DATETIME     NOT NULL                COMMENT '开赛时间',
  `series_format`   VARCHAR(8)   NOT NULL DEFAULT 'bo3'  COMMENT 'bo1/bo3',
  `map_mode`        VARCHAR(8)   NOT NULL DEFAULT 'banpick' COMMENT 'fixed固定/banpick双Ban抽签/random随机',
  `fixed_map_id`    INT UNSIGNED          DEFAULT NULL   COMMENT 'fixed模式指定地图',
  `ban_a_map_id`    INT UNSIGNED          DEFAULT NULL   COMMENT 'a方禁用地图(banpick模式)',
  `ban_b_map_id`    INT UNSIGNED          DEFAULT NULL   COMMENT 'b方禁用地图(banpick模式)',
  `drawn_at`        DATETIME              DEFAULT NULL   COMMENT '抽签时间(banpick模式)',
  `signup_deadline` DATETIME              DEFAULT NULL   COMMENT 'free模式报名截止',
  `status`          VARCHAR(16)  NOT NULL DEFAULT 'signup' COMMENT 'signup报名中/ongoing进行中/finished已结束/canceled已取消',
  `result`          VARCHAR(8)            DEFAULT NULL   COMMENT 'a_win/b_win',
  `result_note`     VARCHAR(200) NOT NULL DEFAULT ''     COMMENT '比分说明',
  `created_by`      INT UNSIGNED NOT NULL                COMMENT '发起人',
  `created_at`      DATETIME              DEFAULT NULL,
  `updated_at`      DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='队内训练赛(v5.6.0)';
-- 训练赛报名
DROP TABLE IF EXISTS `training_signups`;
CREATE TABLE `training_signups` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `match_id`   INT UNSIGNED NOT NULL                COMMENT '训练赛',
  `user_id`    INT UNSIGNED NOT NULL                COMMENT '报名成员',
  `side`       CHAR(1)               DEFAULT NULL   COMMENT '分组后 a/b',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_match_user` (`match_id`, `user_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='训练赛报名(v5.6.0)';
-- 训练赛小局
DROP TABLE IF EXISTS `training_rounds`;
CREATE TABLE `training_rounds` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `match_id`      INT UNSIGNED NOT NULL                COMMENT '训练赛',
  `round_no`      INT UNSIGNED NOT NULL                COMMENT '局序(1起)',
  `map_id`        INT UNSIGNED          DEFAULT NULL   COMMENT '地图',
  `map_name`      VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '地图名快照',
  `attacker_side` CHAR(1)               DEFAULT NULL   COMMENT '攻方a/b',
  `result`        VARCHAR(8)            DEFAULT NULL   COMMENT 'a_win/b_win',
  `result_by`     INT UNSIGNED          DEFAULT NULL   COMMENT '录入人',
  `evidence`      VARCHAR(200)          DEFAULT NULL   COMMENT '结果截图',
  `created_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_match_round` (`match_id`, `round_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='训练赛小局(v5.6.0)';
-- 轻量投票主表
DROP TABLE IF EXISTS `votes`;
CREATE TABLE `votes` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)',
  `title`            VARCHAR(128) NOT NULL                COMMENT '投票标题',
  `multi`            TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '0单选/1多选',
  `max_choices`      INT UNSIGNED NOT NULL DEFAULT 1      COMMENT '多选限选数',
  `anonymous`        TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '是否匿名',
  `allow_add_option` TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '允许成员补充选项',
  `deadline`         DATETIME     NOT NULL                COMMENT '截止时间',
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'open' COMMENT 'open进行中/closed已结束',
  `created_by`       INT UNSIGNED NOT NULL                COMMENT '发起人(管理员)',
  `created_at`       DATETIME              DEFAULT NULL,
  `updated_at`       DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_game_side` (`tenant_id`, `game_side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轻量投票(v5.6.0)';
-- 投票选项
DROP TABLE IF EXISTS `vote_options`;
CREATE TABLE `vote_options` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `vote_id`   INT UNSIGNED NOT NULL                COMMENT '投票',
  `content`   VARCHAR(128) NOT NULL                COMMENT '选项内容',
  `added_by`  INT UNSIGNED          DEFAULT NULL   COMMENT 'NULL=发起时管理员创建',
  `sort`      INT          NOT NULL DEFAULT 0      COMMENT '顺序',
  PRIMARY KEY (`id`),
  KEY `idx_vote` (`vote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投票选项(v5.6.0)';
-- 投票选票
DROP TABLE IF EXISTS `vote_ballots`;
CREATE TABLE `vote_ballots` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `vote_id`    INT UNSIGNED NOT NULL                COMMENT '投票',
  `user_id`    INT UNSIGNED NOT NULL                COMMENT '投票人',
  `option_ids` VARCHAR(128) NOT NULL DEFAULT ''     COMMENT '所选选项ID逗号分隔',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_user` (`vote_id`, `user_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投票选票(v5.6.0)';
-- ------------------------------------------------------------
-- v5 全新安装无任何预置业务数据：
--   战队站点由入驻审核通过时自动开通（建超管 + 默认 settings）
--   唯一预置账号为上方平台超管 admin / admin123
-- ------------------------------------------------------------
-- ------------------------------------------------------------
-- v5.7.0：抽奖大转盘（奖池/奖品/机会/中奖记录）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `lottery_pools`;
CREATE TABLE `lottery_pools` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `name`       VARCHAR(64)  NOT NULL                COMMENT '奖池名称',
  `weight`     INT UNSIGNED NOT NULL DEFAULT 1      COMMENT '奖池权重(两级抽取第一级)',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1开启/0关闭',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖奖池(v5.7.0)';
DROP TABLE IF EXISTS `lottery_prizes`;
CREATE TABLE `lottery_prizes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `pool_id`    INT UNSIGNED NOT NULL                COMMENT '所属奖池',
  `name`       VARCHAR(64)  NOT NULL                COMMENT '奖品名称',
  `weight`     INT UNSIGNED NOT NULL DEFAULT 1      COMMENT '权重(池内第二级抽取)',
  `stock`      INT          NOT NULL DEFAULT -1     COMMENT '库存 -1不限/0抽完',
  `is_thanks`  TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '1=谢谢参与占位',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pool` (`pool_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖奖品(v5.7.0)';
DROP TABLE IF EXISTS `lottery_chances`;
CREATE TABLE `lottery_chances` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `user_id`    INT UNSIGNED NOT NULL                COMMENT '成员',
  `chances`    INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '剩余机会',
  `total`      INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '累计发放',
  `used`       INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '累计消耗',
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖机会余额(v5.7.0)';
DROP TABLE IF EXISTS `lottery_logs`;
CREATE TABLE `lottery_logs` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`      INT UNSIGNED NOT NULL              COMMENT '抽奖人',
  `pool_id`      INT UNSIGNED NOT NULL              COMMENT '命中奖池',
  `prize_id`     INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '命中奖品 0=谢谢参与',
  `prize_name`   VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '奖品名快照',
  `fulfilled`    TINYINT(1)   NOT NULL DEFAULT 0    COMMENT '1已核销发放',
  `fulfilled_by` VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '核销人',
  `fulfilled_at` DATETIME              DEFAULT NULL,
  `created_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖中奖记录(v5.7.0)';
-- ------------------------------------------------------------
-- QQ 官方机器人（v5.8.0）：群绑定验证码 / 群绑定 / 成员绑定
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `bot_group_codes`;
CREATE TABLE `bot_group_codes` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_openid`  VARCHAR(128) NOT NULL              COMMENT 'QQ群openid',
  `code`          CHAR(6)      NOT NULL              COMMENT '6位绑定验证码',
  `member_openid` VARCHAR(128) NOT NULL DEFAULT ''   COMMENT '申请人(QQ成员openid)',
  `expire_at`     INT UNSIGNED NOT NULL              COMMENT '过期时间戳',
  `used_at`       INT UNSIGNED          DEFAULT NULL COMMENT '核销时间戳',
  `created_at`    INT UNSIGNED NOT NULL              COMMENT '生成时间戳',
  PRIMARY KEY (`id`),
  KEY `idx_bgc_group` (`group_openid`),
  KEY `idx_bgc_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ机器人群绑定验证码(v5.8.0)';
DROP TABLE IF EXISTS `bot_group_bindings`;
CREATE TABLE `bot_group_bindings` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `group_openid` VARCHAR(128) NOT NULL              COMMENT 'QQ群openid',
  `bound_by`     INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '绑定操作人(站点用户ID)',
  `created_at`   DATETIME              DEFAULT NULL COMMENT '绑定时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bgb_tenant` (`tenant_id`),
  UNIQUE KEY `uk_bgb_group` (`group_openid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ机器人群绑定(v5.8.0)';
DROP TABLE IF EXISTS `bot_user_bindings`;
CREATE TABLE `bot_user_bindings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `user_id`       INT UNSIGNED NOT NULL              COMMENT '站点用户',
  `member_openid` VARCHAR(128) NOT NULL              COMMENT 'QQ成员openid',
  `created_at`    DATETIME              DEFAULT NULL COMMENT '绑定时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bub_user` (`tenant_id`, `user_id`),
  UNIQUE KEY `uk_bub_openid` (`member_openid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ机器人成员绑定(v5.8.0)';
-- ------------------------------------------------------------
-- QQ 机器人（v5.8.3）：战队级授权 + 调用日志
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `bot_tenant_flags`;
CREATE TABLE `bot_tenant_flags` (
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `enabled`    TINYINT      NOT NULL DEFAULT 0    COMMENT '是否允许使用QQ机器人(1=启用)',
  `updated_at` DATETIME              DEFAULT NULL COMMENT '最近调整时间',
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ机器人战队授权(v5.8.3)';
DROP TABLE IF EXISTS `bot_call_logs`;
CREATE TABLE `bot_call_logs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '所属租户(0=未绑定群)',
  `group_openid`  VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'QQ群openid',
  `member_openid` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'QQ成员openid',
  `command`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '识别出的指令',
  `content`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '原始消息文本',
  `created_at`    DATETIME              DEFAULT NULL COMMENT '调用时间',
  PRIMARY KEY (`id`),
  KEY `idx_bcl_tenant` (`tenant_id`),
  KEY `idx_bcl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ机器人调用日志(v5.8.3)';
-- ------------------------------------------------------------
-- QQ 机器人（v5.9.0）：战队独立机器人配置（多机器人支持）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `bot_tenant_bots`;
CREATE TABLE `bot_tenant_bots` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `app_id`           VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT 'QQ机器人AppID',
  `app_secret`       VARCHAR(128) NOT NULL DEFAULT ''   COMMENT 'QQ机器人AppSecret(明文存储,界面仅回显打码版)',
  `callback_key`     VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '回调密钥(随机生成,用于 /api/bot/callback/{key})',
  `enabled`          TINYINT      NOT NULL DEFAULT 0    COMMENT '战队管理员是否启用(1=启用)',
  `platform_disabled`TINYINT      NOT NULL DEFAULT 0    COMMENT '(v5.28.0 起界面不再使用,列保留)原平台超管紧急停用标志',
  `panel_id`         VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '独立指令面板ID(每个机器人自己的那一份)',
  `env`              VARCHAR(16)  NOT NULL DEFAULT 'prod' COMMENT '接口环境 prod正式 sandbox沙箱(v5.28.0起跟着战队自己走)',
  `created_at`       DATETIME              DEFAULT NULL COMMENT '创建时间',
  `updated_at`       DATETIME              DEFAULT NULL COMMENT '最近调整时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_btb_tenant` (`tenant_id`),
  UNIQUE KEY `uk_btb_callback_key` (`callback_key`),
  KEY `idx_btb_enabled` (`enabled`),
  KEY `idx_btb_platform_disabled` (`platform_disabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='战队机器人配置(v5.9.0)';
-- ------------------------------------------------------------
-- QQ 机器人 AI 聊天（v5.36.14）
--   平台首选模型配置在 settings(tenant_id=0)；这里只存战队级开关、自有模型和 Token 账。
--   AI 只聊天，不提供 tools/function calling，输出永不进入指令分发。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenant_ai_configs`;
CREATE TABLE `tenant_ai_configs` (
  `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`                  INT UNSIGNED NOT NULL              COMMENT '所属租户',
  `mode`                       VARCHAR(16)  NOT NULL DEFAULT 'off' COMMENT 'off关闭 platform平台模型 own自有模型',
  `platform_disabled`          TINYINT      NOT NULL DEFAULT 0    COMMENT '平台停用(1=停用,连自有模型也不能用)',
  `auto_reply`                 TINYINT      NOT NULL DEFAULT 0    COMMENT '全自动接话开关(1=开)',
  `reply_mode`                 VARCHAR(16)  NOT NULL DEFAULT 'at' COMMENT 'at仅@ all全量群消息',
  `bot_alias`                  VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '机器人称呼(群消息里叫到也回)',
  `prompt_preset_id`           INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '选用的平台人设ID(0=平台默认模板)(v5.36.29)',
  `cooldown_seconds`           INT UNSIGNED NOT NULL DEFAULT 60   COMMENT '同群两次 AI 回复最小间隔秒',
  `hourly_limit`               INT UNSIGNED NOT NULL DEFAULT 20   COMMENT '同群每小时最多 AI 回复条数',
  `daily_token_limit`          BIGINT UNSIGNED NOT NULL DEFAULT 100000 COMMENT '每日 Token 上限(0=不限)',
  `chat_probability`           TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT '未命中触发词时闲聊参与概率0-100',
  `sticker_probability`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文字回复后追发表情包概率0-100(0=不发)(v5.36.29)',
  `proactive_enabled`          TINYINT      NOT NULL DEFAULT 0    COMMENT '定时主动发言开关',
  `proactive_interval_minutes` INT UNSIGNED NOT NULL DEFAULT 180  COMMENT '两次主动发言最小间隔分钟',
  `proactive_daily_limit`      TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '每天最多主动发言条数',
  `proactive_start_hour`       TINYINT UNSIGNED NOT NULL DEFAULT 8 COMMENT '静默结束/可发言开始小时',
  `proactive_end_hour`         TINYINT UNSIGNED NOT NULL DEFAULT 23 COMMENT '静默开始/可发言结束小时',
  `own_base_url`               VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '自有模型 OpenAI 兼容 base_url',
  `own_api_key`                VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '自有模型密钥(界面只回显打码版)',
  `own_model`                  VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '自有模型名',
  `token_granted`              BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平台累计发放 Token',
  `token_used`                 BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平台模型累计消耗 Token',
  `daily_used`                 BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '今日已用 Token(平台/自有都计)',
  `daily_date`                 DATE                  DEFAULT NULL COMMENT 'daily_used 对应日期',
  `last_error`                 VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '最后一次失败原因(不含密钥/正文)',
  `last_used_at`               DATETIME              DEFAULT NULL COMMENT '最后一次 AI 调用',
  `last_proactive_at`          DATETIME              DEFAULT NULL COMMENT '最后一次主动发言尝试',
  `proactive_date`             DATE                  DEFAULT NULL COMMENT 'proactive_sent_today 对应日期',
  `proactive_sent_today`       INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '今日已主动发言条数',
  `created_at`                 DATETIME              DEFAULT NULL,
  `updated_at`                 DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tac_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='战队AI聊天配置(v5.36.14)';

DROP TABLE IF EXISTS `ai_token_grants`;
CREATE TABLE `ai_token_grants` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED    NOT NULL              COMMENT '目标租户',
  `amount`        BIGINT          NOT NULL              COMMENT '正数=充值,负数=清零/扣回',
  `balance_after` BIGINT UNSIGNED NOT NULL DEFAULT 0    COMMENT '本次操作后的剩余 Token',
  `note`          VARCHAR(200)    NOT NULL DEFAULT ''   COMMENT '备注',
  `granted_by`    INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '平台超管ID',
  `created_at`    DATETIME                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_atg_tenant` (`tenant_id`),
  KEY `idx_atg_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Token 发放/清零流水(v5.36.14)';

DROP TABLE IF EXISTS `ai_usage_logs`;
CREATE TABLE `ai_usage_logs` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`         INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '所属租户',
  `group_openid`      VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ群openid',
  `member_openid`     VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ成员openid',
  `kind`              VARCHAR(16)     NOT NULL DEFAULT 'at' COMMENT 'at@回复 auto全自动 proactive主动',
  `source`            VARCHAR(16)     NOT NULL DEFAULT 'platform' COMMENT 'platform平台模型 own自有模型',
  `model`             VARCHAR(64)     NOT NULL DEFAULT ''   COMMENT '模型名快照',
  `prompt_tokens`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED    NOT NULL DEFAULT 0,
  `total_tokens`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `duration_ms`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `status`            VARCHAR(16)     NOT NULL DEFAULT 'ok' COMMENT 'ok/fail/send_fail',
  `error`             VARCHAR(200)    NOT NULL DEFAULT ''   COMMENT '失败原因(不含密钥/正文)',
  `created_at`        DATETIME                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aul_tenant` (`tenant_id`, `created_at`),
  KEY `idx_aul_kind` (`kind`),
  KEY `idx_aul_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 调用用量流水(v5.36.14)';

DROP TABLE IF EXISTS `bot_chat_messages`;
CREATE TABLE `bot_chat_messages` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED    NOT NULL              COMMENT '所属租户',
  `group_openid`  VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ群openid',
  `message_id`    VARCHAR(64)     NOT NULL DEFAULT ''   COMMENT 'QQ消息ID(仅作审计)',
  `member_openid` VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ成员openid',
  `role`          VARCHAR(16)     NOT NULL DEFAULT 'user' COMMENT 'user/assistant',
  `content`       VARCHAR(500)    NOT NULL DEFAULT ''   COMMENT '短期上下文正文(自动清理)',
  `created_at`    DATETIME                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bcm_group` (`tenant_id`, `group_openid`, `id`),
  KEY `idx_bcm_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QQ群AI短期上下文(v5.36.14)';

DROP TABLE IF EXISTS `bot_ai_jobs`;
CREATE TABLE `bot_ai_jobs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED    NOT NULL              COMMENT '所属租户',
  `group_openid`   VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ群openid',
  `member_openid`  VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ成员openid',
  `message_id`     VARCHAR(64)              DEFAULT NULL COMMENT 'QQ消息ID(主动发言为NULL)',
  `content`        VARCHAR(500)    NOT NULL DEFAULT ''   COMMENT '待处理消息正文',
  `kind`           VARCHAR(16)     NOT NULL DEFAULT 'at' COMMENT 'at/auto/proactive',
  `status`         VARCHAR(16)     NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/done/failed',
  `attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error`          VARCHAR(200)    NOT NULL DEFAULT ''   COMMENT '失败原因',
  `reply_deadline` DATETIME                 DEFAULT NULL COMMENT '被动回复截止(过期不发,避免误用主动额度)',
  `created_at`     DATETIME                 DEFAULT NULL,
  `started_at`     DATETIME                 DEFAULT NULL,
  `finished_at`    DATETIME                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_baj_msg` (`tenant_id`, `message_id`, `kind`),
  KEY `idx_baj_status` (`status`, `id`),
  KEY `idx_baj_tenant` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 回复任务队列(v5.36.14)';
-- ------------------------------------------------------------
-- AI 人设库与表情包库（v5.36.29）
--   两张都归平台：超管维护，战队只能选人设、调自己发表情包的概率。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `ai_prompt_presets`;
CREATE TABLE `ai_prompt_presets` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(32)   NOT NULL              COMMENT '人设名称(战队下拉里显示)',
  `body`       VARCHAR(4000) NOT NULL              COMMENT '系统提示词正文,支持 {team} {alias}',
  `note`       VARCHAR(200)  NOT NULL DEFAULT ''   COMMENT '给队长看的一句说明',
  `enabled`    TINYINT       NOT NULL DEFAULT 1    COMMENT '1=可选 0=下架(已选的战队自动回落平台默认)',
  `sort`       INT           NOT NULL DEFAULT 0    COMMENT '下拉排序,小的在前',
  `created_by` INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '创建人(平台超管ID)',
  `created_at` DATETIME               DEFAULT NULL,
  `updated_at` DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_name` (`name`),
  KEY `idx_app_enabled` (`enabled`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台角色扮演提示词库(v5.36.29)';

DROP TABLE IF EXISTS `ai_stickers`;
CREATE TABLE `ai_stickers` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(32)   NOT NULL              COMMENT '表情包名称(给 AI 判断该发哪张)',
  `note`       VARCHAR(200)  NOT NULL DEFAULT ''   COMMENT '适用场景(给 AI 判断)',
  `path`       VARCHAR(255)  NOT NULL              COMMENT '站内图片路径 /storage/uploads/stickers/…',
  `bytes`      INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '文件大小(字节)',
  `enabled`    TINYINT       NOT NULL DEFAULT 1    COMMENT '1=可发 0=停用',
  `sort`       INT           NOT NULL DEFAULT 0    COMMENT '排序,小的在前',
  `sent_count` INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '累计发出次数',
  `created_by` INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '上传人(平台超管ID)',
  `created_at` DATETIME               DEFAULT NULL,
  `updated_at` DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_as_name` (`name`),
  KEY `idx_as_enabled` (`enabled`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台表情包库(v5.36.29)';

DROP TABLE IF EXISTS `ai_sticker_logs`;
CREATE TABLE `ai_sticker_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED    NOT NULL              COMMENT '所属租户',
  `group_openid`  VARCHAR(128)    NOT NULL DEFAULT ''   COMMENT 'QQ群openid(界面打码显示)',
  `sticker_id`    INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '表情包ID(0=没图可发)',
  `sticker_name`  VARCHAR(32)     NOT NULL DEFAULT ''   COMMENT '表情包名称快照',
  `pick_mode`     VARCHAR(8)      NOT NULL DEFAULT 'ai' COMMENT 'ai=模型挑的 random=随机',
  `trigger_kind`  VARCHAR(16)     NOT NULL DEFAULT 'at' COMMENT 'at/auto/test',
  `stage`         VARCHAR(16)     NOT NULL DEFAULT 'send' COMMENT 'pick=没图 upload=上传 send=发消息',
  `status`        VARCHAR(16)     NOT NULL DEFAULT 'ok' COMMENT 'ok/fail/skip',
  `error`         VARCHAR(200)    NOT NULL DEFAULT ''   COMMENT '失败原因(不含密钥/聊天正文)',
  `duration_ms`   INT UNSIGNED    NOT NULL DEFAULT 0    COMMENT '整条链路耗时',
  `created_at`    DATETIME                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asl_tenant` (`tenant_id`, `id`),
  KEY `idx_asl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 表情包发送日志(v5.36.30)';
-- ------------------------------------------------------------
-- 强制平台公告（v5.36.31）
--   平台超管写一条，全体已上线成员登录后自动弹出；点「我已知晓」这一条就不再弹，
--   「不再提示」是对以后所有公告静音（那行记成 announcement_id=0），平台可以把这颗钮关掉。
--   已读数只数下面这张表；应读数不落快照，按「已上线成员、排除封禁战队」现算。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `platform_announcements`;
CREATE TABLE `platform_announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(120) NOT NULL              COMMENT '公告标题(弹窗大标题)',
  `content`    TEXT         NOT NULL              COMMENT '公告正文(纯文本,换行原样显示,应用层限 2000 字)',
  `status`     TINYINT      NOT NULL DEFAULT 1    COMMENT '1=在线弹出 0=已下线(下线后不再弹,已读记录保留)',
  `actor_id`   INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '发布人(platform_users.id)',
  `actor_name` VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '发布人登录名快照',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pna_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='强制平台公告(v5.36.31)';
DROP TABLE IF EXISTS `platform_announcement_reads`;
CREATE TABLE `platform_announcement_reads` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `announcement_id` INT UNSIGNED NOT NULL              COMMENT '哪一条公告;0=这个人点了「不再提示」,对以后所有公告静音',
  `user_id`         INT UNSIGNED NOT NULL              COMMENT '哪个人(users.id,全站唯一)',
  `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '点这条的人当时在哪个队(汇总按人现在的队算,这列留档)',
  `choice`          VARCHAR(6)   NOT NULL DEFAULT 'ack' COMMENT 'ack=我已知晓 mute=不再提示',
  `created_at`      DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pnar_ann_user` (`announcement_id`, `user_id`),
  KEY `idx_pnar_ann` (`announcement_id`),
  KEY `idx_pnar_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='强制平台公告送达确认(v5.36.31)';
-- ------------------------------------------------------------
-- 战队销毁登记（v5.36.32）
--   被封满 15 天的站点，平台超管可以整队销毁：库里这个 tenant 的行全删、
--   它引用的图片按引用删掉、tenants 那一行也删。删之前强制打一个备份包
--   （全部行 + 图片文件）落 runtime/data_tasks，这张表就是那个备份包唯一的登记处。
--   跨队约战（battles）不在这张表的账里：那场约战留一半给对面，战队名回显成「已注销战队」。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tenant_purges`;
CREATE TABLE `tenant_purges` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED  NOT NULL              COMMENT '被销毁的租户ID(销毁后 tenants 里这行已不在)',
  `slug`         VARCHAR(30)   NOT NULL              COMMENT '目录名快照(之后可被新站重新占用)',
  `name`         VARCHAR(64)   NOT NULL DEFAULT ''   COMMENT '战队名快照',
  `member_count` INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '销毁时在册成员数',
  `row_count`    INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '销毁时删掉的数据库行数(各表合计)',
  `file_count`   INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '销毁时删掉的图片文件数',
  `bytes`        BIGINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '销毁时这一站占的存储字节数',
  `banned_at`    DATETIME               DEFAULT NULL COMMENT '本次封禁起点(15 天那条线的依据)',
  `banned_days`  INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '确认销毁那天已经封满的天数',
  `backup_file`  VARCHAR(255)  NOT NULL DEFAULT ''   COMMENT '备份包文件名(只在 runtime/data_tasks 里,不走 web 直出)',
  `backup_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '备份包字节数',
  `actor_id`     INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '操作人(platform_users.id)',
  `actor_name`   VARCHAR(64)   NOT NULL DEFAULT ''   COMMENT '操作人登录名快照',
  `created_at`   DATETIME               DEFAULT NULL COMMENT '销毁完成时间',
  PRIMARY KEY (`id`),
  KEY `idx_tpurged_tenant` (`tenant_id`),
  KEY `idx_tpurged_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='战队销毁登记(v5.36.32,备份包唯一凭据)';
-- ------------------------------------------------------------
-- v5.10.0：平台运营与合规增强包
--   Cookie 同意日志 / API 请求日志 / 死链检测
--   （SEO / 隐私政策 / 服务条款 / Sitemap / 图片压缩配置 复用 settings 表 tenant_id=0，无新表）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `cookie_consent`;
CREATE TABLE `cookie_consent` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '所属租户(0=平台)',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '登录用户ID(未登录为0)',
  `user_hash`   CHAR(64)     NOT NULL DEFAULT ''   COMMENT '匿名用户标识(sha256(IP+UA))',
  `choice`      VARCHAR(16)  NOT NULL DEFAULT ''   COMMENT 'accepted/rejected/necessary_only',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '用户IP',
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT ''   COMMENT 'User-Agent前255字符',
  `policy_ver`  VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '同意时的政策版本号',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cc_tenant_user` (`tenant_id`, `user_id`, `user_hash`),
  KEY `idx_cc_tenant` (`tenant_id`),
  KEY `idx_cc_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cookie同意日志(v5.10.0)';
DROP TABLE IF EXISTS `api_logs`;
CREATE TABLE `api_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key_id`   INT UNSIGNED NOT NULL DEFAULT 0    COMMENT 'api_keys.id(未命中或鉴权失败为0)',
  `key_prefix`   VARCHAR(16)  NOT NULL DEFAULT ''   COMMENT 'Key前8位前缀(便于溯源)',
  `scope`        VARCHAR(16)  NOT NULL DEFAULT ''   COMMENT 'platform/tenant/external',
  `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '请求归属租户(平台=0)',
  `method`       VARCHAR(8)   NOT NULL DEFAULT ''   COMMENT 'HTTP方法',
  `path`         VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '请求路径(不含查询串)',
  `status`       SMALLINT     NOT NULL DEFAULT 0    COMMENT 'HTTP响应码',
  `result`       TINYINT      NOT NULL DEFAULT 0    COMMENT '1=成功 0=鉴权失败',
  `fail_reason`  VARCHAR(128) NOT NULL DEFAULT ''   COMMENT '鉴权失败原因',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT ''   COMMENT '客户端IP',
  `duration_ms`  INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '处理耗时毫秒',
  `created_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_al_key` (`api_key_id`),
  KEY `idx_al_tenant` (`tenant_id`),
  KEY `idx_al_created` (`created_at`),
  KEY `idx_al_result` (`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API请求日志(v5.10.0)';
DROP TABLE IF EXISTS `broken_links`;
CREATE TABLE `broken_links` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '链接所在租户(0=平台)',
  `url`          VARCHAR(500) NOT NULL              COMMENT '目标URL',
  `url_hash`     CHAR(64)     NOT NULL              COMMENT 'URL的sha256(去重用)',
  `source_type`  VARCHAR(32)  NOT NULL DEFAULT ''   COMMENT '来源类型(gun_code/announcement/guide/chat/manual)',
  `source_id`    INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '来源记录ID',
  `source_ref`   VARCHAR(128) NOT NULL DEFAULT ''   COMMENT '来源摘要',
  `status_code`  SMALLINT     NOT NULL DEFAULT 0    COMMENT '最近检查的HTTP状态码',
  `error_msg`    VARCHAR(255) NOT NULL DEFAULT ''   COMMENT '错误信息',
  `hit_count`    INT UNSIGNED NOT NULL DEFAULT 1    COMMENT '连续失败次数',
  `last_ok_at`   DATETIME              DEFAULT NULL,
  `last_check_at`DATETIME              DEFAULT NULL,
  `resolved`     TINYINT      NOT NULL DEFAULT 0    COMMENT '1=已标记解决',
  `resolved_by`  INT UNSIGNED NOT NULL DEFAULT 0,
  `resolved_at`  DATETIME              DEFAULT NULL,
  `created_at`   DATETIME              DEFAULT NULL,
  `updated_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bl_tenant_url` (`tenant_id`, `url_hash`),
  KEY `idx_bl_tenant` (`tenant_id`),
  KEY `idx_bl_resolved` (`resolved`),
  KEY `idx_bl_last_check` (`last_check_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='死链检测记录(v5.10.0)';
-- v5.10.0：平台级默认设置（SEO / 隐私政策 / 服务条款 / Cookie 同意 / Sitemap / 图片压缩）
INSERT IGNORE INTO `settings` (`tenant_id`, `key`, `value`) VALUES
  (0, 'seo_site_title',       '战队入驻平台'),
  (0, 'seo_site_description', '基于三角洲行动的多租户游戏战队管理与入驻平台，提供战队公示、成员协作、约战赛事、QQ机器人等一站式服务。'),
  (0, 'seo_keywords',         '三角洲行动,战队管理,游戏战队,战队入驻,约战系统,QQ机器人'),
  (0, 'seo_og_image',         ''),
  (0, 'seo_canonical_base',   ''),
  (0, 'policies_privacy',     ''),
  (0, 'policies_terms',       ''),
  (0, 'policies_updated_at',  ''),
  (0, 'cookie_consent_enabled','1'),
  (0, 'cookie_consent_text',  '本网站使用必要的 Cookie 保障登录与安全，并在您同意后使用统计类 Cookie 改善体验。详见隐私政策。'),
  (0, 'sitemap_enabled',      '1'),
  (0, 'sitemap_last_generated',''),
  (0, 'broken_link_last_scan',''),
  (0, 'image_compress_enabled','1'),
  (0, 'image_compress_quality','82'),
  (0, 'image_compress_max_width','1920'),
  (0, 'stats_api_enabled',    '1'),
  (0, 'stats_upload_window',  '60'),
  (0, 'stats_upload_limit',   '10'),
  (0, 'stats_ip_upload_limit','60'),
  (0, 'image_compress_max_height','1920');
-- ============================================================
-- v5.11.0 成员战绩信息（成员各自用账号密码登录后经接口上传，站内只读展示）
-- 与 database/upgrade_v5.10.0_to_v5.11.0.sql 保持一致
-- 可见性：仅持 stats 权限的管理员 + 本人；20 个指标列全部可空，
-- NULL = 该项未填（前端显示「—」）；一次上传覆盖整行，UNIQUE(tenant_id,user_id) 保证一人一行。
-- ============================================================
DROP TABLE IF EXISTS `player_stats`;
CREATE TABLE `player_stats` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`           INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `user_id`             INT UNSIGNED NOT NULL                COMMENT '成员用户ID',
  `game_pid`            VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '游戏内ID/昵称(成员自报，供管理员核对)',
  `matches`             INT UNSIGNED          DEFAULT NULL   COMMENT '场次',
  `win_rate`            DECIMAL(5,2)          DEFAULT NULL   COMMENT '胜率%(0-100)',
  `avg_score`           DECIMAL(10,2)         DEFAULT NULL   COMMENT '平均评分',
  `kd`                  DECIMAL(10,2)         DEFAULT NULL   COMMENT 'KD',
  `score_per_match`     DECIMAL(10,2)         DEFAULT NULL   COMMENT '场均得分',
  `score_per_minute`    DECIMAL(10,2)         DEFAULT NULL   COMMENT '分均得分',
  `kills`               INT UNSIGNED          DEFAULT NULL   COMMENT '总击杀',
  `deaths`              INT UNSIGNED          DEFAULT NULL   COMMENT '总死亡',
  `kda`                 DECIMAL(10,2)         DEFAULT NULL   COMMENT 'KDA',
  `kills_per_match`     DECIMAL(10,2)         DEFAULT NULL   COMMENT '场均击杀',
  `assists_per_match`   DECIMAL(10,2)         DEFAULT NULL   COMMENT '场均助攻',
  `sites_per_match`     DECIMAL(10,2)         DEFAULT NULL   COMMENT '场均站点',
  `healing_per_match`   DECIMAL(10,2)         DEFAULT NULL   COMMENT '场均治疗',
  `revives`             INT UNSIGNED          DEFAULT NULL   COMMENT '救治',
  `best_match_kills`    INT UNSIGNED          DEFAULT NULL   COMMENT '单局最高击杀',
  `kill_duration`       INT UNSIGNED          DEFAULT NULL   COMMENT '击杀时长(秒)',
  `quit_rate`           DECIMAL(5,2)          DEFAULT NULL   COMMENT '中途退出率%(0-100)',
  `max_win_streak`      INT UNSIGNED          DEFAULT NULL   COMMENT '最长连胜',
  `win_rate_last10`     DECIMAL(5,2)          DEFAULT NULL   COMMENT '近10局胜率%(0-100)',
  `win_rate_last30`     DECIMAL(5,2)          DEFAULT NULL   COMMENT '近30局胜率%(0-100)',
  `created_at`          DATETIME              DEFAULT NULL   COMMENT '首次上传时间',
  `updated_at`          DATETIME              DEFAULT NULL   COMMENT '最近上传时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_ps_game_pid` (`game_pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='成员战绩(v5.11.0)';
-- ------------------------------------------------------------
-- 站内信（v5.22.0 上线；v5.32.0 交流论坛与全站大厅下线后，这一处独立留着）
--   表名仍叫 forum_notices：线上库里它有真实数据，改名要动库与全部 DDL 拷贝，换来的只有名字好听。
--   现在里面只剩两档：举报处理结果、平台邮件群发给你的那一条。
--   头像一律「色块 + 昵称首字」，不接 QQ 头像：users.username 就是 QQ 号，
--   把它换算成任何头像地址下发给成员，等于把全站成员的 QQ 号批量交出去。
-- ------------------------------------------------------------
-- ------------------------------------------------------------
-- 站内信：一个「红点 + 通知列表」装两档 —— 举报处理结果 / 平台邮件群发给你的那一条
--   平台通知写 dedupe='blast:批次:人'，所以同一批重复点发送也只给这个人记一条；
--   举报结果那一档留 NULL，MySQL 唯一索引不拦 NULL，所以同一件事反复有结果能各记一行。
DROP TABLE IF EXISTS `forum_notices`;
CREATE TABLE `forum_notices` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_user_id`   INT UNSIGNED NOT NULL              COMMENT '收通知的人(users.id)',
  `from_user_id` INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '谁触发的(@的人/处理人),0=系统',
  `kind`         VARCHAR(16)  NOT NULL              COMMENT 'mention被@ removed被下架 restored已恢复 report举报有结果',
  `post_id`      INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '跳到哪条帖(点通知直达)',
  `comment_id`   INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '@出现在哪条评论里,0=正文或整帖',
  `note`         VARCHAR(200) NOT NULL DEFAULT ''   COMMENT '要告诉本人的话(下架理由、驳回说明都走这里)',
  `dedupe`       VARCHAR(64)           DEFAULT NULL COMMENT '去重指纹,只有 mention 写;NULL 表示允许重复记',
  `read_at`      DATETIME              DEFAULT NULL COMMENT '本人看过时间,NULL=顶栏红点还计着',
  `created_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_notice` (`dedupe`),
  KEY `idx_forum_notice_to` (`to_user_id`, `read_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站内信(v5.22.0 起;v5.32.0 论坛与大厅下线后只剩举报结果与平台通知两档)';
-- ------------------------------------------------------------
-- 个人黑名单 · 举报与禁言归平台（v5.30.1 上线；v5.32.0 全站大厅那两张表已删）
--   user_blocks：个人黑名单。每人最多 10 个，只能拉其它战队的账号；
--     只对拉黑者本人生效：你看不到他的私聊、他也进不到你的主页，两人私聊互相发不出去。
--   私聊这一版放开到跨队，所以上面 chat_dm_messages 补了三个不带 tenant_id 的索引，
--     chat_dm_prefs 多一列 allow_stranger（是否允许其它战队的人先开口）。
--   users.muted_by：禁言来源。platform 给出的禁言战队队长解不掉，team 的仍由本队自己解。
--   users.show_public / stats_public 的默认值改成 1，与「四个隐私开关默认开」对齐；
--     存量账号由 database/upgrade_v5.29.0_to_v5.30.1.sql 里的 UPDATE 一起刷成开。
-- ------------------------------------------------------------
-- 个人黑名单：A 拉黑 B 只影响 A 自己的视角，B 不会被告知，也不影响其它任何人
DROP TABLE IF EXISTS `user_blocks`;
CREATE TABLE `user_blocks` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`       INT UNSIGNED NOT NULL           COMMENT '拉黑者所属租户',
  `user_id`         INT UNSIGNED NOT NULL           COMMENT '拉黑者(users.id)',
  `blocked_user_id` INT UNSIGNED NOT NULL           COMMENT '被拉黑者(只能是其它战队的账号)',
  `created_at`      DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_block_pair` (`user_id`, `blocked_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='个人黑名单(v5.30.1,每人最多10个,只拉其它战队的人)';
-- ------------------------------------------------------------
-- v5.31.0 平台邮件群发（只平台超管可用）
--   mail_blasts / mail_blast_items：运营在平台后台自己写正文，一次群发给各战队的
--     站长/联络人和全部已上线成员。发信用平台自己配的那一套（settings 里 p_mail_* 那些键，
--     跟战队自己的 smtp_* 完全分开，战队碰不到也读不到）。
--   每一封同时补一条站内信（forum_notices 里 kind=platform，dedupe='blast:批次:人'），
--     所以邮箱填得不对的人照样收得到通知；哪几封没送出去，明细里如实记着原因。
--   发出去几封/失败几封/站内信几条都从明细表数，批次表不另存计数器（免得两边对不上）。
--   队列靠「先认领再发」防重发：pending → sending → sent/failed/skipped。
--     页面关了只是暂停，回来接着跑；卡在 sending 的那几封由页面上的按钮显式重排。
--   收件地址就是注册时那个 QQ号@qq.com（这一版不给个人中心加「自己填邮箱」）。
--   正文按「混着来自动判」处理：只有整篇看起来是一封 HTML 邮件才当 HTML 发，
--     否则一律按纯文本（换行转段落、网址变链接），写了一半标签不会发成乱码。
-- ------------------------------------------------------------
-- 群发批次：一次发送一行，写了什么、发给哪两组、谁点的都记在这
DROP TABLE IF EXISTS `mail_blasts`;
CREATE TABLE `mail_blasts` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `subject`     VARCHAR(200)  NOT NULL             COMMENT '邮件标题(运营自己写)',
  `body`        TEXT          NOT NULL             COMMENT '正文原样存一份(发送与事后回看都读这一份)',
  `is_html`     TINYINT       NOT NULL DEFAULT 0    COMMENT '1=这封按HTML发(只有整篇像HTML文档才判成1,标签写一半的按纯文本发)',
  `recv_groups` VARCHAR(32)   NOT NULL DEFAULT ''   COMMENT '收件分组逗号分隔: owner各战队站长/联络人 member全部已上线成员',
  `target`      INT           NOT NULL DEFAULT 0    COMMENT '计划收件人数(建批时按分组算好落库)',
  `status`      VARCHAR(12)   NOT NULL DEFAULT 'queued' COMMENT 'queued待跑 running发送中 done全发完 stopped手动停了',
  `actor_id`    INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '发起人(platform_users.id)',
  `actor_name`  VARCHAR(64)   NOT NULL DEFAULT ''   COMMENT '发起人登录名(冗余一份,日志被清了也认得出是谁发的)',
  `created_at`  DATETIME              DEFAULT NULL,
  `started_at`  DATETIME              DEFAULT NULL   COMMENT '第一封被认领的时间',
  `finished_at` DATETIME              DEFAULT NULL   COMMENT '发完或手动停止的时间',
  PRIMARY KEY (`id`),
  KEY `idx_blast_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台邮件群发批次(v5.31.0,只平台超管可用)';
-- 逐封明细：一封一行。先认领(sending)再发，发完写回 sent/failed/skipped，重发就靠这个状态
DROP TABLE IF EXISTS `mail_blast_items`;
CREATE TABLE `mail_blast_items` (
  `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `blast_id`  INT UNSIGNED  NOT NULL              COMMENT '所属批次(mail_blasts.id)',
  `uid`       INT UNSIGNED  NOT NULL              COMMENT '收件人(users.id,全站唯一;站长同时也是成员时只留一行)',
  `tenant_id` INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '这个人当时属于哪个队(只给明细列表显示队名)',
  `email`     VARCHAR(128)  NOT NULL DEFAULT ''   COMMENT '收件地址(注册时那个 QQ号@qq.com,只有平台超管在这个页面看得到)',
  `who`       VARCHAR(8)    NOT NULL DEFAULT 'member' COMMENT 'owner该队站长/联络人 member普通已上线成员',
  `status`    VARCHAR(10)   NOT NULL DEFAULT 'pending' COMMENT 'pending待发 sending已认领在飞 sent发出去了 failed连不上或被拒 skipped地址不对没发',
  `notice`    TINYINT       NOT NULL DEFAULT 0    COMMENT '1=站内信也补了一条(邮箱无效的人靠这个看到通知)',
  `err`       VARCHAR(200)  NOT NULL DEFAULT ''   COMMENT '失败原因(邮件服务器回的那句,去掉账号密码后存)',
  `sent_at`   DATETIME              DEFAULT NULL   COMMENT '这一封跑完的时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blast_uid` (`blast_id`, `uid`),
  KEY `idx_blast_pick` (`blast_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件群发逐封明细(v5.31.0,先认领再发送防重发)';

-- ----------------------------
-- 赛前提醒（v5.33.0）
--   event_reminders：一类事件推过就记一行，下次扫到同一场不再推（一场只推一条公告）；
--   约战是两个队各推各的，所以唯一键带上 tenant_id；group_ok=0 只代表 QQ 群那一路没送到
--   （官方 2025-04 起限制机器人主动消息），公告与站内信不受它影响。
-- ----------------------------
DROP TABLE IF EXISTS `event_reminders`;
CREATE TABLE `event_reminders` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`      VARCHAR(16)  NOT NULL             COMMENT 'training训练赛 schedule队内日程 battle跨队约战',
  `ref_id`    INT UNSIGNED NOT NULL             COMMENT '事件表里的行号(training_matches/custom_events/battles.id)',
  `tenant_id` INT UNSIGNED NOT NULL             COMMENT '推给哪个队(约战两个队各记一行,自己推自己的)',
  `event_at`  DATETIME     NOT NULL             COMMENT '开打时间(推的那一刻照抄一份,事后对得上)',
  `lead_min`  SMALLINT     NOT NULL DEFAULT 30  COMMENT '当时设的提前几分钟',
  `group_ok`  TINYINT      NOT NULL DEFAULT 0   COMMENT '1=QQ群里也收到了 0=只落了公告与站内信',
  `sent_at`   DATETIME     NOT NULL             COMMENT '推出去的时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remind_kind_ref` (`kind`, `ref_id`, `tenant_id`),
  KEY `idx_remind_tenant` (`tenant_id`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='赛前提醒去重(v5.33.0,一场只推一次)';

-- ----------------------------
-- 比赛日集合点名（v5.36.34）
--   roll_calls：队长建一张点名单（几点集合、几点截止），可挂一场 race_events。
--   roll_call_replies：一人一行，改口是整行覆盖（与战绩同一口径）。
--   notify_at 非空 = QQ 群那条提醒已经发过，扫的时候不再发第二条；
--   revoked_at 非空 = 成员端不再展示，但回执留着，队长还看得到。
-- ----------------------------
DROP TABLE IF EXISTS `roll_calls`;
CREATE TABLE `roll_calls` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `game_side`  VARCHAR(8)   NOT NULL DEFAULT ''  COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0 口径)',
  `title`      VARCHAR(128) NOT NULL             COMMENT '点名标题(挂比赛时默认带比赛名)',
  `event_id`   INT UNSIGNED NOT NULL DEFAULT 0   COMMENT '关联 race_events.id(0=不挂比赛,只是集合)',
  `call_at`    DATETIME     NOT NULL             COMMENT '集合时间',
  `deadline`   DATETIME              DEFAULT NULL COMMENT '点名截止(过了只算没回,不再收改口)',
  `notify_at`  DATETIME              DEFAULT NULL COMMENT 'QQ群提醒发出去的时间(非空=不再重发)',
  `revoked_at` DATETIME              DEFAULT NULL COMMENT '撤回时间(非空=成员端不再展示)',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0   COMMENT '创建人(users.id,队长)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rc_tenant_call` (`tenant_id`, `call_at`),
  KEY `idx_rc_due` (`notify_at`, `call_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='比赛日集合点名单(v5.36.34)';
DROP TABLE IF EXISTS `roll_call_replies`;
CREATE TABLE `roll_call_replies` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `call_id`    INT UNSIGNED NOT NULL             COMMENT 'roll_calls.id',
  `tenant_id`  INT UNSIGNED NOT NULL             COMMENT '所属租户(冗余一份,按队统计不用回表)',
  `user_id`    INT UNSIGNED NOT NULL             COMMENT '回点名的成员(users.id)',
  `reply`      VARCHAR(8)   NOT NULL DEFAULT ''  COMMENT 'in到 late迟到 out不来',
  `note`       VARCHAR(128) NOT NULL DEFAULT ''  COMMENT '附一句(可选,比如"路上,晚十分钟")',
  `replied_at` DATETIME     NOT NULL             COMMENT '最近一次点/改口的时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rcr_call_user` (`call_id`, `user_id`),
  KEY `idx_rcr_call` (`call_id`),
  KEY `idx_rcr_tenant` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集合点名回执(v5.36.34,一人一行改口覆盖)';
