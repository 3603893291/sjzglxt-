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
  admin_perms  VARCHAR(512) NOT NULL DEFAULT '',
  role_tags    VARCHAR(255) NOT NULL DEFAULT '',
  session_epoch INTEGER     NOT NULL DEFAULT 0,
  last_active_at DATETIME            DEFAULT NULL,
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, username)
);
CREATE INDEX idx_users_tenant ON users (tenant_id);
CREATE INDEX idx_users_team ON users (team_id);
CREATE INDEX idx_users_status ON users (status);
CREATE INDEX idx_users_active ON users (last_active_at);

CREATE TABLE teams (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER      NOT NULL,
  name        VARCHAR(64)  NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  leader_id   INTEGER               DEFAULT NULL,
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL,
  UNIQUE (tenant_id, name)
);
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
  created_at  DATETIME              DEFAULT NULL,
  updated_at  DATETIME              DEFAULT NULL
);
CREATE INDEX idx_gc_user ON gun_codes (user_id);
CREATE INDEX idx_gc_status ON gun_codes (status);

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
  title        VARCHAR(128) NOT NULL,
  content      TEXT         NOT NULL,
  image        VARCHAR(255) NOT NULL DEFAULT '',
  need_receipt INTEGER      NOT NULL DEFAULT 0,
  deadline     DATETIME              DEFAULT NULL,
  reminded_at  DATETIME              DEFAULT NULL,
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL
);

CREATE TABLE race_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  title      VARCHAR(128) NOT NULL DEFAULT '撞车比赛',
  race_time  DATETIME     NOT NULL,
  created_at DATETIME              DEFAULT NULL
);
CREATE INDEX idx_race_time ON race_events (race_time);

CREATE TABLE custom_race_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER      NOT NULL,
  title      VARCHAR(128) NOT NULL DEFAULT '自建房比赛',
  race_time  DATETIME     NOT NULL,
  created_at DATETIME              DEFAULT NULL
);
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
  title        VARCHAR(128) NOT NULL,
  start_time   DATETIME     NOT NULL,
  duration_min INTEGER      NOT NULL DEFAULT 60,
  note         VARCHAR(500) NOT NULL DEFAULT '',
  created_at   DATETIME              DEFAULT NULL,
  updated_at   DATETIME              DEFAULT NULL
);
CREATE INDEX idx_ce_time ON custom_events (start_time);

CREATE TABLE duty_configs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER     NOT NULL,
  duty_type   VARCHAR(16) NOT NULL DEFAULT 'room_host',
  cycle       VARCHAR(8)  NOT NULL DEFAULT 'day',
  anchor_date DATE        NOT NULL,
  enabled     INTEGER     NOT NULL DEFAULT 1,
  created_at  DATETIME             DEFAULT NULL,
  updated_at  DATETIME             DEFAULT NULL,
  UNIQUE (tenant_id, duty_type)
);

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
SQL);

$now = date('Y-m-d H:i:s');
// 内置平台超管: admin / admin123（登录后请立即修改密码）
// 战队站点不预置，由入驻审核通过时自动开通
$adminHash = '$2y$10$j/Kfqxd5qKY0C5h//t5c8Og.G6AYXZcOxsVpjMtzh/OY/cbIzpP.a';
$pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,created_at,updated_at)
  VALUES ('admin','$adminHash','平台管理员','admin','normal','$now','$now')");

echo "SQLite 测试库已初始化(v5): $path\n";
echo "平台超管账号: admin / admin123\n";
