-- 齿镜：参考图质量判断 V2 迁移（MySQL 5.7 / 8.0）
-- 已部署参考图后台的站点只执行本文件，不要重新执行 database.sql。
USE `8_138_230_100_666`;

SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_reference_settings' AND COLUMN_NAME='quality_model')=0,
  'ALTER TABLE capture_reference_settings ADD COLUMN quality_model VARCHAR(96) NULL AFTER validation_enabled',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_reference_settings' AND COLUMN_NAME='quality_confidence_threshold')=0,
  'ALTER TABLE capture_reference_settings ADD COLUMN quality_confidence_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.800 AFTER quality_model',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_reference_tests' AND COLUMN_NAME='human_verdict')=0,
  'ALTER TABLE capture_reference_tests ADD COLUMN human_verdict ENUM(''accepted'',''too_far'',''too_close'',''wrong_region'',''blurred'',''lighting'',''framing'',''uncertain'') NULL AFTER latency_ms',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_reference_tests' AND COLUMN_NAME='human_note')=0,
  'ALTER TABLE capture_reference_tests ADD COLUMN human_note VARCHAR(500) NULL AFTER human_verdict',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_reference_tests' AND COLUMN_NAME='labeled_by')=0,
  'ALTER TABLE capture_reference_tests ADD COLUMN labeled_by BIGINT UNSIGNED NULL AFTER human_note',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='capture_reference_tests' AND COLUMN_NAME='labeled_at')=0,
  'ALTER TABLE capture_reference_tests ADD COLUMN labeled_at DATETIME NULL AFTER labeled_by',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='capture_reference_tests' AND CONSTRAINT_NAME='fk_capture_reference_tests_labeler')=0,
  'ALTER TABLE capture_reference_tests ADD CONSTRAINT fk_capture_reference_tests_labeler FOREIGN KEY (labeled_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT quality_model,quality_confidence_threshold FROM capture_reference_settings WHERE id=1;
SELECT COUNT(*) AS test_count,COUNT(human_verdict) AS labeled_count FROM capture_reference_tests;
