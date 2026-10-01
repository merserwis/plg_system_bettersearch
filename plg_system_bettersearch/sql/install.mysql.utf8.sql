CREATE TABLE IF NOT EXISTS `#__bettersearch_items` (
  `id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL DEFAULT 0,
  `category_id` int(11) NOT NULL DEFAULT 0,
  `cat_ids` varchar(2048) NOT NULL DEFAULT '',
  `t_title` text NOT NULL,
  `c_title` varchar(1024) NOT NULL DEFAULT '',
  `t_sku` text NOT NULL,
  `c_sku` varchar(2048) NOT NULL DEFAULT '',
  `t_fields` text NOT NULL,
  `c_fields` text NOT NULL,
  `t_cats` text NOT NULL,
  `c_cats` text NOT NULL,
  `c_main` mediumtext NOT NULL,
  `t_main` mediumtext NOT NULL,
  `t_body` mediumtext NOT NULL,
  `excerpt` text NOT NULL,
  `price` decimal(15,4) NULL DEFAULT NULL,
  `in_stock` tinyint(1) NOT NULL DEFAULT 1,
  `pcrc` int unsigned NOT NULL DEFAULT 0,
  `sig` bigint(20) NOT NULL DEFAULT 0,
  `indexed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_app` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__bettersearch_state` (
  `k` varchar(64) NOT NULL,
  `v` mediumtext NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__bettersearch_log` (
  `query` varchar(190) NOT NULL,
  `searches` int(11) NOT NULL DEFAULT 0,
  `results` int(11) NOT NULL DEFAULT 0,
  `last_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`query`),
  KEY `idx_searches` (`searches`),
  KEY `idx_last` (`last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
