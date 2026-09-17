-- 齿镜 AI 只读工具审计表。
-- 在 phpMyAdmin 先选择当前齿镜数据库，再执行本文件。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ai_tool_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  client_kind ENUM('web','device') NOT NULL,
  device_public_id VARCHAR(32) NULL,
  conversation_id VARCHAR(32) NULL,
  call_id VARCHAR(160) NULL,
  tool_name VARCHAR(64) NOT NULL,
  arguments_json JSON NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message VARCHAR(500) NULL,
  elapsed_ms INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ai_tool_audit_public_id (public_id),
  KEY idx_ai_tool_audit_user_created (user_id,created_at),
  KEY idx_ai_tool_audit_tool_created (tool_name,created_at),
  KEY idx_ai_tool_audit_call (call_id),
  CONSTRAINT fk_ai_tool_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
