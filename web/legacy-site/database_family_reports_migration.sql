-- 齿镜：一键家庭报告（受限 MySQL 账号兼容版）
-- 在 phpMyAdmin 左侧先选中齿镜业务数据库，再执行一次。
-- 本文件不访问 information_schema。

ALTER TABLE ai_dentist_call_logs
  MODIFY COLUMN operation
  ENUM('analysis','follow_up','admin_test','family_image','family_summary','family_model_report')
  NOT NULL;

CREATE TABLE IF NOT EXISTS family_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  symptoms TEXT NULL,
  status ENUM('processing','waiting_models','compiling','completed','partial','failed') NOT NULL DEFAULT 'processing',
  image_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress_step SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  progress_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  progress_label VARCHAR(180) NULL,
  clinical_summary_json JSON NULL,
  model_summary_json JSON NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_family_reports_public (public_id),
  KEY idx_family_reports_user_created (user_id,created_at),
  KEY idx_family_reports_member_created (member_id,created_at),
  CONSTRAINT fk_family_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_family_reports_member FOREIGN KEY (member_id) REFERENCES family_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_report_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  source_detection_id BIGINT UNSIGNED NULL,
  analysis_detection_id BIGINT UNSIGNED NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ai_status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  ai_report_json JSON NULL,
  ai_model_name VARCHAR(96) NULL,
  ai_error_message VARCHAR(1000) NULL,
  model_skipped TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_family_report_image_order (report_id,sort_order),
  KEY idx_family_report_source (source_detection_id),
  KEY idx_family_report_analysis (analysis_detection_id),
  KEY idx_family_report_ai_status (report_id,ai_status),
  CONSTRAINT fk_family_report_images_report FOREIGN KEY (report_id) REFERENCES family_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_family_report_images_source FOREIGN KEY (source_detection_id) REFERENCES detections(id) ON DELETE SET NULL,
  CONSTRAINT fk_family_report_images_analysis FOREIGN KEY (analysis_detection_id) REFERENCES detections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW COLUMNS FROM family_reports;
SHOW COLUMNS FROM family_report_images;
