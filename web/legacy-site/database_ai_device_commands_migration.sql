-- 齿镜 AI 设备控制：命令队列、执行事件和设备能力/状态。
-- 在 phpMyAdmin 中先选择当前齿镜数据库，再执行本文件。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS device_runtime_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id BIGINT UNSIGNED NOT NULL,
  firmware_version VARCHAR(64) NULL,
  capabilities_json JSON NULL,
  state_json JSON NULL,
  last_reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_device_runtime_device (device_id),
  KEY idx_device_runtime_reported (last_reported_at),
  CONSTRAINT fk_device_runtime_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_commands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  conversation_id VARCHAR(32) NULL,
  tool_call_id VARCHAR(160) NULL,
  source ENUM('web_ai','device_ai','local_ai') NOT NULL,
  command_name VARCHAR(64) NOT NULL,
  arguments_json JSON NOT NULL,
  status ENUM('queued','delivered','accepted','running','succeeded','failed','expired','cancelled') NOT NULL DEFAULT 'queued',
  result_json JSON NULL,
  error_message VARCHAR(500) NULL,
  expires_at DATETIME NOT NULL,
  delivered_at DATETIME NULL,
  acknowledged_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_device_commands_public_id (public_id),
  UNIQUE KEY uk_device_commands_user_tool_call (user_id,tool_call_id),
  KEY idx_device_commands_device_status_created (device_id,status,created_at),
  KEY idx_device_commands_user_created (user_id,created_at),
  KEY idx_device_commands_expires (expires_at),
  CONSTRAINT fk_device_commands_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_device_commands_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_command_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  command_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('queued','delivered','accepted','running','succeeded','failed','expired','cancelled') NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_device_command_events_command_created (command_id,created_at),
  CONSTRAINT fk_device_command_events_command FOREIGN KEY (command_id) REFERENCES device_commands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧设备对话中曾明确禁止硬件控制。本迁移只替换这一版默认文案，
-- 不覆盖用户自己写过的其他助手指令。
UPDATE ai_device_conversations
SET instructions='你是齿镜设备上的语音助手。请使用简洁、友好的中文回答。你可以读取当前账号的成员、图片和检测记录，并在用户明确要求时调用已授权的设备控制工具。口腔健康内容仅用于健康科普和辅助建议，不替代医生诊断。不得执行删除、解绑、修改网络、修改凭据或其他未授权操作。'
WHERE instructions LIKE '%不要尝试控制、配置或操作任何硬件设备%';
