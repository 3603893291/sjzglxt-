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
  `expires_at` DATETIME              DEFAULT NULL COMMENT '过期时间(默认签发后2小时)',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='隐身巡查令牌(v5.2.0)';

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
  `admin_perms` VARCHAR(512) NOT NULL DEFAULT ''    COMMENT '管理员权限JSON(v3.9:超管分配)',
  `role_tags`  VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '职责标签ID数组JSON,第一个为默认展示(v5.3.0)',
  `session_epoch` INT UNSIGNED NOT NULL DEFAULT 0   COMMENT '密码纪元:改密+1,旧会话强制登出(v5.7.1)',
  `last_active_at` DATETIME          DEFAULT NULL   COMMENT '最后活跃时间(在线统计)',
  `created_at` DATETIME              DEFAULT NULL   COMMENT '注册时间',
  `updated_at` DATETIME              DEFAULT NULL   COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_username` (`tenant_id`, `username`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_team` (`team_id`),
  KEY `idx_status` (`status`),
  KEY `idx_active` (`last_active_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表(战队系统成员)';

-- 战队小队表
DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL             COMMENT '所属租户',
  `name`        VARCHAR(64)  NOT NULL              COMMENT '小队名称',
  `description` VARCHAR(500) NOT NULL DEFAULT ''   COMMENT '小队简介',
  `leader_id`   INT UNSIGNED          DEFAULT NULL COMMENT '队长用户ID',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name` (`tenant_id`, `name`),
  KEY `idx_tenant` (`tenant_id`)
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
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
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
  `title`      VARCHAR(128) NOT NULL,
  `content`    TEXT         NOT NULL,
  `image`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '配图(可选)',
  `need_receipt` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否需要已读回执(v5.6.0)',
  `deadline`     DATETIME              DEFAULT NULL COMMENT '回执截止(可选,v5.6.0)',
  `reminded_at`  DATETIME              DEFAULT NULL COMMENT '一键提醒未读时间(v5.6.0)',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告表';

-- 撞车比赛时间表
DROP TABLE IF EXISTS `race_events`;
CREATE TABLE `race_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `title`      VARCHAR(128) NOT NULL DEFAULT '撞车比赛',
  `race_time`  DATETIME     NOT NULL COMMENT '比赛时间点',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_time` (`race_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='撞车比赛时间表';

-- 自建房比赛日程表（v3.9.6）
DROP TABLE IF EXISTS `custom_race_events`;
CREATE TABLE `custom_race_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `title`      VARCHAR(128) NOT NULL DEFAULT '自建房比赛',
  `race_time`  DATETIME     NOT NULL COMMENT '比赛时间点',
  `created_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_time` (`race_time`)
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
  `title`        VARCHAR(128) NOT NULL                COMMENT '活动标题',
  `start_time`   DATETIME     NOT NULL                COMMENT '开始时间',
  `duration_min` INT UNSIGNED NOT NULL DEFAULT 60     COMMENT '时长(分钟)',
  `note`         VARCHAR(500) NOT NULL DEFAULT ''     COMMENT '说明(地点/房间号/备注)',
  `created_at`   DATETIME              DEFAULT NULL,
  `updated_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日程自定义活动(v5.6.0)';

-- 值班配置
DROP TABLE IF EXISTS `duty_configs`;
CREATE TABLE `duty_configs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL                COMMENT '所属租户',
  `duty_type`  VARCHAR(16)  NOT NULL DEFAULT 'room_host' COMMENT '值班类型(首期room_host房主值班)',
  `cycle`      VARCHAR(8)   NOT NULL DEFAULT 'day'  COMMENT '轮换周期 day按天/week按周',
  `anchor_date` DATE        NOT NULL                COMMENT '轮换锚点日期(第0周期)',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '是否启用',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_type` (`tenant_id`, `duty_type`)
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
  KEY `idx_status` (`status`)
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
  KEY `idx_tenant` (`tenant_id`)
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
