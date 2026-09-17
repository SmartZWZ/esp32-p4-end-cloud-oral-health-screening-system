-- 齿镜：口腔综合报告七视图档案升级（MySQL 5.7 / 8.0）
-- 执行前请在 phpMyAdmin 左侧选中齿镜业务数据库。
-- 本文件只需执行一次；不会修改或删除已有历史报告。

SET NAMES utf8mb4;

ALTER TABLE family_reports
  ADD COLUMN source_mode ENUM('recent_images','seven_view_archive') NOT NULL DEFAULT 'recent_images' AFTER symptoms,
  ADD COLUMN capture_session_id BIGINT UNSIGNED NULL AFTER source_mode,
  ADD COLUMN source_snapshot_json JSON NULL AFTER capture_session_id,
  ADD COLUMN source_fingerprint CHAR(64) NULL AFTER source_snapshot_json,
  ADD KEY idx_family_reports_capture (capture_session_id),
  ADD CONSTRAINT fk_family_reports_capture
    FOREIGN KEY (capture_session_id) REFERENCES capture_sessions(id) ON DELETE SET NULL;

ALTER TABLE family_report_images
  ADD COLUMN capture_region_id
    ENUM('front_bite','left_bite','right_bite','upper_left_open','upper_right_open','lower_left_open','lower_right_open')
    NULL AFTER sort_order,
  ADD COLUMN capture_region_index TINYINT UNSIGNED NULL AFTER capture_region_id,
  ADD COLUMN source_public_id_snapshot VARCHAR(32) NULL AFTER capture_region_index,
  ADD COLUMN source_created_at_snapshot DATETIME NULL AFTER source_public_id_snapshot,
  ADD COLUMN source_sha256 CHAR(64) NULL AFTER source_created_at_snapshot,
  ADD KEY idx_family_report_region (report_id,capture_region_index);

SHOW COLUMNS FROM family_reports;
SHOW COLUMNS FROM family_report_images;
