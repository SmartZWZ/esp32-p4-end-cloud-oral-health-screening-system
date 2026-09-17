-- 齿镜：21 张采集参考图后台迁移（MySQL 5.7 / 8.0）
-- 只执行一次。为避免 #1046，请直接执行整份文件，不要切换到 information_schema。
USE `8_138_230_100_666`;

ALTER TABLE detections
  ADD COLUMN source_mirrored TINYINT(1) NOT NULL DEFAULT 0 AFTER image_bytes,
  ADD COLUMN normalization_applied ENUM('none','horizontal_flip') NOT NULL DEFAULT 'none' AFTER source_mirrored,
  ADD COLUMN orientation_normalized TINYINT(1) NOT NULL DEFAULT 0 AFTER normalization_applied;

CREATE TABLE IF NOT EXISTS capture_reference_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  version_code VARCHAR(32) NOT NULL,
  name VARCHAR(96) NOT NULL,
  description VARCHAR(500) NULL,
  hardware_profile VARCHAR(96) NOT NULL DEFAULT 'ESP32-P4 + OV5647',
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  published_by BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  archived_by BIGINT UNSIGNED NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_capture_reference_versions_public (public_id),
  UNIQUE KEY uk_capture_reference_versions_code (version_code),
  KEY idx_capture_reference_versions_status (status,created_at),
  CONSTRAINT fk_capture_reference_versions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_reference_versions_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_capture_reference_versions_archiver FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capture_reference_settings (
  id TINYINT UNSIGNED NOT NULL,
  active_version_id BIGINT UNSIGNED NULL,
  validation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  quality_model VARCHAR(96) NULL,
  quality_confidence_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.800,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_capture_reference_settings_version FOREIGN KEY (active_version_id) REFERENCES capture_reference_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_capture_reference_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO capture_reference_settings(id,active_version_id,validation_enabled) VALUES(1,NULL,0);

CREATE TABLE IF NOT EXISTS capture_reference_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  version_id BIGINT UNSIGNED NOT NULL,
  slot_index TINYINT UNSIGNED NOT NULL,
  slot_code VARCHAR(64) NOT NULL,
  region_id ENUM('front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open') NOT NULL,
  distance_label ENUM('too_far','too_close','good') NOT NULL,
  source_type ENUM('admin_upload','cloud_detection') NOT NULL,
  source_detection_id BIGINT UNSIGNED NULL,
  baseline_path VARCHAR(255) NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  image_width SMALLINT UNSIGNED NOT NULL,
  image_height SMALLINT UNSIGNED NOT NULL,
  image_bytes INT UNSIGNED NOT NULL,
  source_mirrored TINYINT(1) NOT NULL DEFAULT 0,
  normalization_applied ENUM('none','horizontal_flip') NOT NULL DEFAULT 'none',
  orientation_normalized TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(500) NULL,
  captured_distance VARCHAR(80) NULL,
  lighting_note VARCHAR(180) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_capture_reference_images_public (public_id),
  UNIQUE KEY uk_capture_reference_images_slot (version_id,region_id,distance_label),
  UNIQUE KEY uk_capture_reference_images_index (version_id,slot_index),
  KEY idx_capture_reference_images_source (source_detection_id),
  CONSTRAINT fk_capture_reference_images_version FOREIGN KEY (version_id) REFERENCES capture_reference_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_capture_reference_images_detection FOREIGN KEY (source_detection_id) REFERENCES detections(id) ON DELETE SET NULL,
  CONSTRAINT fk_capture_reference_images_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_capture_reference_images_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capture_reference_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  admin_user_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NULL,
  image_id BIGINT UNSIGNED NULL,
  operation VARCHAR(48) NOT NULL,
  detail_json JSON NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_capture_reference_audit_public (public_id),
  KEY idx_capture_reference_audit_created (created_at),
  KEY idx_capture_reference_audit_version (version_id,created_at),
  CONSTRAINT fk_capture_reference_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_reference_audit_version FOREIGN KEY (version_id) REFERENCES capture_reference_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_capture_reference_audit_image FOREIGN KEY (image_id) REFERENCES capture_reference_images(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capture_reference_tests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  admin_user_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NOT NULL,
  expected_region ENUM('front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open') NOT NULL,
  candidate_source ENUM('admin_upload','cloud_detection') NOT NULL,
  candidate_detection_id BIGINT UNSIGNED NULL,
  model_name VARCHAR(96) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  result_json JSON NULL,
  error_message VARCHAR(1000) NULL,
  latency_ms INT UNSIGNED NULL,
  human_verdict ENUM('accepted','too_far','too_close','wrong_region','blurred','lighting','framing','uncertain') NULL,
  human_note VARCHAR(500) NULL,
  labeled_by BIGINT UNSIGNED NULL,
  labeled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_capture_reference_tests_public (public_id),
  KEY idx_capture_reference_tests_created (created_at),
  CONSTRAINT fk_capture_reference_tests_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_reference_tests_version FOREIGN KEY (version_id) REFERENCES capture_reference_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_capture_reference_tests_detection FOREIGN KEY (candidate_detection_id) REFERENCES detections(id) ON DELETE SET NULL,
  CONSTRAINT fk_capture_reference_tests_labeler FOREIGN KEY (labeled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
