-- ============================================================
-- Phase B マイグレーション
-- オペレーション拡張: ハンディPOS / テイクアウト / フロアマップ / コース・食べ放題
-- ============================================================

-- ------------------------------------------------------------
-- 1. orders テーブル拡張
-- ------------------------------------------------------------

-- table_id を NULL許容（テイクアウトはテーブルなし）
ALTER TABLE orders MODIFY COLUMN table_id VARCHAR(36) DEFAULT NULL;

-- 注文種別
ALTER TABLE orders ADD COLUMN order_type ENUM('dine_in','takeout','handy') NOT NULL DEFAULT 'dine_in'
  COMMENT '注文種別: dine_in=顧客セルフ, takeout=テイクアウト, handy=スタッフ入力'
  AFTER status;

-- スタッフID（ハンディ注文時に記録）
ALTER TABLE orders ADD COLUMN staff_id VARCHAR(36) DEFAULT NULL
  COMMENT 'ハンディ注文を入力したスタッフ'
  AFTER order_type;

-- テイクアウト: 顧客情報
ALTER TABLE orders ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL
  COMMENT 'テイクアウト: 顧客名'
  AFTER staff_id;

ALTER TABLE orders ADD COLUMN customer_phone VARCHAR(30) DEFAULT NULL
  COMMENT 'テイクアウト: 電話番号'
  AFTER customer_name;

-- テイクアウト: 受取時間
ALTER TABLE orders ADD COLUMN pickup_at DATETIME DEFAULT NULL
  COMMENT 'テイクアウト: 受取予定時刻'
  AFTER customer_phone;

-- コース: 紐付けとフェーズ追跡
ALTER TABLE orders ADD COLUMN course_id VARCHAR(36) DEFAULT NULL
  COMMENT 'コーステンプレートID（コース注文時）'
  AFTER pickup_at;

ALTER TABLE orders ADD COLUMN current_phase INT DEFAULT NULL
  COMMENT 'コース: 現在の提供フェーズ番号'
  AFTER course_id;

-- インデックス追加
ALTER TABLE orders ADD INDEX idx_order_type (order_type);
ALTER TABLE orders ADD INDEX idx_order_pickup (pickup_at);
ALTER TABLE orders ADD INDEX idx_order_staff (staff_id);

-- ------------------------------------------------------------
-- 2. tables テーブル拡張（フロアマップ用）
-- ------------------------------------------------------------

ALTER TABLE tables ADD COLUMN pos_x INT NOT NULL DEFAULT 0
  COMMENT 'フロアマップ上のX座標（px）'
  AFTER session_token;

ALTER TABLE tables ADD COLUMN pos_y INT NOT NULL DEFAULT 0
  COMMENT 'フロアマップ上のY座標（px）'
  AFTER pos_x;

ALTER TABLE tables ADD COLUMN width INT NOT NULL DEFAULT 80
  COMMENT 'フロアマップ上の幅（px）'
  AFTER pos_y;

ALTER TABLE tables ADD COLUMN height INT NOT NULL DEFAULT 80
  COMMENT 'フロアマップ上の高さ（px）'
  AFTER width;

ALTER TABLE tables ADD COLUMN shape ENUM('rect','circle','ellipse') NOT NULL DEFAULT 'rect'
  COMMENT 'テーブル形状'
  AFTER height;

ALTER TABLE tables ADD COLUMN floor VARCHAR(30) NOT NULL DEFAULT '1F'
  COMMENT 'フロア名（1F, 2F, テラス等）'
  AFTER shape;

ALTER TABLE tables ADD INDEX idx_table_floor (store_id, floor);

-- ------------------------------------------------------------
-- 3. table_sessions — テーブルセッション管理
-- テーブルごとの着席〜退席サイクルを明示的に管理
-- ------------------------------------------------------------

CREATE TABLE table_sessions (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    table_id        VARCHAR(36) NOT NULL,
    status          ENUM('seated','eating','bill_requested','paid','closed') NOT NULL DEFAULT 'seated'
                    COMMENT 'seated=着席, eating=食事中, bill_requested=会計待ち, paid=会計済み, closed=退席済み',
    guest_count     INT DEFAULT NULL          COMMENT '来客数',
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at       DATETIME DEFAULT NULL,

    -- 食べ放題・制限時間
    plan_id         VARCHAR(36) DEFAULT NULL   COMMENT 'time_limit_plansのID',
    time_limit_min  INT DEFAULT NULL           COMMENT '制限時間（分）',
    last_order_min  INT DEFAULT NULL           COMMENT 'ラストオーダー（終了N分前）',
    expires_at      DATETIME DEFAULT NULL      COMMENT '制限時間の終了時刻',
    last_order_at   DATETIME DEFAULT NULL      COMMENT 'ラストオーダー時刻',

    INDEX idx_ts_store (store_id),
    INDEX idx_ts_table (table_id),
    INDEX idx_ts_active (store_id, status),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. time_limit_plans — 食べ放題・時間制限プラン
-- ------------------------------------------------------------

CREATE TABLE time_limit_plans (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL      COMMENT 'プラン名（90分食べ放題 等）',
    name_en         VARCHAR(100) DEFAULT '',
    duration_min    INT NOT NULL               COMMENT '制限時間（分）',
    last_order_min  INT NOT NULL DEFAULT 15    COMMENT 'ラストオーダー（終了N分前）',
    price           INT NOT NULL DEFAULT 0     COMMENT 'プラン料金（税込）',
    description     TEXT DEFAULT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_tlp_store (store_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. course_templates — コース料理テンプレート
-- ------------------------------------------------------------

CREATE TABLE course_templates (
    id              VARCHAR(36) PRIMARY KEY,
    store_id        VARCHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL      COMMENT 'コース名（松コース 等）',
    name_en         VARCHAR(100) DEFAULT '',
    price           INT NOT NULL DEFAULT 0     COMMENT 'コース料金（税込）',
    description     TEXT DEFAULT NULL,
    phase_count     INT NOT NULL DEFAULT 1     COMMENT 'フェーズ数（前菜→メイン→デザート等）',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ct_store (store_id),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. course_phases — コース各フェーズの品目定義
-- ------------------------------------------------------------

CREATE TABLE course_phases (
    id              VARCHAR(36) PRIMARY KEY,
    course_id       VARCHAR(36) NOT NULL,
    phase_number    INT NOT NULL               COMMENT 'フェーズ番号（1, 2, 3...）',
    name            VARCHAR(100) NOT NULL      COMMENT 'フェーズ名（前菜, メイン, デザート等）',
    name_en         VARCHAR(100) DEFAULT '',
    items           JSON NOT NULL              COMMENT '品目リスト [{id, name, qty}]',
    auto_fire_min   INT DEFAULT NULL           COMMENT '前フェーズ完了後N分で自動発火（NULL=手動）',
    sort_order      INT NOT NULL DEFAULT 0,

    UNIQUE INDEX idx_cp_course_phase (course_id, phase_number),
    FOREIGN KEY (course_id) REFERENCES course_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
