CREATE TABLE IF NOT EXISTS `#__aesirx_user_xref`
(
    `user_id`    int(11)  NULL DEFAULT NULL,
    `aesirx_id`  int(11)  NULL DEFAULT NULL,
    `created_at` datetime NOT NULL,
    UNIQUE KEY `idx_aesirx_id` (`aesirx_id`),
    UNIQUE KEY `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  DEFAULT COLLATE = utf8mb4_unicode_ci;

ALTER TABLE `#__aesirx_user_xref`
    ADD FOREIGN KEY (`user_id`) REFERENCES `#__users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL;
