-- 1.6.11: Gridbox carts that received a product from the search results (orders in the e-mail report)
CREATE TABLE IF NOT EXISTS `#__bettersearch_carts` (
  `cart_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `query` varchar(120) NOT NULL,
  `day` date NOT NULL,
  PRIMARY KEY (`cart_id`, `item_id`),
  KEY `idx_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
