-- 检测结果表迁移：若此前已经成功创建 detection_results，本文件可安全执行且不会重复建表。
SET NAMES utf8mb4;
USE `8_138_230_100_666`;

CREATE TABLE IF NOT EXISTS detection_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  detection_id BIGINT UNSIGNED NOT NULL,
  model_name VARCHAR(64) NOT NULL,
  model_version VARCHAR(64) NULL,
  model_type ENUM('vision','llm') NOT NULL,
  status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
  raw_result_json JSON NULL,
  summary_text TEXT NULL,
  risk_level ENUM('unknown','low','medium','high') NOT NULL DEFAULT 'unknown',
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_detection_results_public_id (public_id),
  KEY idx_results_detection_created (detection_id,created_at),
  CONSTRAINT fk_results_detection FOREIGN KEY (detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
