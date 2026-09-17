-- 齿镜：图片“查看与编辑”审计记录（MySQL 5.7 / 8.0）
-- 请先在 phpMyAdmin 左侧选择齿镜数据库，再执行本文件。

CREATE TABLE IF NOT EXISTS image_edit_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  detection_id BIGINT UNSIGNED NOT NULL,
  old_image_path VARCHAR(255) NOT NULL,
  new_image_path VARCHAR(255) NOT NULL,
  image_width SMALLINT UNSIGNED NOT NULL,
  image_height SMALLINT UNSIGNED NOT NULL,
  image_bytes INT UNSIGNED NOT NULL,
  operations_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_image_edit_history_public (public_id),
  KEY idx_image_edit_history_detection (detection_id,created_at),
  KEY idx_image_edit_history_user (user_id,created_at),
  CONSTRAINT fk_image_edit_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_image_edit_history_detection FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT COUNT(*) AS image_edit_history_ready FROM image_edit_history;

