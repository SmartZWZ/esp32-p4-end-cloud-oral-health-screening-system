-- 齿镜：牙齿轮廓与浅龋暗线在线调参实验台（MySQL 5.7 / 8.0）
-- 请先在 phpMyAdmin 左侧选中齿镜数据库，再执行本文件。

CREATE TABLE IF NOT EXISTS tooth_darkline_lab_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  detection_id BIGINT UNSIGNED NULL,
  tooth_navigation_id SMALLINT UNSIGNED NOT NULL,
  parameters_json TEXT NOT NULL,
  status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  result_json MEDIUMTEXT NULL,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tooth_darkline_lab_public_id (public_id),
  KEY idx_tooth_darkline_lab_queue (status, id),
  KEY idx_tooth_darkline_lab_user_detection (user_id, detection_id, tooth_navigation_id, id),
  CONSTRAINT fk_tooth_darkline_lab_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tooth_darkline_lab_detection
    FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
