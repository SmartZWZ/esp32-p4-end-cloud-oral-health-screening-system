-- 齿镜：牙齿实例轮廓与浅龋暗线模型迁移（MySQL 5.7 / 8.0）
-- 只需在齿镜数据库中执行一次；不要重新执行 database.sql。
USE `8_138_230_100_666`;

ALTER TABLE detections
  MODIFY COLUMN model_pipeline
  ENUM('caries','both','dental_seg','calculus_seg','tooth_outline','all_models')
  NOT NULL DEFAULT 'caries';

SHOW COLUMNS FROM detections LIKE 'model_pipeline';
