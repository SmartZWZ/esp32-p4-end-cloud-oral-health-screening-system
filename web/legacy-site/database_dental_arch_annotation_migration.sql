-- 齿镜：七视图牙列标注、人工复核与版本化绑定（MySQL 5.7 / 8.0）
-- 使用前请先在 phpMyAdmin 左侧选中齿镜数据库。

SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ai_dentist_settings' AND COLUMN_NAME='dental_arch_model')=0,
  "ALTER TABLE ai_dentist_settings ADD COLUMN dental_arch_model VARCHAR(96) NOT NULL DEFAULT 'qwen3.7-plus' AFTER fallback_model",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ai_dentist_settings' AND COLUMN_NAME='dental_arch_fallback_model')=0,
  "ALTER TABLE ai_dentist_settings ADD COLUMN dental_arch_fallback_model VARCHAR(96) NOT NULL DEFAULT 'qwen3.6-plus' AFTER dental_arch_model",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE ai_dentist_call_logs
  MODIFY COLUMN operation ENUM(
    'analysis','follow_up','admin_test','family_image','family_summary','family_model_report',
    'dental_arch_image','dental_arch_joint'
  ) NOT NULL;

CREATE TABLE IF NOT EXISTS dental_arch_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  capture_session_id BIGINT UNSIGNED NULL,
  source_type ENUM('capture_archive','manual','numbered_test') NOT NULL,
  review_mode ENUM('ask','required','skip') NOT NULL DEFAULT 'ask',
  review_decision ENUM('pending','review','direct') NOT NULL DEFAULT 'pending',
  status ENUM('waiting_outline','vision_pending','vision_processing','review_required','completed','failed','stale') NOT NULL DEFAULT 'waiting_outline',
  visual_model VARCHAR(96) NOT NULL,
  fallback_model VARCHAR(96) NULL,
  guide_sha256 CHAR(64) NOT NULL,
  progress_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress_total TINYINT UNSIGNED NOT NULL DEFAULT 10,
  progress_label VARCHAR(180) NULL,
  error_message VARCHAR(1000) NULL,
  active_version_id BIGINT UNSIGNED NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_dental_arch_jobs_public (public_id),
  KEY idx_dental_arch_jobs_status_created (status,created_at),
  KEY idx_dental_arch_jobs_member_created (member_id,created_at),
  KEY idx_dental_arch_jobs_capture (capture_session_id),
  CONSTRAINT fk_dental_arch_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dental_arch_jobs_member FOREIGN KEY (member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  CONSTRAINT fk_dental_arch_jobs_capture FOREIGN KEY (capture_session_id) REFERENCES capture_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_arch_job_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NOT NULL,
  source_detection_id BIGINT UNSIGNED NOT NULL,
  outline_detection_id BIGINT UNSIGNED NOT NULL,
  capture_region_id ENUM('front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open') NOT NULL,
  capture_region_index TINYINT UNSIGNED NOT NULL,
  source_kind ENUM('device','local_fill','manual_upload') NOT NULL DEFAULT 'device',
  status ENUM('waiting_outline','outline_completed','vision_processing','vision_completed','failed') NOT NULL DEFAULT 'waiting_outline',
  local_result_json JSON NULL,
  vision_result_json JSON NULL,
  joint_result_json JSON NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_dental_arch_job_region (job_id,capture_region_id),
  UNIQUE KEY uk_dental_arch_job_outline (outline_detection_id),
  KEY idx_dental_arch_job_images_source (source_detection_id),
  CONSTRAINT fk_dental_arch_job_images_job FOREIGN KEY (job_id) REFERENCES dental_arch_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_dental_arch_job_images_source FOREIGN KEY (source_detection_id) REFERENCES detections(id) ON DELETE CASCADE,
  CONSTRAINT fk_dental_arch_job_images_outline FOREIGN KEY (outline_detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_arch_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  job_id BIGINT UNSIGNED NOT NULL,
  version_number SMALLINT UNSIGNED NOT NULL,
  status ENUM('draft','confirmed','stale') NOT NULL DEFAULT 'draft',
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  result_json JSON NOT NULL,
  model_name VARCHAR(96) NOT NULL,
  guide_sha256 CHAR(64) NOT NULL,
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_dental_arch_versions_public (public_id),
  UNIQUE KEY uk_dental_arch_versions_number (job_id,version_number),
  KEY idx_dental_arch_versions_current (job_id,is_current),
  CONSTRAINT fk_dental_arch_versions_job FOREIGN KEY (job_id) REFERENCES dental_arch_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_dental_arch_versions_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_arch_tooth_views (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id BIGINT UNSIGNED NOT NULL,
  source_detection_id BIGINT UNSIGNED NOT NULL,
  capture_region_id ENUM('front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open') NOT NULL,
  tooth_fdi CHAR(2) NOT NULL,
  instance_id VARCHAR(24) NOT NULL,
  bbox_json JSON NOT NULL,
  contour_json JSON NOT NULL,
  darkline_json JSON NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
  review_status ENUM('accepted','needs_review','rejected') NOT NULL DEFAULT 'needs_review',
  source_method ENUM('local_vlm','manual') NOT NULL DEFAULT 'local_vlm',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_dental_arch_tooth_view (version_id,source_detection_id,tooth_fdi),
  KEY idx_dental_arch_tooth_lookup (version_id,tooth_fdi),
  KEY idx_dental_arch_tooth_source (source_detection_id),
  CONSTRAINT fk_dental_arch_tooth_version FOREIGN KEY (version_id) REFERENCES dental_arch_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_dental_arch_tooth_source FOREIGN KEY (source_detection_id) REFERENCES detections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='dental_arch_jobs' AND CONSTRAINT_NAME='fk_dental_arch_jobs_active_version')=0,
  'ALTER TABLE dental_arch_jobs ADD CONSTRAINT fk_dental_arch_jobs_active_version FOREIGN KEY (active_version_id) REFERENCES dental_arch_versions(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
