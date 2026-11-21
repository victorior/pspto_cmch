-- Google OAuth: google_id en users, password_hash opcional.
ALTER TABLE `users`
  ADD COLUMN `google_id` varchar(255) DEFAULT NULL AFTER `email`,
  ADD COLUMN `avatar_url` varchar(512) DEFAULT NULL AFTER `full_name`,
  ADD UNIQUE KEY `uq_google_id` (`google_id`);

ALTER TABLE `users`
  MODIFY COLUMN `password_hash` varchar(255) NULL DEFAULT NULL;
