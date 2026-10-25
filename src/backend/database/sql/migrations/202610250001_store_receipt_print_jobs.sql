CREATE TABLE IF NOT EXISTS `store_print_jobs` (
  `job_id` char(32) NOT NULL,
  `deployment` varchar(100) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `receipt_type` varchar(10) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `reprint_of` varchar(32) NOT NULL DEFAULT '',
  `payload` mediumblob NOT NULL,
  `status` varchar(16) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`job_id`),
  UNIQUE KEY `one_receipt_delivery` (`deployment`,`cashier_id`,`receipt_type`,`receipt_id`,`reprint_of`),
  KEY `pending_delivery` (`deployment`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
