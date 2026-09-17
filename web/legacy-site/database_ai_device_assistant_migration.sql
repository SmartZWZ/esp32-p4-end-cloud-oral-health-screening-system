-- 在 phpMyAdmin 中先选择数据库 `8_138_230_100_666`，再执行本文件。
-- 设备语音助手记录：与网页端 ai_conversations / ai_messages 完全隔离。

CREATE TABLE IF NOT EXISTS ai_device_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NULL,
  device_public_id VARCHAR(32) NOT NULL,
  device_uid VARCHAR(96) NOT NULL,
  device_name VARCHAR(64) NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT '设备语音对话',
  instructions VARCHAR(1200) NOT NULL DEFAULT '你是齿镜设备上的语音助手。请使用简洁、友好的中文回答。你可以读取当前账号获授权的信息；仅当用户明确提出时，才可以调用服务器提供的受限设备控制工具。涉及成员的操作必须先确认成员。口腔健康内容仅用于健康科普和辅助建议，不替代医生诊断。',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ai_device_conversations_public_id (public_id),
  KEY idx_ai_device_conversations_user_updated (user_id, updated_at),
  KEY idx_ai_device_conversations_device_updated (device_id, updated_at),
  CONSTRAINT fk_ai_device_conversations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_device_conversations_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_device_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(32) NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ai_device_messages_public_id (public_id),
  KEY idx_ai_device_messages_conversation_created (conversation_id, created_at),
  CONSTRAINT fk_ai_device_messages_conversation FOREIGN KEY (conversation_id) REFERENCES ai_device_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
