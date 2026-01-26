CREATE TABLE IF NOT EXISTS `#__tg_imported_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT NULL,
  `message_id` BIGINT NULL,
  `channel_id` BIGINT NULL,
  `imported_at` DATETIME NOT NULL,
  `article_id` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_channel_message` (`channel_id`, `message_id`),
  KEY `idx_post_id` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;