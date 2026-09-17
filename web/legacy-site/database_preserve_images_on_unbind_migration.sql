-- 齿镜：设备解绑时保留历史影像（MySQL 5.7 / 8.0）
-- 在 phpMyAdmin 左侧先选中齿镜业务数据库，再执行一次。
-- 本文件不访问 information_schema，也不包含 USE 语句。

ALTER TABLE detections
  DROP FOREIGN KEY fk_detections_device;

ALTER TABLE detections
  ADD CONSTRAINT fk_detections_device
  FOREIGN KEY (device_id) REFERENCES devices(id)
  ON DELETE SET NULL;

SHOW CREATE TABLE detections;
