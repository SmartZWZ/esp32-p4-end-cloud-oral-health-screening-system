-- 已有站点图片管理升级：在 phpMyAdmin 选中当前数据库后执行一次。
-- 原有记录默认视为“上传后云端检测”，不会改变历史图片或检测结果。
SET NAMES utf8mb4;
USE `8_138_230_100_666`;

SET @has_upload_mode := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'detections' AND COLUMN_NAME = 'upload_mode'
);
SET @add_upload_mode_sql := IF(
  @has_upload_mode = 0,
  "ALTER TABLE detections ADD COLUMN upload_mode ENUM('archive','detect') NOT NULL DEFAULT 'detect' AFTER image_bytes",
  'SELECT 1'
);
PREPARE add_upload_mode_stmt FROM @add_upload_mode_sql;
EXECUTE add_upload_mode_stmt;
DEALLOCATE PREPARE add_upload_mode_stmt;

ALTER TABLE detections
  MODIFY COLUMN status ENUM('saved','received','processing','completed','failed') NOT NULL DEFAULT 'received';

SET @has_upload_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'detections' AND INDEX_NAME = 'idx_detections_user_upload_created'
);
SET @add_upload_index_sql := IF(
  @has_upload_index = 0,
  'ALTER TABLE detections ADD KEY idx_detections_user_upload_created (user_id,upload_mode,created_at)',
  'SELECT 1'
);
PREPARE add_upload_index_stmt FROM @add_upload_index_sql;
EXECUTE add_upload_index_stmt;
DEALLOCATE PREPARE add_upload_index_stmt;
