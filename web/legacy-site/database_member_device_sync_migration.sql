-- 已执行“成员功能”迁移的站点：在 phpMyAdmin 选中数据库后，仅执行一次本文件。
SET NAMES utf8mb4;
USE `8_138_230_100_666`;

ALTER TABLE family_members
  ADD COLUMN sync_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN updated_source ENUM('web','device') NOT NULL DEFAULT 'web' AFTER sync_version;

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
