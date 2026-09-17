-- 增加在 Windows 本地 GPU 运行的牙结石 v5 双模型语义分割流水线。
-- 在 phpMyAdmin 中先选择当前齿镜数据库，再执行本文件一次。
SET NAMES utf8mb4;
ALTER TABLE detections
  MODIFY COLUMN model_pipeline
  ENUM('caries','both','dental_seg','calculus_seg','tooth_outline','all_models')
  NOT NULL DEFAULT 'caries';
