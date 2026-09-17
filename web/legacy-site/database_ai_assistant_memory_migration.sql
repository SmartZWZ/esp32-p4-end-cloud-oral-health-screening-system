-- 齿镜 AI 助手会话记忆迁移
-- 在 phpMyAdmin 中先选择当前齿镜数据库，再完整执行本文件。
-- 网页助手与设备助手继续使用两套独立表，任何历史都不会相互混用。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS chijing_add_column;
DELIMITER $$
CREATE PROCEDURE chijing_add_column(IN table_name_value VARCHAR(64),IN column_name_value VARCHAR(64),IN definition_value TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=table_name_value AND COLUMN_NAME=column_name_value
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',table_name_value,'` ADD COLUMN `',column_name_value,'` ',definition_value);
    PREPARE statement_value FROM @ddl;
    EXECUTE statement_value;
    DEALLOCATE PREPARE statement_value;
  END IF;
END$$
DELIMITER ;

CALL chijing_add_column('ai_conversations','memory_enabled','TINYINT(1) NOT NULL DEFAULT 1 AFTER `instructions`');
CALL chijing_add_column('ai_conversations','summary_text','TEXT NULL AFTER `memory_enabled`');
CALL chijing_add_column('ai_conversations','summary_up_to_message_id','BIGINT UNSIGNED NULL AFTER `summary_text`');
CALL chijing_add_column('ai_conversations','summary_updated_at','DATETIME NULL AFTER `summary_up_to_message_id`');
CALL chijing_add_column('ai_conversations','context_status','ENUM(''ready'',''partial'',''failed'') NOT NULL DEFAULT ''ready'' AFTER `summary_updated_at`');
CALL chijing_add_column('ai_conversations','context_message_count','SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `context_status`');
CALL chijing_add_column('ai_conversations','last_message_at','DATETIME NULL AFTER `context_message_count`');

CALL chijing_add_column('ai_device_conversations','memory_enabled','TINYINT(1) NOT NULL DEFAULT 1 AFTER `instructions`');
CALL chijing_add_column('ai_device_conversations','summary_text','TEXT NULL AFTER `memory_enabled`');
CALL chijing_add_column('ai_device_conversations','summary_up_to_message_id','BIGINT UNSIGNED NULL AFTER `summary_text`');
CALL chijing_add_column('ai_device_conversations','summary_updated_at','DATETIME NULL AFTER `summary_up_to_message_id`');
CALL chijing_add_column('ai_device_conversations','context_status','ENUM(''ready'',''partial'',''failed'') NOT NULL DEFAULT ''ready'' AFTER `summary_updated_at`');
CALL chijing_add_column('ai_device_conversations','context_message_count','SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `context_status`');
CALL chijing_add_column('ai_device_conversations','last_message_at','DATETIME NULL AFTER `context_message_count`');
CALL chijing_add_column('ai_device_conversations','is_active','TINYINT(1) NOT NULL DEFAULT 1 AFTER `last_message_at`');
DROP PROCEDURE chijing_add_column;

SET @index_exists=(
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_device_conversations' AND INDEX_NAME='idx_ai_device_active'
);
SET @index_ddl=IF(@index_exists=0,
  'CREATE INDEX `idx_ai_device_active` ON `ai_device_conversations` (`device_public_id`,`is_active`,`updated_at`)',
  'SELECT 1');
PREPARE index_statement FROM @index_ddl;
EXECUTE index_statement;
DEALLOCATE PREPARE index_statement;

CREATE TABLE IF NOT EXISTS ai_assistant_memory_settings (
  id TINYINT UNSIGNED NOT NULL,
  context_turns SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  summary_max_chars SMALLINT UNSIGNED NOT NULL DEFAULT 2000,
  context_max_chars SMALLINT UNSIGNED NOT NULL DEFAULT 12000,
  device_idle_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  summary_model VARCHAR(96) NOT NULL DEFAULT 'qwen-plus',
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ai_memory_settings_user
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_assistant_memory_settings
  (id,context_turns,summary_max_chars,context_max_chars,device_idle_minutes,retention_days,summary_model)
VALUES
  (1,10,2000,12000,30,0,'qwen-plus')
ON DUPLICATE KEY UPDATE id=VALUES(id);

UPDATE ai_conversations c
LEFT JOIN (
  SELECT conversation_id,MAX(created_at) AS last_message_at
  FROM ai_messages GROUP BY conversation_id
) m ON m.conversation_id=c.id
SET c.last_message_at=m.last_message_at
WHERE c.last_message_at IS NULL AND m.last_message_at IS NOT NULL;

UPDATE ai_device_conversations c
LEFT JOIN (
  SELECT conversation_id,MAX(created_at) AS last_message_at
  FROM ai_device_messages GROUP BY conversation_id
) m ON m.conversation_id=c.id
SET c.last_message_at=m.last_message_at
WHERE c.last_message_at IS NULL AND m.last_message_at IS NOT NULL;
