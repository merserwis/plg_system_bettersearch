-- 1.4.0: conversion statistics, Search Console queries

CREATE TABLE IF NOT EXISTS `#__bettersearch_events` (
  `day` date NOT NULL,
  `query` varchar(120) NOT NULL,
  `item_id` int(11) NOT NULL,
  `kind` tinyint(1) NOT NULL,
  `hits` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `query`, `item_id`, `kind`),
  KEY `idx_query` (`query`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__bettersearch_gsc` (
  `query` varchar(190) NOT NULL,
  `clicks` int(11) NOT NULL DEFAULT 0,
  `impressions` int(11) NOT NULL DEFAULT 0,
  `position` decimal(8,2) NOT NULL DEFAULT 0,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`query`),
  KEY `idx_impressions` (`impressions`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
