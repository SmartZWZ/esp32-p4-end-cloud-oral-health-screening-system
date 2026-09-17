-- 齿镜：全部模型联合分析迁移（受限 MySQL 账号兼容版）
-- 在 phpMyAdmin 左侧先选中齿镜业务数据库，再执行一次。
-- 本文件不读取 information_schema，适用于无系统库查询权限的站点数据库账号。

ALTER TABLE detections
  MODIFY COLUMN model_pipeline
  ENUM('caries','both','dental_seg','calculus_seg','tooth_outline','all_models')
  NOT NULL DEFAULT 'caries';

ALTER TABLE detections
  ADD COLUMN source_detection_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN progress_step TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_detection_id,
  ADD COLUMN progress_total TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER progress_step,
  ADD COLUMN progress_label VARCHAR(120) NULL AFTER progress_total,
  ADD KEY idx_detections_source (source_detection_id);

SHOW COLUMNS FROM detections LIKE 'model_pipeline';
SHOW COLUMNS FROM detections LIKE 'source_detection_id';
SHOW COLUMNS FROM detections LIKE 'progress_step';
SHOW COLUMNS FROM detections LIKE 'progress_total';
SHOW COLUMNS FROM detections LIKE 'progress_label';
