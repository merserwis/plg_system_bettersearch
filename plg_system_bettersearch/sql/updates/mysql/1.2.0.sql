ALTER TABLE `#__bettersearch_items` ADD COLUMN `pcrc` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `#__bettersearch_log` ADD KEY `idx_last` (`last_at`);
