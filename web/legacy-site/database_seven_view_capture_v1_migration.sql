-- 齿镜：七视图自动采集协议 V1（方案 B 内存安全版）
-- 已部署站点只执行本文件，不要重新执行 database.sql。
SET NAMES utf8mb4;
USE `8_138_230_100_666`;
SET @db=DATABASE();

SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND COLUMN_NAME='capture_session_id')=0,'ALTER TABLE detections ADD COLUMN capture_session_id BIGINT UNSIGNED NULL AFTER result_json','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND COLUMN_NAME='capture_mode')=0,'ALTER TABLE detections ADD COLUMN capture_mode ENUM(''single_image'',''seven_view'') NULL AFTER capture_session_id','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND COLUMN_NAME='capture_region_id')=0,'ALTER TABLE detections ADD COLUMN capture_region_id ENUM(''front_bite'',''left_bite'',''right_bite'',''upper_left_open'',''upper_right_open'',''lower_left_open'',''lower_right_open'') NULL AFTER capture_mode','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND COLUMN_NAME='capture_region_index')=0,'ALTER TABLE detections ADD COLUMN capture_region_index TINYINT UNSIGNED NULL AFTER capture_region_id','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND COLUMN_NAME='canonical_orientation')=0,'ALTER TABLE detections ADD COLUMN canonical_orientation ENUM(''patient_coordinate'') NULL AFTER normalization_applied','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS capture_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  protocol_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
  device_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  client_session_nonce VARCHAR(64) NOT NULL,
  capture_mode ENUM('seven_view') NOT NULL DEFAULT 'seven_view',
  status ENUM('collecting','completed','incomplete','cancelled','expired') NOT NULL DEFAULT 'collecting',
  current_region_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
  completed_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completed_mask TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reference_version_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  expired_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_capture_sessions_public (public_id),
  UNIQUE KEY uk_capture_sessions_device_nonce (device_id,client_session_nonce),
  KEY idx_capture_sessions_device_status (device_id,status,updated_at),
  KEY idx_capture_sessions_member_created (member_id,created_at),
  CONSTRAINT fk_capture_sessions_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_sessions_member FOREIGN KEY (member_id) REFERENCES family_members(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_sessions_reference FOREIGN KEY (reference_version_id) REFERENCES capture_reference_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capture_candidates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  protocol_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
  device_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  request_id VARCHAR(64) NOT NULL,
  request_sha256 CHAR(64) NOT NULL,
  capture_mode ENUM('single_image','seven_view') NOT NULL,
  expected_region ENUM('free','front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open') NOT NULL,
  region_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
  validation_status ENUM('validating','completed','failed') NOT NULL DEFAULT 'validating',
  lifecycle_status ENUM('temporary','confirmed','discarded','expired') NOT NULL DEFAULT 'temporary',
  raw_path VARCHAR(255) NOT NULL,
  canonical_path VARCHAR(255) NOT NULL,
  image_width SMALLINT UNSIGNED NOT NULL,
  image_height SMALLINT UNSIGNED NOT NULL,
  image_bytes INT UNSIGNED NOT NULL,
  source_mirrored TINYINT(1) NOT NULL DEFAULT 1,
  normalization_applied ENUM('horizontal_flip') NOT NULL DEFAULT 'horizontal_flip',
  canonical_orientation ENUM('patient_coordinate') NOT NULL DEFAULT 'patient_coordinate',
  detected_region ENUM('front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open','unknown') NULL,
  region_match TINYINT(1) NULL,
  accepted TINYINT(1) NULL,
  decision ENUM('retake','next','complete') NULL,
  distance_class ENUM('too_far','too_close','good','unknown') NULL,
  quality_result JSON NULL,
  confidence DECIMAL(5,4) NULL,
  reason_code VARCHAR(32) NULL,
  error_code VARCHAR(32) NULL,
  instruction VARCHAR(96) NULL,
  model_name VARCHAR(96) NULL,
  reference_version_code VARCHAR(32) NULL,
  model_latency_ms INT UNSIGNED NULL,
  server_elapsed_ms INT UNSIGNED NULL,
  response_json JSON NULL,
  response_http_status SMALLINT UNSIGNED NULL,
  confirm_response_json JSON NULL,
  detection_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  validated_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  discarded_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_capture_candidates_public (public_id),
  UNIQUE KEY uk_capture_candidates_device_request (device_id,request_id),
  UNIQUE KEY uk_capture_candidates_detection (detection_id),
  KEY idx_capture_candidates_session_status (session_id,validation_status),
  KEY idx_capture_candidates_lifecycle_created (lifecycle_status,created_at),
  CONSTRAINT fk_capture_candidates_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_candidates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_candidates_member FOREIGN KEY (member_id) REFERENCES family_members(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_candidates_session FOREIGN KEY (session_id) REFERENCES capture_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_candidates_detection FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_candidates' AND COLUMN_NAME='canonical_orientation')=0,'ALTER TABLE capture_candidates ADD COLUMN canonical_orientation ENUM(''patient_coordinate'') NOT NULL DEFAULT ''patient_coordinate'' AFTER normalization_applied','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;

SET @sql=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND INDEX_NAME='idx_detections_capture_session')=0,'ALTER TABLE detections ADD KEY idx_detections_capture_session (capture_session_id,capture_region_index)','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='detections' AND INDEX_NAME='uk_detections_capture_region')=0,'ALTER TABLE detections ADD UNIQUE KEY uk_detections_capture_region (capture_session_id,capture_region_id)','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='detections' AND CONSTRAINT_NAME='fk_detections_capture_session')=0,'ALTER TABLE detections ADD CONSTRAINT fk_detections_capture_session FOREIGN KEY (capture_session_id) REFERENCES capture_sessions(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;

SELECT COUNT(*) AS capture_sessions_ready FROM capture_sessions;
SELECT COUNT(*) AS capture_candidates_ready FROM capture_candidates;
SHOW COLUMNS FROM detections LIKE 'capture_session_id';
