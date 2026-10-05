-- 1.5.0: searches per day (for the e-mail report); the t_params column is added by script.php
CREATE TABLE IF NOT EXISTS `#__bettersearch_daily` (
  `day` date NOT NULL,
  `query` varchar(120) NOT NULL,
  `searches` int(11) NOT NULL DEFAULT 0,
  `results` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `query`),
  KEY `idx_query` (`query`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
