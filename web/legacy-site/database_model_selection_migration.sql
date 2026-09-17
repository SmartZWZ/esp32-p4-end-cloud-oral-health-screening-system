-- 模型选择与检测框可视化升级：在 phpMyAdmin 选中当前数据库后执行一次。
SET NAMES utf8mb4;
SET @has_model_pipeline := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'detections' AND COLUMN_NAME = 'model_pipeline'
);
SET @add_model_pipeline_sql := IF(
  @has_model_pipeline = 0,
  "ALTER TABLE detections ADD COLUMN model_pipeline ENUM('caries','both','dental_seg','calculus_seg','tooth_outline','all_models') NOT NULL DEFAULT 'caries' AFTER upload_mode",
  'SELECT 1'
);
PREPARE add_model_pipeline_stmt FROM @add_model_pipeline_sql;
EXECUTE add_model_pipeline_stmt;
DEALLOCATE PREPARE add_model_pipeline_stmt;
