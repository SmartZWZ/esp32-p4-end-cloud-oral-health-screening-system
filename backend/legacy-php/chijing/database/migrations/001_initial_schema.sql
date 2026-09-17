-- 齿镜 v0.1 初始化结构（MySQL 5.7+ / utf8mb4）
-- 请先在宝塔创建并选中目标数据库，再导入本文件。
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration VARCHAR(191) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL COMMENT '对外使用的 ULID，避免暴露递增 ID',
  email VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nickname VARCHAR(64) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_public_id (public_id),
  UNIQUE KEY uk_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT '仅保存 SHA-256 后的刷新令牌',
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_sessions_token_hash (token_hash),
  KEY idx_sessions_user_validity (user_id, expires_at, revoked_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reset_token_hash (token_hash),
  KEY idx_reset_user (user_id, expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  device_uid VARCHAR(96) NOT NULL COMMENT '固件烧录的唯一硬件编号',
  owner_user_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(64) NOT NULL DEFAULT '我的齿镜',
  activation_code_hash CHAR(64) NULL COMMENT '生产/配对时生成，成功绑定后失效',
  status ENUM('unbound','active','disabled','retired') NOT NULL DEFAULT 'unbound',
  firmware_version VARCHAR(64) NULL,
  last_seen_at DATETIME NULL,
  bound_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_devices_public_id (public_id),
  UNIQUE KEY uk_devices_device_uid (device_uid),
  KEY idx_devices_owner_status (owner_user_id, status),
  CONSTRAINT fk_devices_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id BIGINT UNSIGNED NOT NULL,
  token_prefix CHAR(12) NOT NULL COMMENT '令牌前缀，仅用于快速定位',
  token_hash CHAR(64) NOT NULL COMMENT '设备令牌 SHA-256，原文只在签发时显示一次',
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_device_tokens_hash (token_hash),
  KEY idx_device_tokens_device_validity (device_id, expires_at, revoked_at),
  CONSTRAINT fk_device_tokens_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL COMMENT '创建即固化归属，所有用户数据查询必须以此过滤',
  device_id BIGINT UNSIGNED NULL,
  status ENUM('uploaded','queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'uploaded',
  source ENUM('device','web','admin') NOT NULL DEFAULT 'device',
  client_request_id VARCHAR(96) NULL COMMENT '设备重传幂等键',
  captured_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  failure_code VARCHAR(64) NULL,
  failure_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_detections_public_id (public_id),
  UNIQUE KEY uk_detections_device_request (device_id, client_request_id),
  KEY idx_detections_user_created (user_id, created_at),
  KEY idx_detections_status_created (status, created_at),
  CONSTRAINT fk_detections_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_detections_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detection_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  detection_id BIGINT UNSIGNED NOT NULL,
  image_kind ENUM('original','normalized','annotated','thumbnail') NOT NULL,
  storage_key VARCHAR(512) NOT NULL COMMENT 'storage 内相对路径或对象存储 Key，不能直接公开',
  mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
  byte_size INT UNSIGNED NOT NULL,
  width SMALLINT UNSIGNED NULL,
  height SMALLINT UNSIGNED NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_detection_images_kind (detection_id, image_kind),
  KEY idx_detection_images_sha256 (sha256),
  CONSTRAINT fk_detection_images_detection FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  detection_id BIGINT UNSIGNED NOT NULL,
  model_name VARCHAR(128) NOT NULL,
  model_version VARCHAR(64) NOT NULL,
  result_json JSON NOT NULL COMMENT '类别、置信度、框坐标、图像质量等结构化输出',
  quality_score DECIMAL(5,4) NULL,
  inference_ms INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_model_results_detection (detection_id, created_at),
  CONSTRAINT fk_model_results_detection FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  detection_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(64) NOT NULL,
  model_name VARCHAR(128) NOT NULL,
  prompt_version VARCHAR(64) NOT NULL,
  report_json JSON NOT NULL COMMENT '面向用户的摘要、建议、风险提示和免责声明',
  status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  failure_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ai_reports_detection (detection_id),
  CONSTRAINT fk_ai_reports_detection FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  device_id BIGINT UNSIGNED NULL,
  action VARCHAR(96) NOT NULL,
  target_type VARCHAR(64) NULL,
  target_public_id CHAR(26) NULL,
  ip_address VARCHAR(45) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user_created (user_id, created_at),
  KEY idx_audit_device_created (device_id, created_at),
  KEY idx_audit_action_created (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('001_initial_schema');
SET FOREIGN_KEY_CHECKS = 1;
