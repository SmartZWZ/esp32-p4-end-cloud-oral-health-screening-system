-- 网页本地图片上传来源没有物理设备，因此允许 detections.device_id 为空。
-- 已有 ESP32-P4 记录及外键关系不会改变；NULL 仅表示该图片由登录用户在网页上传。
ALTER TABLE detections
  MODIFY COLUMN device_id BIGINT UNSIGNED NULL;
