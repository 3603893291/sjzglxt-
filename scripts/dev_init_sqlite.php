<?php
/**
 * 本地开发辅助脚本：初始化 SQLite 测试库（v5 结构，目录制多租户）
 * 用途：在没有 MySQL 的机器上快速跑通系统（结构/数据与 database/game_team.sql v5 等价）
 * 用法：php scripts/dev_init_sqlite.php <sqlite文件路径>
 * 注意：生产环境请使用 MySQL 5.7 并导入 database/game_team.sql
 */
$path = $argv[1] ?? null;
if (!$path) {
    fwrite(STDERR, "用法: php scripts/dev_init_sqlite.php <sqlite文件路径>\n");
    exit(1);
}
@unlink($path);
$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<'SQL'
CREATE TABLE platform_users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  username   VARCHAR(32)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  nickname   VARCHAR(32)  NOT NULL DEFAULT '',
  role       VARCHAR(16)  NOT NULL DEFAULT 'team',
  status     VARCHAR(16)  NOT NULL DEFAULT 'normal',
  reg_ip     VARCHAR(45)  NOT NULL DEFAULT '',
  login_alert_seen_at DATETIME     DEFAULT NULL,
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_pu_role ON platform_users (role);
CREATE TABLE tenants (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  applicant_id   INTEGER      NOT NULL,
  slug           VARCHAR(30)  NOT NULL UNIQUE,
  name           VARCHAR(64)  NOT NULL,
  logo           VARCHAR(255) NOT NULL DEFAULT '',
  description    VARCHAR(500) NOT NULL DEFAULT '',
  admin_username VARCHAR(32)  NOT NULL DEFAULT '',
  admin_user_id  INTEGER               DEFAULT NULL,
  status         VARCHAR(16)  NOT NULL DEFAULT 'approved',
  banned_at      DATETIME              DEFAULT NULL,
  storage_quota  INTEGER      NOT NULL DEFAULT 0,
  created_at     DATETIME              DEFAULT NULL,
  updated_at     DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tenants_applicant ON tenants (applicant_id);
CREATE TABLE tenant_transfers (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER      NOT NULL,
  from_uid      INTEGER      NOT NULL,
  to_uid        INTEGER      NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
  cooling_until DATETIME              DEFAULT NULL,
  created_at    DATETIME              DEFAULT NULL,
  updated_at    DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tt_tenant ON tenant_transfers (tenant_id);
CREATE INDEX idx_tt_to ON tenant_transfers (to_uid);
CREATE TABLE platform_login_logs (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  platform_user_id INTEGER     NOT NULL DEFAULT 0,
  username         VARCHAR(32) NOT NULL DEFAULT '',
  ip               VARCHAR(45) NOT NULL DEFAULT '',
  region           VARCHAR(64) NOT NULL DEFAULT '',
  result           INTEGER     NOT NULL DEFAULT 0,
  via              VARCHAR(12) NOT NULL DEFAULT 'pwd',
  flagged          INTEGER     NOT NULL DEFAULT 0,
  created_at       DATETIME             DEFAULT NULL
);
CREATE INDEX idx_pll_user ON platform_login_logs (platform_user_id);
CREATE TABLE tenant_notices (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  content    VARCHAR(500) NOT NULL,
  read_at    DATETIME              DEFAULT NULL,
  created_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tn_tenant ON tenant_notices (tenant_id);
-- v5.2.0：隐身巡查令牌签发登记（仅平台超管可签发，校验只认库内登记）
CREATE TABLE stealth_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER     NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  created_by INTEGER     NOT NULL DEFAULT 0,
  created_at DATETIME             DEFAULT NULL,
  expires_at DATETIME             DEFAULT NULL
);
CREATE INDEX idx_st_tenant ON stealth_tokens (tenant_id);
CREATE INDEX idx_st_hash ON stealth_tokens (token_hash);
-- v5.36.13：平台协作（队长授权 / 一次性入口票据 / 协作会话）
CREATE TABLE support_grants (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL UNIQUE,
  granted_by INTEGER      NOT NULL DEFAULT 0,
  granted_at DATETIME              DEFAULT NULL,
  revoked_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE TABLE support_sessions (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id        INTEGER     NOT NULL,
  platform_user_id INTEGER     NOT NULL DEFAULT 0,
  started_at       DATETIME             DEFAULT NULL,
  last_seen_at     DATETIME             DEFAULT NULL,
  expires_at       DATETIME             DEFAULT NULL,
  ended_at         DATETIME             DEFAULT NULL,
  ip               VARCHAR(45) NOT NULL DEFAULT '',
  ua               VARCHAR(200) NOT NULL DEFAULT ''
);
CREATE INDEX idx_ss_tenant ON support_sessions (tenant_id);
CREATE INDEX idx_ss_platform ON support_sessions (platform_user_id);
CREATE TABLE support_tickets (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id        INTEGER     NOT NULL,
  platform_user_id INTEGER     NOT NULL DEFAULT 0,
  token_hash       VARCHAR(64) NOT NULL UNIQUE,
  created_at       DATETIME             DEFAULT NULL,
  expires_at       DATETIME             DEFAULT NULL,
  used_at          DATETIME             DEFAULT NULL
);
CREATE INDEX idx_stk_tenant ON support_tickets (tenant_id);
-- v5.17.0：管理员登录日志（只记 role=admin 的成功与失败尝试；flagged=1 为新归属地登录需提醒）
CREATE TABLE login_logs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER     NOT NULL,
  user_id    INTEGER     NOT NULL DEFAULT 0,
  username   VARCHAR(32) NOT NULL DEFAULT '',
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  region     VARCHAR(128) NOT NULL DEFAULT '',
  ua         VARCHAR(200) NOT NULL DEFAULT '',
  via        VARCHAR(12) NOT NULL DEFAULT 'pwd',
  result     VARCHAR(8)  NOT NULL DEFAULT 'success',
  flagged    INTEGER     NOT NULL DEFAULT 0,
  created_at DATETIME             DEFAULT NULL
);
CREATE INDEX idx_ll_tenant ON login_logs (tenant_id, id);
CREATE INDEX idx_ll_user ON login_logs (tenant_id, user_id);
CREATE INDEX idx_ll_flag ON login_logs (tenant_id, flagged);
-- 对外授权登录旧表（v5.18.0 引入，v5.36.7 已下线；仅保留历史数据，代码不再读写）
CREATE TABLE oauth_apps (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  app_id        VARCHAR(32)  NOT NULL,
  secret_hash   VARCHAR(64)  NOT NULL,
  secret_prefix VARCHAR(8)   NOT NULL DEFAULT '',
  name          VARCHAR(64)  NOT NULL,
  domain        VARCHAR(128) NOT NULL DEFAULT '',
  redirect_uris TEXT,
  contact       VARCHAR(64)  NOT NULL DEFAULT '',
  remark        VARCHAR(255) NOT NULL DEFAULT '',
  status        INTEGER      NOT NULL DEFAULT 1,
  created_by    INTEGER               DEFAULT NULL,
  last_used_at  DATETIME              DEFAULT NULL,
  created_at    DATETIME              DEFAULT NULL,
  updated_at    DATETIME              DEFAULT NULL,
  UNIQUE (app_id)
);
-- 授权同意关系（账号 × 应用一行；撤销只置 revoked_at，不删行）
CREATE TABLE oauth_grants (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  app_ref    INTEGER NOT NULL,
  tenant_id  INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  created_at DATETIME         DEFAULT NULL,
  used_at    DATETIME         DEFAULT NULL,
  revoked_at DATETIME         DEFAULT NULL,
  UNIQUE (app_ref, tenant_id, user_id)
);
CREATE INDEX idx_og_user ON oauth_grants (tenant_id, user_id);
-- 授权码（一次性，5 分钟；redirect_uri 落库供换令牌时再比一次）
CREATE TABLE oauth_auth_codes (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  code_hash    VARCHAR(64)  NOT NULL,
  app_ref      INTEGER      NOT NULL,
  tenant_id    INTEGER      NOT NULL,
  user_id      INTEGER      NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL,
  expires_at   DATETIME              DEFAULT NULL,
  used_at      DATETIME              DEFAULT NULL
);
CREATE INDEX idx_oc_hash ON oauth_auth_codes (code_hash);
CREATE INDEX idx_oc_app  ON oauth_auth_codes (app_ref, created_at);
-- 访问令牌（10 分钟，只给服务端到服务端取用户信息）
CREATE TABLE oauth_access_tokens (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash   VARCHAR(64) NOT NULL,
  app_ref      INTEGER     NOT NULL,
  tenant_id    INTEGER     NOT NULL,
  user_id      INTEGER     NOT NULL,
  code_id      INTEGER              DEFAULT NULL,
  ip           VARCHAR(45) NOT NULL DEFAULT '',
  created_at   DATETIME             DEFAULT NULL,
  expires_at   DATETIME             DEFAULT NULL,
  revoked_at   DATETIME             DEFAULT NULL,
  last_used_at DATETIME             DEFAULT NULL
);
CREATE INDEX idx_ot_hash  ON oauth_access_tokens (token_hash);
CREATE INDEX idx_ot_user  ON oauth_access_tokens (tenant_id, user_id);
CREATE INDEX idx_ot_app   ON oauth_access_tokens (app_ref, created_at);
-- 调用审计（全站最多 5000 行，写入时顺带裁剪；凭据原文不入 detail）
CREATE TABLE oauth_logs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  app_ref     INTEGER     NOT NULL DEFAULT 0,
  app_name    VARCHAR(64) NOT NULL DEFAULT '',
  tenant_id   INTEGER     NOT NULL DEFAULT 0,
  tenant_name VARCHAR(64) NOT NULL DEFAULT '',
  user_id     INTEGER     NOT NULL DEFAULT 0,
  qq          VARCHAR(32) NOT NULL DEFAULT '',
  action      VARCHAR(24) NOT NULL DEFAULT '',
  result      VARCHAR(8)  NOT NULL DEFAULT 'success',
  detail      VARCHAR(300) NOT NULL DEFAULT '',
  ip          VARCHAR(45) NOT NULL DEFAULT '',
  created_at  DATETIME             DEFAULT NULL
);
CREATE INDEX idx_ol_app    ON oauth_logs (app_ref, id);
CREATE INDEX idx_ol_tenant ON oauth_logs (tenant_id, id);
CREATE INDEX idx_ol_user   ON oauth_logs (tenant_id, user_id);
-- ------------------------------------------------------------
-- 一对一私聊(v5.19.0：聊天室拆成「战队群 + 私聊」)
--   正文仅当事双方可读；管理员默认只有元数据，被举报的会话才允许展开正文
--   与 database/game_team.sql 的同名表结构等价(SQLite 方言)
-- ------------------------------------------------------------
CREATE TABLE chat_dm_messages (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER  NOT NULL,
  from_user_id INTEGER  NOT NULL,
  to_user_id   INTEGER  NOT NULL,
  pair_key     VARCHAR(32) NOT NULL DEFAULT '',
  content      VARCHAR(500) NOT NULL,
  recalled     INTEGER  NOT NULL DEFAULT 0,
  recalled_at  DATETIME          DEFAULT NULL,
  created_at   DATETIME          DEFAULT NULL
);
CREATE INDEX idx_dm_pair ON chat_dm_messages (tenant_id, pair_key, id);
CREATE INDEX idx_dm_to   ON chat_dm_messages (tenant_id, to_user_id, recalled, id);
CREATE INDEX idx_dm_pair_g ON chat_dm_messages (pair_key, id);
CREATE INDEX idx_dm_from   ON chat_dm_messages (from_user_id, id);
CREATE INDEX idx_dm_to_g   ON chat_dm_messages (to_user_id, id);
CREATE TABLE chat_dm_reads (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER  NOT NULL,
  user_id      INTEGER  NOT NULL,
  peer_id      INTEGER  NOT NULL,
  last_read_id INTEGER  NOT NULL DEFAULT 0,
  updated_at   DATETIME          DEFAULT NULL
);
CREATE UNIQUE INDEX uk_dm_read ON chat_dm_reads (tenant_id, user_id, peer_id);
CREATE TABLE chat_dm_prefs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  allow_dm   INTEGER NOT NULL DEFAULT 1,
  allow_stranger INTEGER NOT NULL DEFAULT 1,
  updated_at DATETIME         DEFAULT NULL
);
CREATE UNIQUE INDEX uk_dm_pref ON chat_dm_prefs (tenant_id, user_id);
CREATE TABLE chat_reports (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER NOT NULL,
  pair_key    VARCHAR(32) NOT NULL,
  message_id  INTEGER NOT NULL DEFAULT 0,
  reporter_id INTEGER NOT NULL,
  peer_id     INTEGER NOT NULL DEFAULT 0,
  reason      VARCHAR(200) NOT NULL DEFAULT '',
  status      VARCHAR(12) NOT NULL DEFAULT 'pending',
  created_at  DATETIME         DEFAULT NULL,
  reviewed_by INTEGER          DEFAULT NULL,
  reviewed_at DATETIME         DEFAULT NULL,
  result_note VARCHAR(200) NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX uk_report_msg ON chat_reports (tenant_id, message_id);
CREATE INDEX idx_report_status ON chat_reports (tenant_id, status, id);
CREATE INDEX idx_report_pair   ON chat_reports (tenant_id, pair_key);
-- 展示页条目(v5.20.0)：kind=gallery 图集 / honor 荣誉墙；visible=0 只隐藏不删
CREATE TABLE showcase_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  kind       VARCHAR(12)  NOT NULL,
  title      VARCHAR(64)  NOT NULL DEFAULT '',
  note       VARCHAR(200) NOT NULL DEFAULT '',
  image      VARCHAR(160) NOT NULL DEFAULT '',
  sort       INTEGER      NOT NULL DEFAULT 0,
  visible    INTEGER      NOT NULL DEFAULT 1,
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_show_item ON showcase_items (tenant_id, kind, visible, sort);
-- 展示页投稿与入队意向(v5.20.0)：type=post 内容投稿(通过才上墙) / intent 入队意向
CREATE TABLE show_submissions (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  type        VARCHAR(8)   NOT NULL DEFAULT 'intent',
  qq          VARCHAR(16)  NOT NULL DEFAULT '',
  nick        VARCHAR(32)  NOT NULL DEFAULT '',
  position    VARCHAR(32)  NOT NULL DEFAULT '',
  content     VARCHAR(500) NOT NULL,
  image       VARCHAR(160) NOT NULL DEFAULT '',
  status      VARCHAR(12)  NOT NULL DEFAULT 'pending',
  result_note VARCHAR(200) NOT NULL DEFAULT '',
  reviewed_by INTEGER               DEFAULT NULL,
  reviewed_at DATETIME              DEFAULT NULL,
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  region      VARCHAR(128) NOT NULL DEFAULT '',
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL
);
CREATE INDEX idx_show_sub     ON show_submissions (tenant_id, type, status, id);
CREATE INDEX idx_show_sub_ip  ON show_submissions (tenant_id, ip);
-- 站内信（v5.22.0；v5.32.0 论坛与公共聊天区下线后只剩「举报结果」与「平台通知」两档，表名不动）
CREATE TABLE forum_notices (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  to_user_id   INTEGER      NOT NULL,
  from_user_id INTEGER      NOT NULL DEFAULT 0,
  kind         VARCHAR(16)  NOT NULL,
  post_id      INTEGER      NOT NULL DEFAULT 0,
  comment_id   INTEGER      NOT NULL DEFAULT 0,
  note         VARCHAR(200) NOT NULL DEFAULT '',
  dedupe       VARCHAR(64)           DEFAULT NULL,
  read_at      DATETIME              DEFAULT NULL,
  created_at   DATETIME              DEFAULT NULL,
  UNIQUE (dedupe)
);
CREATE INDEX idx_forum_notice_to ON forum_notices (to_user_id, read_at, id);
-- v5.2.0：站点数据导入导出任务（提交后需平台超管审核同意才执行）
CREATE TABLE tenant_data_tasks (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  type        VARCHAR(8)   NOT NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'pending',
  file        VARCHAR(255) NOT NULL DEFAULT '',
  note        VARCHAR(200) NOT NULL DEFAULT '',
  created_at  DATETIME              DEFAULT NULL,
  reviewed_at DATETIME              DEFAULT NULL,
  reviewed_by INTEGER               DEFAULT NULL,
  finished_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tdt_tenant ON tenant_data_tasks (tenant_id);
CREATE INDEX idx_tdt_status ON tenant_data_tasks (status);
CREATE TABLE tenant_applications (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  applicant_id   INTEGER      NOT NULL,
  slug           VARCHAR(30)  NOT NULL,
  name           VARCHAR(64)  NOT NULL,
  logo           VARCHAR(255) NOT NULL DEFAULT '',
  description    VARCHAR(500) NOT NULL DEFAULT '',
  admin_username VARCHAR(32)  NOT NULL,
  admin_password VARCHAR(255) NOT NULL,
  screenshot     VARCHAR(255) NOT NULL DEFAULT '',
  status         VARCHAR(16)  NOT NULL DEFAULT 'pending',
  reject_reason  VARCHAR(200) NOT NULL DEFAULT '',
  created_at     DATETIME              DEFAULT NULL,
  updated_at     DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tapp_status ON tenant_applications (status);
CREATE INDEX idx_tapp_applicant ON tenant_applications (applicant_id);
CREATE INDEX idx_tapp_slug ON tenant_applications (slug);
CREATE TABLE users (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  username     VARCHAR(32)  NOT NULL,
  password     VARCHAR(255) NOT NULL,
  nickname     VARCHAR(32)  NOT NULL DEFAULT '',
  email        VARCHAR(64)  NOT NULL DEFAULT '',
  profile_image VARCHAR(255) NOT NULL DEFAULT '',
  reg_ip       VARCHAR(45)  NOT NULL DEFAULT '',
  reg_region   VARCHAR(128) NOT NULL DEFAULT '',
  role         VARCHAR(16)  NOT NULL DEFAULT 'user',
  team_id      INTEGER               DEFAULT NULL,
  status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
  muted        INTEGER      NOT NULL DEFAULT 0,
  muted_by     VARCHAR(8)   NOT NULL DEFAULT '',
  admin_perms  VARCHAR(512) NOT NULL DEFAULT '',
  role_tags    VARCHAR(255) NOT NULL DEFAULT '',
  session_epoch INTEGER     NOT NULL DEFAULT 0,
  last_active_at DATETIME            DEFAULT NULL,
  login_alert_seen_at DATETIME       DEFAULT NULL,
  show_public    INTEGER      NOT NULL DEFAULT 1,
  stats_public   INTEGER      NOT NULL DEFAULT 1,
  game_side      VARCHAR(8)   NOT NULL DEFAULT '',
  game_side_lock VARCHAR(8)   NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, username)
);
CREATE INDEX idx_users_game_side ON users (tenant_id, game_side);
CREATE INDEX idx_users_tenant ON users (tenant_id);
CREATE INDEX idx_users_team ON users (team_id);
CREATE INDEX idx_users_status ON users (status);
CREATE INDEX idx_users_active ON users (last_active_at);
CREATE TABLE passport_links (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  user_id      INTEGER      NOT NULL,
  passport_sub VARCHAR(64)  NOT NULL,
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, passport_sub),
  UNIQUE (tenant_id, user_id)
);
CREATE INDEX idx_passport_user ON passport_links (user_id);
CREATE TABLE teams (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  game_side   VARCHAR(8)   NOT NULL DEFAULT '',
  name        VARCHAR(64)  NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  leader_id   INTEGER               DEFAULT NULL,
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, name)
);
CREATE INDEX idx_teams_game_side ON teams (tenant_id, game_side);
CREATE INDEX idx_teams_tenant ON teams (tenant_id);
CREATE TABLE images (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  user_id     INTEGER      NOT NULL,
  title       VARCHAR(128) NOT NULL DEFAULT '',
  file        VARCHAR(255) NOT NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'pending',
  reviewed_by INTEGER               DEFAULT NULL,
  reviewed_at DATETIME              DEFAULT NULL,
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL
);
CREATE INDEX idx_images_status ON images (status);
CREATE INDEX idx_images_user ON images (user_id);
CREATE TABLE gun_codes (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  user_id     INTEGER      NOT NULL,
  title       VARCHAR(128) NOT NULL,
  code        TEXT         NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status      VARCHAR(16)  NOT NULL DEFAULT 'published',
  category    VARCHAR(32)  NOT NULL DEFAULT 'other',
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL
);
CREATE INDEX idx_gc_user ON gun_codes (user_id);
CREATE INDEX idx_gc_status ON gun_codes (status);
CREATE INDEX idx_gc_tenant ON gun_codes (tenant_id);
CREATE INDEX idx_gc_category ON gun_codes (tenant_id, category);
CREATE TABLE gun_code_comments (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  gun_code_id INTEGER      NOT NULL,
  user_id     INTEGER      NOT NULL,
  content     VARCHAR(300) NOT NULL,
  created_at  DATETIME              DEFAULT NULL
);
CREATE INDEX idx_gcc_gc ON gun_code_comments (gun_code_id);
CREATE INDEX idx_gcc_user ON gun_code_comments (user_id);
CREATE TABLE blocked_ips (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ip         VARCHAR(45)  NOT NULL UNIQUE,
  reason     VARCHAR(128) NOT NULL DEFAULT '',
  blocked_by INTEGER               DEFAULT NULL,
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE TABLE chat_messages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  user_id     INTEGER      NOT NULL,
  content     VARCHAR(500) NOT NULL,
  recalled    INTEGER      NOT NULL DEFAULT 0,
  recalled_by INTEGER               DEFAULT NULL,
  created_at  DATETIME              DEFAULT NULL
);
CREATE TABLE settings (
  tenant_id INTEGER     NOT NULL,
  key   VARCHAR(64) NOT NULL,
  value TEXT,
  PRIMARY KEY (tenant_id, key)
);
CREATE TABLE email_codes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  email      VARCHAR(64) NOT NULL,
  code       VARCHAR(8)  NOT NULL,
  used       INTEGER     NOT NULL DEFAULT 0,
  expired_at DATETIME    NOT NULL,
  created_at DATETIME             DEFAULT NULL
);
CREATE INDEX idx_ec_email ON email_codes (email, used, expired_at);
CREATE TABLE announcements (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  game_side    VARCHAR(8)   NOT NULL DEFAULT '',
  title        VARCHAR(128) NOT NULL,
  content      TEXT         NOT NULL,
  image        VARCHAR(255) NOT NULL DEFAULT '',
  need_receipt INTEGER      NOT NULL DEFAULT 0,
  deadline     DATETIME              DEFAULT NULL,
  reminded_at  DATETIME              DEFAULT NULL,
  scheduled_at DATETIME              DEFAULT NULL,
  revoked_at   DATETIME              DEFAULT NULL,
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL
);
CREATE INDEX idx_ann_game_side ON announcements (tenant_id, game_side);
CREATE TABLE race_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  game_side  VARCHAR(8)   NOT NULL DEFAULT '',
  title      VARCHAR(128) NOT NULL DEFAULT '撞车比赛',
  race_time  DATETIME     NOT NULL,
  created_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_race_game_side ON race_events (tenant_id, game_side);
CREATE INDEX idx_race_time ON race_events (race_time);
CREATE TABLE custom_race_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  game_side  VARCHAR(8)   NOT NULL DEFAULT '',
  title      VARCHAR(128) NOT NULL DEFAULT '自建房比赛',
  race_time  DATETIME     NOT NULL,
  created_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_custom_race_game_side ON custom_race_events (tenant_id, game_side);
CREATE INDEX idx_custom_race_time ON custom_race_events (race_time);
CREATE TABLE guides (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  user_id    INTEGER      NOT NULL,
  title      VARCHAR(128) NOT NULL,
  content    TEXT         NOT NULL,
  image      VARCHAR(255) NOT NULL DEFAULT '',
  status     VARCHAR(16)  NOT NULL DEFAULT 'published',
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_guides_user ON guides (user_id);
CREATE INDEX idx_guides_status ON guides (status);
CREATE TABLE admin_logs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  admin_id   INTEGER      NOT NULL,
  admin_name VARCHAR(64)  NOT NULL DEFAULT '',
  action     VARCHAR(64)  NOT NULL,
  detail     VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_alog_admin ON admin_logs (admin_id);
CREATE TABLE rotation_maps (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  name       VARCHAR(64)  NOT NULL,
  image      VARCHAR(255) NOT NULL DEFAULT '',
  sort       INTEGER      NOT NULL DEFAULT 0,
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE TABLE rotation_days (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  name       VARCHAR(32)  NOT NULL DEFAULT '',
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE TABLE rotation_day_maps (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER   NOT NULL,
  day_id INTEGER NOT NULL,
  map_id INTEGER NOT NULL,
  UNIQUE (day_id, map_id)
);
CREATE INDEX idx_rdm_map ON rotation_day_maps (map_id);
-- v5.3.0：职责标签库（管理员维护，名称+自定义颜色）
CREATE TABLE role_tags (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER     NOT NULL,
  name       VARCHAR(16) NOT NULL,
  color      CHAR(7)     NOT NULL DEFAULT '#0f766e',
  created_at DATETIME             DEFAULT NULL,
  updated_at DATETIME             DEFAULT NULL,
  UNIQUE (tenant_id, name)
);
CREATE INDEX idx_rt_tenant ON role_tags (tenant_id);
-- v5.3.0：7天免密码登录令牌（只存 sha256）
CREATE TABLE remember_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER    NOT NULL,
  user_id    INTEGER    NOT NULL,
  token_hash CHAR(64)   NOT NULL,
  created_at DATETIME            DEFAULT NULL,
  expires_at DATETIME            DEFAULT NULL
);
CREATE INDEX idx_rtk_tenant_user ON remember_tokens (tenant_id, user_id);
CREATE INDEX idx_rtk_hash ON remember_tokens (token_hash);
-- v5.7.1：忘记密码申请（新密码密文 + 主页截图佐证，管理员审核后生效；每用户同时仅一条待审）
CREATE TABLE password_resets (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  user_id      INTEGER      NOT NULL,
  new_password VARCHAR(255) NOT NULL,
  screenshot   VARCHAR(255) NOT NULL DEFAULT '',
  request_ip   VARCHAR(45)  NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, user_id)
);
CREATE INDEX idx_pwrst_tenant ON password_resets (tenant_id);
-- v5.3.0：每日签到（每人每天一条，含连续天数与今日运势表情包）
CREATE TABLE checkins (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER  NOT NULL,
  user_id      INTEGER  NOT NULL,
  checkin_date DATE     NOT NULL,
  streak       INTEGER  NOT NULL DEFAULT 1,
  fortune_img  INTEGER  NOT NULL DEFAULT 0,
  created_at   DATETIME          DEFAULT NULL,
  UNIQUE (tenant_id, user_id, checkin_date)
);
CREATE INDEX idx_ck_tenant_date ON checkins (tenant_id, checkin_date);
-- v5.4.0：跨战队约战（平台级：无租户列）
CREATE TABLE battles (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  title           VARCHAR(50)  NOT NULL DEFAULT '',
  game_mode       VARCHAR(50)  NOT NULL DEFAULT '',
  battle_time     DATETIME     NOT NULL,
  room_info       VARCHAR(200) NOT NULL DEFAULT '',
  remark          VARCHAR(500) NOT NULL DEFAULT '',
  challenger_type VARCHAR(8)   NOT NULL,
  challenger_id   INTEGER      NOT NULL,
  defender_type   VARCHAR(8)   NOT NULL,
  defender_id     INTEGER      NOT NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'pending',
  battle_type     VARCHAR(10)  NOT NULL DEFAULT 'official',
  series_format   VARCHAR(8)   NOT NULL DEFAULT 'bo3',
  roster_limit    INTEGER      NOT NULL DEFAULT 20,
  ban_a_map_id    INTEGER               DEFAULT NULL,
  ban_b_map_id    INTEGER               DEFAULT NULL,
  drawn_at        DATETIME              DEFAULT NULL,
  adv_side        CHAR(1)               DEFAULT NULL,
  adv_reason      VARCHAR(120) NOT NULL DEFAULT '',
  adv_role        VARCHAR(10)           DEFAULT NULL,
  result          VARCHAR(8)            DEFAULT NULL,
  result_note     VARCHAR(200) NOT NULL DEFAULT '',
  result_by       INTEGER               DEFAULT NULL,
  created_by      VARCHAR(64)  NOT NULL DEFAULT '',
  accepted_by     VARCHAR(64)  NOT NULL DEFAULT '',
  canceled_by     VARCHAR(64)  NOT NULL DEFAULT '',
  finished_at     DATETIME              DEFAULT NULL,
  created_at      DATETIME              DEFAULT NULL,
  updated_at      DATETIME              DEFAULT NULL
);
CREATE INDEX idx_battles_status ON battles (status);
CREATE INDEX idx_battles_ch ON battles (challenger_type, challenger_id);
CREATE INDEX idx_battles_df ON battles (defender_type, defender_id);
CREATE TABLE battle_signups (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  battle_id        INTEGER     NOT NULL,
  side             CHAR(1)     NOT NULL,
  signup_type      VARCHAR(8)  NOT NULL DEFAULT 'user',
  user_id          INTEGER              DEFAULT NULL,
  display_name     VARCHAR(32) NOT NULL DEFAULT '',
  tenant_id        INTEGER              DEFAULT NULL,
  external_team_id INTEGER              DEFAULT NULL,
  manual           INTEGER     NOT NULL DEFAULT 0,
  created_at       DATETIME             DEFAULT NULL
);
CREATE UNIQUE INDEX uk_signup_user ON battle_signups (battle_id, side, user_id);
CREATE INDEX idx_bsignups_battle ON battle_signups (battle_id);
CREATE TABLE battle_rounds (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  battle_id     INTEGER     NOT NULL,
  round_no      INTEGER     NOT NULL,
  map_id        INTEGER              DEFAULT NULL,
  map_name      VARCHAR(64) NOT NULL DEFAULT '',
  attacker_side CHAR(1)              DEFAULT NULL,
  result        VARCHAR(8)           DEFAULT NULL,
  result_by     INTEGER              DEFAULT NULL,
  evidence      VARCHAR(200)         DEFAULT NULL,
  attack_secs   INTEGER              DEFAULT NULL,
  occupy_count  INTEGER              DEFAULT NULL,
  occupy_ratio  INTEGER              DEFAULT NULL,
  created_at    DATETIME             DEFAULT NULL,
  UNIQUE (battle_id, round_no)
);
CREATE TABLE external_teams (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         VARCHAR(50)  NOT NULL,
  contact_name VARCHAR(50)  NOT NULL DEFAULT '',
  remark       VARCHAR(200) NOT NULL DEFAULT '',
  status       INTEGER      NOT NULL DEFAULT 1,
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL
);
CREATE TABLE api_keys (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  key_hash         CHAR(64)    NOT NULL,
  key_prefix       VARCHAR(8)  NOT NULL DEFAULT '',
  name             VARCHAR(64) NOT NULL DEFAULT '',
  scope            VARCHAR(16) NOT NULL,
  tenant_id        INTEGER              DEFAULT NULL,
  external_team_id INTEGER              DEFAULT NULL,
  bound_user_id    INTEGER              DEFAULT NULL,
  status           INTEGER     NOT NULL DEFAULT 1,
  created_by       INTEGER              DEFAULT NULL,
  last_used_at     DATETIME             DEFAULT NULL,
  created_at       DATETIME             DEFAULT NULL
);
CREATE INDEX idx_apikeys_hash ON api_keys (key_hash);
-- v5.6.0：六项功能新表
CREATE TABLE blacklist (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  qq         VARCHAR(16)  NOT NULL,
  nickname   VARCHAR(32)  NOT NULL DEFAULT '',
  reason     VARCHAR(200) NOT NULL,
  created_by VARCHAR(64)  NOT NULL DEFAULT '',
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, qq)
);
CREATE TABLE announcement_reads (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  announcement_id INTEGER NOT NULL,
  user_id         INTEGER NOT NULL,
  reminded        INTEGER NOT NULL DEFAULT 0,
  created_at      DATETIME         DEFAULT NULL,
  UNIQUE (announcement_id, user_id)
);
CREATE TABLE custom_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  game_side    VARCHAR(8)   NOT NULL DEFAULT '',
  title        VARCHAR(128) NOT NULL,
  start_time   DATETIME     NOT NULL,
  duration_min INTEGER      NOT NULL DEFAULT 60,
  note         VARCHAR(500) NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL
);
CREATE INDEX idx_ce_game_side ON custom_events (tenant_id, game_side);
CREATE INDEX idx_ce_time ON custom_events (start_time);
CREATE TABLE duty_configs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER     NOT NULL,
  game_side   VARCHAR(8)  NOT NULL DEFAULT '',
  duty_type   VARCHAR(16) NOT NULL DEFAULT 'room_host',
  cycle       VARCHAR(8)  NOT NULL DEFAULT 'day',
  anchor_date DATE        NOT NULL,
  enabled     INTEGER     NOT NULL DEFAULT 1,
  created_at  DATETIME             DEFAULT NULL,
  updated_at  DATETIME             DEFAULT NULL,
  UNIQUE (tenant_id, duty_type, game_side)
);
CREATE INDEX idx_duty_game_side ON duty_configs (tenant_id, game_side);
CREATE TABLE duty_members (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  config_id  INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  sort       INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME         DEFAULT NULL,
  UNIQUE (config_id, user_id)
);
CREATE TABLE duty_swaps (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER     NOT NULL,
  config_id   INTEGER     NOT NULL,
  duty_date   DATE        NOT NULL,
  from_uid    INTEGER     NOT NULL,
  to_uid      INTEGER     NOT NULL,
  status      VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at  DATETIME             DEFAULT NULL,
  resolved_at DATETIME             DEFAULT NULL
);
CREATE TABLE training_matches (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER      NOT NULL,
  game_side       VARCHAR(8)   NOT NULL DEFAULT '',
  title           VARCHAR(50)  NOT NULL DEFAULT '',
  mode            VARCHAR(16)  NOT NULL DEFAULT 'squad',
  team_a_id       INTEGER               DEFAULT NULL,
  team_b_id       INTEGER               DEFAULT NULL,
  match_time      DATETIME     NOT NULL,
  series_format   VARCHAR(8)   NOT NULL DEFAULT 'bo3',
  map_mode        VARCHAR(8)   NOT NULL DEFAULT 'banpick',
  fixed_map_id    INTEGER               DEFAULT NULL,
  signup_deadline DATETIME              DEFAULT NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'signup',
  result          VARCHAR(8)            DEFAULT NULL,
  result_note     VARCHAR(200) NOT NULL DEFAULT '',
  created_by      INTEGER      NOT NULL,
  created_at      DATETIME              DEFAULT NULL,
  updated_at      DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tm_game_side ON training_matches (tenant_id, game_side);
CREATE TABLE training_signups (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  match_id   INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  side       CHAR(1)          DEFAULT NULL,
  created_at DATETIME         DEFAULT NULL,
  UNIQUE (match_id, user_id)
);
CREATE TABLE training_rounds (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER     NOT NULL,
  match_id      INTEGER     NOT NULL,
  round_no      INTEGER     NOT NULL,
  map_id        INTEGER              DEFAULT NULL,
  map_name      VARCHAR(64) NOT NULL DEFAULT '',
  attacker_side CHAR(1)              DEFAULT NULL,
  result        VARCHAR(8)           DEFAULT NULL,
  result_by     INTEGER              DEFAULT NULL,
  evidence      VARCHAR(200)         DEFAULT NULL,
  created_at    DATETIME             DEFAULT NULL,
  UNIQUE (match_id, round_no)
);
CREATE TABLE votes (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id        INTEGER      NOT NULL,
  game_side        VARCHAR(8)   NOT NULL DEFAULT '',
  title            VARCHAR(128) NOT NULL,
  multi            INTEGER      NOT NULL DEFAULT 0,
  max_choices      INTEGER      NOT NULL DEFAULT 1,
  anonymous        INTEGER      NOT NULL DEFAULT 0,
  allow_add_option INTEGER      NOT NULL DEFAULT 0,
  deadline         DATETIME     NOT NULL,
  status           VARCHAR(16)  NOT NULL DEFAULT 'open',
  created_by       INTEGER      NOT NULL,
  created_at       DATETIME              DEFAULT NULL,
  updated_at       DATETIME              DEFAULT NULL
);
CREATE INDEX idx_vote_game_side ON votes (tenant_id, game_side);
CREATE TABLE vote_options (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER      NOT NULL,
  vote_id   INTEGER      NOT NULL,
  content   VARCHAR(128) NOT NULL,
  added_by  INTEGER               DEFAULT NULL,
  sort      INTEGER      NOT NULL DEFAULT 0
);
CREATE TABLE vote_ballots (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  vote_id    INTEGER      NOT NULL,
  user_id    INTEGER      NOT NULL,
  option_ids VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME              DEFAULT NULL,
  UNIQUE (vote_id, user_id)
);
-- v5.7.0：抽奖大转盘（奖池/奖品/机会/中奖记录）
CREATE TABLE lottery_pools (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER     NOT NULL,
  name       VARCHAR(64) NOT NULL,
  weight     INTEGER     NOT NULL DEFAULT 1,
  enabled    INTEGER     NOT NULL DEFAULT 1,
  created_at DATETIME             DEFAULT NULL,
  updated_at DATETIME             DEFAULT NULL
);
CREATE INDEX idx_lpool_tenant ON lottery_pools (tenant_id);
CREATE TABLE lottery_prizes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER     NOT NULL,
  pool_id    INTEGER     NOT NULL,
  name       VARCHAR(64) NOT NULL,
  weight     INTEGER     NOT NULL DEFAULT 1,
  stock      INTEGER     NOT NULL DEFAULT -1,
  is_thanks  INTEGER     NOT NULL DEFAULT 0,
  created_at DATETIME             DEFAULT NULL,
  updated_at DATETIME             DEFAULT NULL
);
CREATE INDEX idx_lprize_pool ON lottery_prizes (pool_id);
CREATE TABLE lottery_chances (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  chances    INTEGER NOT NULL DEFAULT 0,
  total      INTEGER NOT NULL DEFAULT 0,
  used       INTEGER NOT NULL DEFAULT 0,
  updated_at DATETIME         DEFAULT NULL,
  UNIQUE (tenant_id, user_id)
);
CREATE TABLE lottery_logs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER     NOT NULL,
  user_id      INTEGER     NOT NULL,
  pool_id      INTEGER     NOT NULL,
  prize_id     INTEGER     NOT NULL DEFAULT 0,
  prize_name   VARCHAR(64) NOT NULL DEFAULT '',
  fulfilled    INTEGER     NOT NULL DEFAULT 0,
  fulfilled_by VARCHAR(64) NOT NULL DEFAULT '',
  fulfilled_at DATETIME             DEFAULT NULL,
  created_at   DATETIME             DEFAULT NULL
);
CREATE INDEX idx_llog_tenant ON lottery_logs (tenant_id);
CREATE INDEX idx_llog_user ON lottery_logs (user_id);
-- v5.8.0：QQ 官方机器人（群绑定验证码 / 群绑定 / 成员绑定）
CREATE TABLE bot_group_codes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  group_openid  VARCHAR(128) NOT NULL,
  code          CHAR(6)      NOT NULL,
  member_openid VARCHAR(128) NOT NULL DEFAULT '',
  expire_at     INTEGER      NOT NULL,
  used_at       INTEGER               DEFAULT NULL,
  created_at    INTEGER      NOT NULL
);
CREATE INDEX idx_bgc_group ON bot_group_codes (group_openid);
CREATE INDEX idx_bgc_code ON bot_group_codes (code);
CREATE TABLE bot_group_bindings (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL UNIQUE,
  group_openid VARCHAR(128) NOT NULL UNIQUE,
  bound_by     INTEGER      NOT NULL DEFAULT 0,
  created_at   DATETIME              DEFAULT NULL
);
CREATE TABLE bot_user_bindings (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER      NOT NULL,
  user_id       INTEGER      NOT NULL,
  member_openid VARCHAR(128) NOT NULL UNIQUE,
  created_at    DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, user_id)
);
-- v5.8.3：战队级机器人授权（平台超管控制哪个战队可用；新战队默认停用）
CREATE TABLE bot_tenant_flags (
  tenant_id  INTEGER PRIMARY KEY,
  enabled    INTEGER  NOT NULL DEFAULT 0,
  updated_at DATETIME          DEFAULT NULL
);
-- v5.9.0：战队机器人配置（多机器人支持）
CREATE TABLE bot_tenant_bots (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id        INTEGER      NOT NULL,
  app_id           VARCHAR(64)  NOT NULL DEFAULT '',
  app_secret       VARCHAR(128) NOT NULL DEFAULT '',
  callback_key     VARCHAR(32)  NOT NULL DEFAULT '',
  enabled          INTEGER      NOT NULL DEFAULT 0,
  platform_disabled INTEGER     NOT NULL DEFAULT 0,
  panel_id         VARCHAR(64)  NOT NULL DEFAULT '',
  env              VARCHAR(16)  NOT NULL DEFAULT 'prod',
  created_at       DATETIME              DEFAULT NULL,
  updated_at       DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id),
  UNIQUE (callback_key)
);
-- v5.8.3：机器人调用日志（平台超管可查看/清空）
CREATE TABLE bot_call_logs (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER      NOT NULL DEFAULT 0,
  group_openid  VARCHAR(128) NOT NULL DEFAULT '',
  member_openid VARCHAR(128) NOT NULL DEFAULT '',
  command       VARCHAR(32)  NOT NULL DEFAULT '',
  content       VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME              DEFAULT NULL
);
CREATE INDEX idx_bcl_tenant ON bot_call_logs (tenant_id);
CREATE INDEX idx_bcl_created ON bot_call_logs (created_at);
-- v5.36.14：QQ 机器人 AI 聊天（战队配置 / Token 账 / 用量 / 短期上下文 / 任务队列）
CREATE TABLE tenant_ai_configs (
  id                         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id                  INTEGER      NOT NULL UNIQUE,
  mode                       VARCHAR(16)  NOT NULL DEFAULT 'off',
  platform_disabled          INTEGER      NOT NULL DEFAULT 0,
  auto_reply                 INTEGER      NOT NULL DEFAULT 0,
  reply_mode                 VARCHAR(16)  NOT NULL DEFAULT 'at',
  bot_alias                  VARCHAR(32)  NOT NULL DEFAULT '',
  prompt_preset_id           INTEGER      NOT NULL DEFAULT 0,
  cooldown_seconds           INTEGER      NOT NULL DEFAULT 60,
  hourly_limit               INTEGER      NOT NULL DEFAULT 20,
  daily_token_limit          INTEGER      NOT NULL DEFAULT 100000,
  chat_probability           INTEGER      NOT NULL DEFAULT 20,
  sticker_probability        INTEGER      NOT NULL DEFAULT 0,
  proactive_enabled          INTEGER      NOT NULL DEFAULT 0,
  proactive_interval_minutes INTEGER      NOT NULL DEFAULT 180,
  proactive_daily_limit      INTEGER      NOT NULL DEFAULT 2,
  proactive_start_hour       INTEGER      NOT NULL DEFAULT 8,
  proactive_end_hour         INTEGER      NOT NULL DEFAULT 23,
  own_base_url               VARCHAR(255) NOT NULL DEFAULT '',
  own_api_key                VARCHAR(255) NOT NULL DEFAULT '',
  own_model                  VARCHAR(64)  NOT NULL DEFAULT '',
  token_granted              INTEGER      NOT NULL DEFAULT 0,
  token_used                 INTEGER      NOT NULL DEFAULT 0,
  daily_used                 INTEGER      NOT NULL DEFAULT 0,
  daily_date                 DATE                  DEFAULT NULL,
  last_error                 VARCHAR(255) NOT NULL DEFAULT '',
  last_used_at               DATETIME              DEFAULT NULL,
  last_proactive_at          DATETIME              DEFAULT NULL,
  proactive_date             DATE                  DEFAULT NULL,
  proactive_sent_today       INTEGER      NOT NULL DEFAULT 0,
  created_at                 DATETIME              DEFAULT NULL,
  updated_at                 DATETIME              DEFAULT NULL
);
CREATE TABLE ai_token_grants (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER      NOT NULL,
  amount        INTEGER      NOT NULL,
  balance_after INTEGER      NOT NULL DEFAULT 0,
  note          VARCHAR(200) NOT NULL DEFAULT '',
  granted_by    INTEGER      NOT NULL DEFAULT 0,
  created_at    DATETIME              DEFAULT NULL
);
CREATE INDEX idx_atg_tenant ON ai_token_grants (tenant_id);
CREATE INDEX idx_atg_created ON ai_token_grants (created_at);
CREATE TABLE ai_usage_logs (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER      NOT NULL DEFAULT 0,
  group_openid      VARCHAR(128) NOT NULL DEFAULT '',
  member_openid     VARCHAR(128) NOT NULL DEFAULT '',
  kind              VARCHAR(16)  NOT NULL DEFAULT 'at',
  source            VARCHAR(16)  NOT NULL DEFAULT 'platform',
  model             VARCHAR(64)  NOT NULL DEFAULT '',
  prompt_tokens     INTEGER      NOT NULL DEFAULT 0,
  completion_tokens INTEGER      NOT NULL DEFAULT 0,
  total_tokens      INTEGER      NOT NULL DEFAULT 0,
  duration_ms       INTEGER      NOT NULL DEFAULT 0,
  status            VARCHAR(16)  NOT NULL DEFAULT 'ok',
  error             VARCHAR(200) NOT NULL DEFAULT '',
  created_at        DATETIME              DEFAULT NULL
);
CREATE INDEX idx_aul_tenant ON ai_usage_logs (tenant_id, created_at);
CREATE INDEX idx_aul_kind ON ai_usage_logs (kind);
CREATE INDEX idx_aul_created ON ai_usage_logs (created_at);
CREATE TABLE bot_chat_messages (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER      NOT NULL,
  group_openid  VARCHAR(128) NOT NULL DEFAULT '',
  message_id    VARCHAR(64)  NOT NULL DEFAULT '',
  member_openid VARCHAR(128) NOT NULL DEFAULT '',
  role          VARCHAR(16)  NOT NULL DEFAULT 'user',
  content       VARCHAR(500) NOT NULL DEFAULT '',
  created_at    DATETIME              DEFAULT NULL
);
CREATE INDEX idx_bcm_group ON bot_chat_messages (tenant_id, group_openid, id);
CREATE INDEX idx_bcm_created ON bot_chat_messages (created_at);
CREATE TABLE bot_ai_jobs (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER      NOT NULL,
  group_openid   VARCHAR(128) NOT NULL DEFAULT '',
  member_openid  VARCHAR(128) NOT NULL DEFAULT '',
  message_id     VARCHAR(64)           DEFAULT NULL,
  content        VARCHAR(500) NOT NULL DEFAULT '',
  kind           VARCHAR(16)  NOT NULL DEFAULT 'at',
  status         VARCHAR(16)  NOT NULL DEFAULT 'pending',
  attempts       INTEGER      NOT NULL DEFAULT 0,
  error          VARCHAR(200) NOT NULL DEFAULT '',
  reply_deadline DATETIME              DEFAULT NULL,
  created_at     DATETIME              DEFAULT NULL,
  started_at     DATETIME              DEFAULT NULL,
  finished_at    DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, message_id, kind)
);
CREATE INDEX idx_baj_status ON bot_ai_jobs (status, id);
CREATE INDEX idx_baj_tenant ON bot_ai_jobs (tenant_id, created_at);
-- v5.36.29：AI 人设库与表情包库（平台级）
CREATE TABLE ai_prompt_presets (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       VARCHAR(32)   NOT NULL UNIQUE,
  body       VARCHAR(4000) NOT NULL,
  note       VARCHAR(200)  NOT NULL DEFAULT '',
  enabled    INTEGER       NOT NULL DEFAULT 1,
  sort       INTEGER       NOT NULL DEFAULT 0,
  created_by INTEGER       NOT NULL DEFAULT 0,
  created_at DATETIME               DEFAULT NULL,
  updated_at DATETIME               DEFAULT NULL
);
CREATE INDEX idx_app_enabled ON ai_prompt_presets (enabled, sort);
CREATE TABLE ai_stickers (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       VARCHAR(32)   NOT NULL UNIQUE,
  note       VARCHAR(200)  NOT NULL DEFAULT '',
  path       VARCHAR(255)  NOT NULL,
  bytes      INTEGER       NOT NULL DEFAULT 0,
  enabled    INTEGER       NOT NULL DEFAULT 1,
  sort       INTEGER       NOT NULL DEFAULT 0,
  sent_count INTEGER       NOT NULL DEFAULT 0,
  created_by INTEGER       NOT NULL DEFAULT 0,
  created_at DATETIME               DEFAULT NULL,
  updated_at DATETIME               DEFAULT NULL
);
CREATE INDEX idx_as_enabled ON ai_stickers (enabled, sort);
CREATE TABLE ai_sticker_logs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  group_openid VARCHAR(128) NOT NULL DEFAULT '',
  sticker_id   INTEGER      NOT NULL DEFAULT 0,
  sticker_name VARCHAR(32)  NOT NULL DEFAULT '',
  pick_mode    VARCHAR(8)   NOT NULL DEFAULT 'ai',
  trigger_kind VARCHAR(16)  NOT NULL DEFAULT 'at',
  stage        VARCHAR(16)  NOT NULL DEFAULT 'send',
  status       VARCHAR(16)  NOT NULL DEFAULT 'ok',
  error        VARCHAR(200) NOT NULL DEFAULT '',
  duration_ms  INTEGER      NOT NULL DEFAULT 0,
  created_at   DATETIME              DEFAULT NULL
);
CREATE INDEX idx_asl_tenant ON ai_sticker_logs (tenant_id, id);
CREATE INDEX idx_asl_created ON ai_sticker_logs (created_at);
-- ============================================================
-- v5.36.31：强制平台公告（平台超管发布，全体已上线成员登录后强制弹出）
--   platform_announcement_reads.announcement_id=0 那一行是「不再提示」的全局静音
-- ============================================================
CREATE TABLE platform_announcements (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  title      VARCHAR(120) NOT NULL,
  content    TEXT          NOT NULL,
  status     INTEGER       NOT NULL DEFAULT 1,
  actor_id   INTEGER       NOT NULL DEFAULT 0,
  actor_name VARCHAR(64)   NOT NULL DEFAULT '',
  created_at DATETIME               DEFAULT NULL,
  updated_at DATETIME               DEFAULT NULL
);
CREATE INDEX idx_pna_status ON platform_announcements (status, id);
CREATE TABLE platform_announcement_reads (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  announcement_id INTEGER      NOT NULL,
  user_id         INTEGER      NOT NULL,
  tenant_id       INTEGER      NOT NULL DEFAULT 0,
  choice          VARCHAR(6)   NOT NULL DEFAULT 'ack',
  created_at      DATETIME              DEFAULT NULL,
  UNIQUE (announcement_id, user_id)
);
CREATE INDEX idx_pnar_ann ON platform_announcement_reads (announcement_id);
CREATE INDEX idx_pnar_tenant ON platform_announcement_reads (tenant_id);
-- ============================================================
-- v5.36.32：战队销毁登记（封满 15 天后平台超管整队销毁，备份包唯一凭据）
-- ============================================================
CREATE TABLE tenant_purges (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER      NOT NULL,
  slug         VARCHAR(30)  NOT NULL,
  name         VARCHAR(64)  NOT NULL DEFAULT '',
  member_count INTEGER      NOT NULL DEFAULT 0,
  row_count    INTEGER      NOT NULL DEFAULT 0,
  file_count   INTEGER      NOT NULL DEFAULT 0,
  bytes        INTEGER      NOT NULL DEFAULT 0,
  banned_at    DATETIME              DEFAULT NULL,
  banned_days  INTEGER      NOT NULL DEFAULT 0,
  backup_file  VARCHAR(255) NOT NULL DEFAULT '',
  backup_bytes INTEGER      NOT NULL DEFAULT 0,
  actor_id     INTEGER      NOT NULL DEFAULT 0,
  actor_name   VARCHAR(64)  NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL
);
CREATE INDEX idx_tpurged_tenant ON tenant_purges (tenant_id);
CREATE INDEX idx_tpurged_created ON tenant_purges (created_at);
-- ============================================================
-- v5.10.0：平台运营与合规增强包
--   Cookie 同意日志 / API 请求日志 / 死链检测
-- ============================================================
CREATE TABLE cookie_consent (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL DEFAULT 0,
  user_id     INTEGER      NOT NULL DEFAULT 0,
  user_hash   CHAR(64)     NOT NULL DEFAULT '',
  choice      VARCHAR(16)  NOT NULL DEFAULT '',
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent  VARCHAR(255) NOT NULL DEFAULT '',
  policy_ver  VARCHAR(32)  NOT NULL DEFAULT '',
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, user_id, user_hash)
);
CREATE INDEX idx_cc_tenant  ON cookie_consent (tenant_id);
CREATE INDEX idx_cc_created ON cookie_consent (created_at);
CREATE TABLE api_logs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  api_key_id   INTEGER      NOT NULL DEFAULT 0,
  key_prefix   VARCHAR(16)  NOT NULL DEFAULT '',
  scope        VARCHAR(16)  NOT NULL DEFAULT '',
  tenant_id    INTEGER      NOT NULL DEFAULT 0,
  method       VARCHAR(8)   NOT NULL DEFAULT '',
  path         VARCHAR(255) NOT NULL DEFAULT '',
  status       INTEGER      NOT NULL DEFAULT 0,
  result       INTEGER      NOT NULL DEFAULT 0,
  fail_reason  VARCHAR(128) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  duration_ms  INTEGER      NOT NULL DEFAULT 0,
  created_at   DATETIME              DEFAULT NULL
);
CREATE INDEX idx_al_key     ON api_logs (api_key_id);
CREATE INDEX idx_al_tenant  ON api_logs (tenant_id);
CREATE INDEX idx_al_created ON api_logs (created_at);
CREATE INDEX idx_al_result  ON api_logs (result);
CREATE TABLE broken_links (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER      NOT NULL DEFAULT 0,
  url           VARCHAR(500) NOT NULL,
  url_hash      CHAR(64)     NOT NULL,
  source_type   VARCHAR(32)  NOT NULL DEFAULT '',
  source_id     INTEGER      NOT NULL DEFAULT 0,
  source_ref    VARCHAR(128) NOT NULL DEFAULT '',
  status_code   INTEGER      NOT NULL DEFAULT 0,
  error_msg     VARCHAR(255) NOT NULL DEFAULT '',
  hit_count     INTEGER      NOT NULL DEFAULT 1,
  last_ok_at    DATETIME              DEFAULT NULL,
  last_check_at DATETIME              DEFAULT NULL,
  resolved      INTEGER      NOT NULL DEFAULT 0,
  resolved_by   INTEGER      NOT NULL DEFAULT 0,
  resolved_at   DATETIME              DEFAULT NULL,
  created_at    DATETIME              DEFAULT NULL,
  updated_at    DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, url_hash)
);
CREATE INDEX idx_bl_tenant     ON broken_links (tenant_id);
CREATE INDEX idx_bl_resolved   ON broken_links (resolved);
CREATE INDEX idx_bl_last_check ON broken_links (last_check_at);
-- v5.11.0 成员战绩（成员各自登录后经接口上传，指标列全部可空 = 该项未填）
CREATE TABLE player_stats (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id           INTEGER      NOT NULL,
  user_id             INTEGER      NOT NULL,
  game_pid            VARCHAR(64)  NOT NULL DEFAULT '',
  matches             INTEGER               DEFAULT NULL,
  win_rate            DECIMAL(5,2)          DEFAULT NULL,
  avg_score           DECIMAL(10,2)         DEFAULT NULL,
  kd                  DECIMAL(10,2)         DEFAULT NULL,
  score_per_match     DECIMAL(10,2)         DEFAULT NULL,
  score_per_minute    DECIMAL(10,2)         DEFAULT NULL,
  kills               INTEGER               DEFAULT NULL,
  deaths              INTEGER               DEFAULT NULL,
  kda                 DECIMAL(10,2)         DEFAULT NULL,
  kills_per_match     DECIMAL(10,2)         DEFAULT NULL,
  assists_per_match   DECIMAL(10,2)         DEFAULT NULL,
  sites_per_match     DECIMAL(10,2)         DEFAULT NULL,
  healing_per_match   DECIMAL(10,2)         DEFAULT NULL,
  revives             INTEGER               DEFAULT NULL,
  best_match_kills    INTEGER               DEFAULT NULL,
  kill_duration       INTEGER               DEFAULT NULL,
  quit_rate           DECIMAL(5,2)          DEFAULT NULL,
  max_win_streak      INTEGER               DEFAULT NULL,
  win_rate_last10     DECIMAL(5,2)          DEFAULT NULL,
  win_rate_last30     DECIMAL(5,2)          DEFAULT NULL,
  created_at          DATETIME              DEFAULT NULL,
  updated_at          DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, user_id)
);
CREATE INDEX idx_ps_tenant   ON player_stats (tenant_id);
CREATE INDEX idx_ps_game_pid ON player_stats (game_pid);
-- 个人黑名单（v5.30.1；每人最多 10 个、只能拉其它战队的人，且只对拉黑者本人生效）
CREATE TABLE user_blocks (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  user_id         INTEGER NOT NULL,
  blocked_user_id INTEGER NOT NULL,
  created_at      DATETIME     DEFAULT NULL
);
CREATE UNIQUE INDEX uk_block_pair ON user_blocks (user_id, blocked_user_id);
-- v5.31.0 平台邮件群发（与 database/game_team.sql 同一份口径：批次 + 逐封明细）
CREATE TABLE mail_blasts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  subject      VARCHAR(200) NOT NULL,
  body         TEXT         NOT NULL,
  is_html      INTEGER      NOT NULL DEFAULT 0,
  recv_groups  VARCHAR(32)  NOT NULL DEFAULT '',
  target       INTEGER      NOT NULL DEFAULT 0,
  status       VARCHAR(12)  NOT NULL DEFAULT 'queued',
  actor_id     INTEGER      NOT NULL DEFAULT 0,
  actor_name   VARCHAR(64)  NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL,
  started_at   DATETIME              DEFAULT NULL,
  finished_at  DATETIME              DEFAULT NULL
);
CREATE INDEX idx_blast_status ON mail_blasts (status, id);
CREATE TABLE mail_blast_items (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  blast_id  INTEGER NOT NULL,
  uid       INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL DEFAULT 0,
  email     VARCHAR(128) NOT NULL DEFAULT '',
  who       VARCHAR(8)   NOT NULL DEFAULT 'member',
  status    VARCHAR(10)  NOT NULL DEFAULT 'pending',
  notice    INTEGER      NOT NULL DEFAULT 0,
  err       VARCHAR(200) NOT NULL DEFAULT '',
  sent_at   DATETIME              DEFAULT NULL
);
CREATE UNIQUE INDEX uk_blast_uid ON mail_blast_items (blast_id, uid);
CREATE INDEX idx_blast_pick ON mail_blast_items (blast_id, status, id);

-- v5.33.0 赛前提醒去重：一类事件一行，推过不再推
CREATE TABLE event_reminders (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  kind      VARCHAR(16)  NOT NULL,
  ref_id    INTEGER      NOT NULL,
  tenant_id INTEGER      NOT NULL,
  event_at  DATETIME     NOT NULL,
  lead_min  INTEGER      NOT NULL DEFAULT 30,
  group_ok  INTEGER      NOT NULL DEFAULT 0,
  sent_at   DATETIME     NOT NULL,
  UNIQUE (kind, ref_id, tenant_id)
);
CREATE INDEX idx_remind_tenant ON event_reminders (tenant_id, sent_at);

-- v5.36.34 比赛日集合点名：一张点名单 + 一人一行的回执
CREATE TABLE roll_calls (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  game_side  VARCHAR(8)   NOT NULL DEFAULT '',
  title      VARCHAR(128) NOT NULL,
  event_id   INTEGER      NOT NULL DEFAULT 0,
  call_at    DATETIME     NOT NULL,
  deadline   DATETIME              DEFAULT NULL,
  notify_at  DATETIME              DEFAULT NULL,
  revoked_at DATETIME              DEFAULT NULL,
  created_by INTEGER      NOT NULL DEFAULT 0,
  created_at DATETIME              DEFAULT NULL,
  updated_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_rc_tenant_call ON roll_calls (tenant_id, call_at);
CREATE INDEX idx_rc_due ON roll_calls (notify_at, call_at);
CREATE TABLE roll_call_replies (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  call_id    INTEGER      NOT NULL,
  tenant_id  INTEGER      NOT NULL,
  user_id    INTEGER      NOT NULL,
  reply      VARCHAR(8)   NOT NULL DEFAULT '',
  note       VARCHAR(128) NOT NULL DEFAULT '',
  replied_at DATETIME     NOT NULL,
  UNIQUE (call_id, user_id)
);
CREATE INDEX idx_rcr_call ON roll_call_replies (call_id);
CREATE INDEX idx_rcr_tenant ON roll_call_replies (tenant_id, user_id);
SQL);
$now = date('Y-m-d H:i:s');
// 内置平台超管: admin / admin123（登录后请立即修改密码）
// 战队站点不预置，由入驻审核通过时自动开通
$adminHash = '$2y$10$j/Kfqxd5qKY0C5h//t5c8Og.G6AYXZcOxsVpjMtzh/OY/cbIzpP.a';
$pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,created_at,updated_at)
  VALUES ('admin','$adminHash','平台管理员','admin','normal','$now','$now')");
// v5.10.0：平台级默认设置（SEO / 隐私政策 / Cookie 同意 / Sitemap / 图片压缩）
// 与 MySQL 升级脚本保持一致；幂等 INSERT OR IGNORE，重复执行安全
$platformDefaults = [
    'seo_site_title'         => '战队入驻平台',
    'seo_site_description'   => '基于三角洲行动的多租户游戏战队管理与入驻平台，提供战队公示、成员协作、约战赛事、QQ机器人等一站式服务。',
    'seo_keywords'           => '三角洲行动,战队管理,游戏战队,战队入驻,约战系统,QQ机器人',
    'seo_og_image'           => '',
    'seo_canonical_base'     => '',
    'policies_privacy'       => '',
    'policies_terms'         => '',
    'policies_updated_at'    => '',
    'cookie_consent_enabled' => '1',
    'cookie_consent_text'    => '本网站使用必要的 Cookie 保障登录与安全，并在您同意后使用统计类 Cookie 改善体验。详见隐私政策。',
    'sitemap_enabled'        => '1',
    'sitemap_last_generated' => '',
    'broken_link_last_scan'  => '',
    'image_compress_enabled' => '1',
    'image_compress_quality' => '82',
    'image_compress_max_width'  => '1920',
    'image_compress_max_height' => '1920',
    'stats_api_enabled'      => '1',
    'stats_upload_window'    => '60',
    'stats_upload_limit'     => '10',
    'stats_ip_upload_limit'  => '60',
];
$insSetting = $pdo->prepare('INSERT OR IGNORE INTO settings (tenant_id, `key`, value) VALUES (0, ?, ?)');
foreach ($platformDefaults as $k => $v) {
    $insSetting->execute([$k, $v]);
}
echo "SQLite 测试库已初始化(v5): $path\n";
echo "平台超管账号: admin / admin123\n";
