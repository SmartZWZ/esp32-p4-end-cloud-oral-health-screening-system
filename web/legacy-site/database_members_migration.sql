-- 已执行过旧版 database.sql 的站点：在 phpMyAdmin 选中数据库后，仅执行一次本文件。
SET NAMES utf8mb4;
USE `8_138_230_100_666`;

CREATE TABLE IF NOT EXISTS family_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  relationship VARCHAR(32) NOT NULL DEFAULT '',
  gender ENUM('unknown','male','female') NOT NULL DEFAULT 'unknown',
  birth_date DATE NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','deleted') NOT NULL DEFAULT 'active',
  sync_version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_source ENUM('web','device') NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_members_public_id (public_id),
  KEY idx_members_user_status (user_id,status),
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_sync_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  operation_id VARCHAR(64) NOT NULL,
  operation_type ENUM('create','update','delete') NOT NULL,
  response_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_member_sync_device_operation (device_id,operation_id),
  KEY idx_member_sync_user_created (user_id,created_at),
  CONSTRAINT fk_member_sync_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_member_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE detections ADD COLUMN member_id BIGINT UNSIGNED NULL AFTER device_id;
ALTER TABLE detections ADD KEY idx_detections_member_created (member_id,created_at);
ALTER TABLE detections ADD CONSTRAINT fk_detections_member FOREIGN KEY (member_id) REFERENCES family_members(id) ON DELETE SET NULL;

-- 为已有账户创建“本人”成员；既有历史图片保持未归属，避免错误归类。
INSERT INTO family_members(public_id,user_id,name,relationship,is_default)
SELECT CONCAT('legacy_member_',u.id),u.id,u.nickname,'本人',1
FROM users u
WHERE NOT EXISTS (
  SELECT 1 FROM family_members m WHERE m.user_id=u.id AND m.status='active'
);
