-- 增加本地运行的 YOLO11 口腔四类实例分割模型。
-- 在 phpMyAdmin 中先选择当前齿镜数据库，再执行本文件。
SET NAMES utf8mb4;

ALTER TABLE detections
  MODIFY COLUMN model_pipeline
  ENUM('caries','both','dental_seg','calculus_seg','tooth_outline','all_models')
  NOT NULL DEFAULT 'caries';
