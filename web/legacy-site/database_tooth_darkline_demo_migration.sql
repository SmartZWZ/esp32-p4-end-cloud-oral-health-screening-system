-- 齿镜：允许内置七视图测试档案进入浅龋证据层队列（MySQL 5.7 / 8.0）
-- 请先在 phpMyAdmin 左侧选中当前齿镜数据库，再执行本文件。
-- 真实影像任务仍然保存 detection_id；只有受控的内置测试数据任务使用 NULL。

ALTER TABLE tooth_darkline_lab_jobs
  MODIFY COLUMN detection_id BIGINT UNSIGNED NULL;
