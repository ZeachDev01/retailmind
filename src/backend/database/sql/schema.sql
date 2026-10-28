-- RetailMind canonical fresh-install database schema.
-- Fresh inventory workflow includes procurement storage retirement (2026-10-15).
-- Direct fresh import includes current forecasting and platform migrations.
-- Complete schema with one Super Administrator and required defaults; business data starts empty.
-- To preserve existing records, import update.sql instead.
-- WARNING: Importing this file drops and recreates existing RetailMind tables.

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: inventory_system
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
-- These later migration tables have no DROP in the original dump section.
DROP TABLE IF EXISTS `cash_refund_exception_access`, `checkout_attempts`, `demand_forecasts`, `forecast_sales_imports`,
  `gcash_adjustments`, `gcash_funding_movements`, `gcash_service_sessions`, `gcash_transactions`,
  `inventory_draft_keys`, `inventory_submissions`, `ml_jobs`, `ml_model_evaluations`,
  `scheduled_demand_drivers`, `store_calendar`, `store_print_jobs`, `store_record_origins`;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `category` enum('store_operation','security','recovery','platform_setting','recovery_account') NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `previous_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_activity_log_category_created` (`category`,`created_at`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attention_settings`
--

DROP TABLE IF EXISTS `attention_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attention_settings` (
  `setting_scope` enum('platform','store') NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(100) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_scope`,`setting_key`),
  KEY `fk_attention_setting_actor` (`updated_by`),
  CONSTRAINT `fk_attention_setting_actor` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attention_states`
--

DROP TABLE IF EXISTS `attention_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attention_states` (
  `notification_level` int NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `attention_key` varchar(120) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `first_detected_at` datetime NOT NULL,
  `last_detected_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`attention_key`),
  KEY `idx_attention_states_active` (`user_id`,`is_active`,`last_detected_at`),
  CONSTRAINT `fk_attention_state_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `backup_history`
--

DROP TABLE IF EXISTS `backup_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_history` (
  `backup_id` bigint NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `backup_type` enum('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
  `file_size` bigint DEFAULT NULL,
  `status` enum('completed','failed') NOT NULL DEFAULT 'completed',
  `performed_by` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `snapshot_at` datetime DEFAULT NULL,
  `envelope_version` varchar(16) DEFAULT NULL,
  `cipher` varchar(64) DEFAULT NULL,
  `requested_by_role` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `backup_history_ibfk_1` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `barcode_pairings`
--

DROP TABLE IF EXISTS `barcode_pairings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barcode_pairings` (
  `pairing_id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(12) NOT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('pending','connected','expired') NOT NULL DEFAULT 'pending',
  `device_label` varchar(120) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `connected_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `access_token_hash` varchar(255) DEFAULT NULL,
  `joined_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`pairing_id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_barcode_pairings_code` (`code`),
  KEY `idx_barcode_pairings_expires_at` (`expires_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `barcode_pairings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `barcode_scans`
--

DROP TABLE IF EXISTS `barcode_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barcode_scans` (
  `scan_id` int(11) NOT NULL AUTO_INCREMENT,
  `pairing_id` int(11) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `payload` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`scan_id`),
  KEY `idx_barcode_scans_pairing_scan` (`pairing_id`,`scan_id`),
  CONSTRAINT `barcode_scans_ibfk_1` FOREIGN KEY (`pairing_id`) REFERENCES `barcode_pairings` (`pairing_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_name` varchar(100) NOT NULL,
  `branch_code` varchar(30) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch_id`),
  UNIQUE KEY `branch_name` (`branch_name`),
  UNIQUE KEY `branch_code` (`branch_code`),
  KEY `idx_branches_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cash_drawer_movements`
--

DROP TABLE IF EXISTS `cash_drawer_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_drawer_movements` (
  `drawer_movement_id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `movement_type` enum('pay_in','pay_out','cash_in','cash_out','safe_drop') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `cashier_id` int(11) NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`drawer_movement_id`),
  KEY `idx_drawer_movements_shift` (`shift_id`),
  KEY `idx_drawer_movements_cashier` (`cashier_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `cash_drawer_movements_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`),
  CONSTRAINT `cash_drawer_movements_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_drawer_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cash_refund_items`
--

DROP TABLE IF EXISTS `cash_refund_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_refund_items` (
  `refund_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `refund_id` int(11) NOT NULL,
  `sale_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `selling_form` enum('piece','pack') NOT NULL DEFAULT 'piece',
  `pack_size` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `disposition` enum('restockable','damaged') NOT NULL DEFAULT 'restockable',
  PRIMARY KEY (`refund_item_id`),
  UNIQUE KEY `uq_cash_refund_items_refund_line` (`refund_id`,`sale_item_id`),
  KEY `idx_cash_refund_items_sale_item` (`sale_item_id`),
  KEY `idx_cash_refund_items_product` (`product_id`),
  CONSTRAINT `fk_cash_refund_items_refund` FOREIGN KEY (`refund_id`) REFERENCES `cash_refunds` (`refund_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cash_refund_items_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`sale_item_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cash_refund_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cash_refunds`
--

DROP TABLE IF EXISTS `cash_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_refunds` (
  `refund_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','card','ewallet') NOT NULL DEFAULT 'cash',
  `reason` varchar(50) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `original_cashier_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `exception_reason` varchar(255) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`refund_id`),
  KEY `idx_cash_refunds_sale` (`sale_id`),
  KEY `idx_cash_refunds_shift` (`shift_id`),
  KEY `idx_cash_refunds_cashier` (`cashier_id`),
  CONSTRAINT `fk_cash_refunds_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cash_refunds_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cash_refunds_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cash_refunds_original_cashier_id` FOREIGN KEY (`original_cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cash_refunds_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_refund_exception_access` (
  `access_id` int(11) NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `approved_by` int(11) NOT NULL,
  `exception_reason` varchar(255) NOT NULL,
  `expires_at` bigint NOT NULL,
  `used_refund_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`access_id`),
  UNIQUE KEY `uq_refund_exception_token` (`token_hash`),
  FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`used_refund_id`) REFERENCES `cash_refunds` (`refund_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cashier_shifts`
--

DROP TABLE IF EXISTS `cashier_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_shifts` (
  `shift_id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` int(11) NOT NULL,
  `register_id` int(11) DEFAULT NULL,
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `opening_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `closed_at` timestamp NULL DEFAULT NULL,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `actual_cash` decimal(12,2) DEFAULT NULL,
  `cash_variance` decimal(12,2) DEFAULT NULL,
  `variance_threshold` decimal(12,2) DEFAULT NULL,
  `variance_review_required` tinyint(1) NOT NULL DEFAULT 0,
  `payment_totals` text DEFAULT NULL,
  `closing_notes` text DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `intervention_reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_draft_notes` text DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `review_shortage` decimal(12,2) DEFAULT NULL,
  `review_history` text DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `open_cashier_id` int(11) GENERATED ALWAYS AS (IF(status = 'open', cashier_id, NULL)) STORED,
  `open_register_id` int(11) GENERATED ALWAYS AS (IF(status = 'open', register_id, NULL)) STORED,
  PRIMARY KEY (`shift_id`),
  KEY `idx_cashier_shifts_cashier_status` (`cashier_id`,`status`),
  KEY `idx_cashier_shifts_register` (`register_id`),
  KEY `idx_cashier_shifts_variance_review` (`variance_review_required`,`status`,`closed_at`),
  UNIQUE KEY `uq_cashier_shifts_open_cashier` (`open_cashier_id`),
  UNIQUE KEY `uq_cashier_shifts_open_register` (`open_register_id`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `idx_cashier_shifts_closed_by` (`closed_by`),
  CONSTRAINT `cashier_shifts_ibfk_1` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `cashier_shifts_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cashier_shifts_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cashier_shifts_register` FOREIGN KEY (`register_id`) REFERENCES `registers` (`register_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `csv_import_logs`
--

DROP TABLE IF EXISTS `csv_import_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `csv_import_logs` (
  `import_id` int(11) NOT NULL AUTO_INCREMENT,
  `imported_by` int(11) NOT NULL,
  `import_type` enum('products','inventory') NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `total_rows` int(11) DEFAULT NULL,
  `successful_rows` int(11) DEFAULT NULL,
  `failed_rows` int(11) DEFAULT NULL,
  `import_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('completed','failed') DEFAULT 'completed',
  PRIMARY KEY (`import_id`),
  KEY `imported_by` (`imported_by`),
  CONSTRAINT `csv_import_logs_ibfk_1` FOREIGN KEY (`imported_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cycle_count_schedules`
--

DROP TABLE IF EXISTS `cycle_count_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cycle_count_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `abc_class` enum('A','B','C') NOT NULL DEFAULT 'C',
  `frequency_days` int(11) NOT NULL DEFAULT 90,
  `next_count_date` date NOT NULL,
  `priority_score` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('scheduled','completed','skipped') NOT NULL DEFAULT 'scheduled',
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`schedule_id`),
  KEY `idx_cycle_product_status` (`product_id`,`status`),
  KEY `idx_cycle_count_next_date` (`next_count_date`,`status`),
  KEY `assigned_to` (`assigned_to`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `cycle_count_schedules_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `cycle_count_schedules_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `cycle_count_schedules_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_delivery_log`
--

DROP TABLE IF EXISTS `email_delivery_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_delivery_log` (
  `email_log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed','queued') NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`email_log_id`),
  KEY `idx_email_delivery_log_created_at` (`created_at`),
  KEY `idx_email_delivery_log_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fiscal_period_locks`
--

DROP TABLE IF EXISTS `fiscal_period_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiscal_period_locks` (
  `lock_id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `locked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`lock_id`),
  KEY `period_id` (`period_id`),
  CONSTRAINT `fiscal_period_locks_ibfk_1` FOREIGN KEY (`period_id`) REFERENCES `fiscal_periods` (`period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fiscal_periods`
--

DROP TABLE IF EXISTS `fiscal_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiscal_periods` (
  `period_id` int(11) NOT NULL AUTO_INCREMENT,
  `period_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('open','closed','locked') DEFAULT 'open',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `open_period_key` tinyint GENERATED ALWAYS AS (IF(`status` = 'open', 1, NULL)) STORED,
  PRIMARY KEY (`period_id`),
  UNIQUE KEY `period_name` (`period_name`),
  UNIQUE KEY `uq_fiscal_period_one_open` (`open_period_key`),
  KEY `created_by` (`created_by`),
  KEY `closed_by` (`closed_by`),
  CONSTRAINT `fiscal_periods_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fiscal_periods_ibfk_2` FOREIGN KEY (`closed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `forecast_decisions`
--

DROP TABLE IF EXISTS `forecast_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forecast_decisions` (
  `forecast_decision_id` int(11) NOT NULL AUTO_INCREMENT,
  `prediction_id` int(11) DEFAULT NULL,
  `forecast_job_id` bigint unsigned DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `original_suggested_qty` int(11) NOT NULL DEFAULT 0,
  `final_quantity` int(11) NOT NULL DEFAULT 0,
  `decision` enum('accepted','modified','rejected') NOT NULL,
  `override_reason` text DEFAULT NULL,
  `decided_by` int(11) NOT NULL,
  `decided_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`forecast_decision_id`),
  KEY `idx_forecast_decisions_prediction` (`prediction_id`),
  KEY `fk_forecast_decisions_forecast_job` (`forecast_job_id`),
  KEY `product_id` (`product_id`),
  KEY `decided_by` (`decided_by`),
  CONSTRAINT `forecast_decisions_ibfk_1` FOREIGN KEY (`prediction_id`) REFERENCES `stock_predictions` (`prediction_id`),
  CONSTRAINT `fk_forecast_decisions_forecast_job` FOREIGN KEY (`forecast_job_id`) REFERENCES `ml_jobs` (`job_id`) ON DELETE RESTRICT,
  CONSTRAINT `forecast_decisions_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `forecast_decisions_ibfk_3` FOREIGN KEY (`decided_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `forecast_evaluations`
--

DROP TABLE IF EXISTS `forecast_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forecast_evaluations` (
  `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
  `training_run_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `mae` decimal(14,4) DEFAULT NULL,
  `rmse` decimal(14,4) DEFAULT NULL,
  `wape` decimal(10,4) DEFAULT NULL,
  `smape` decimal(10,4) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_forecast_evaluations_product` (`product_id`,`generated_at`),
  KEY `training_run_id` (`training_run_id`),
  CONSTRAINT `forecast_evaluations_ibfk_1` FOREIGN KEY (`training_run_id`) REFERENCES `model_training_runs` (`training_run_id`) ON DELETE SET NULL,
  CONSTRAINT `forecast_evaluations_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `forecast_runs`
--

DROP TABLE IF EXISTS `forecast_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forecast_runs` (
  `forecast_run_id` int(11) NOT NULL AUTO_INCREMENT,
  `model_name` varchar(100) NOT NULL,
  `model_version` varchar(20) NOT NULL,
  `forecast_period_days` int(11) NOT NULL DEFAULT 30,
  `evaluation_result` longtext DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`forecast_run_id`),
  KEY `idx_forecast_runs_generated_at` (`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `held_sales`
--

DROP TABLE IF EXISTS `held_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `held_sales` (
  `held_sale_id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) NOT NULL,
  `customer_label` varchar(120) DEFAULT NULL,
  `cart_json` longtext NOT NULL,
  `item_count` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('held','resumed','discarded','completed','expired') NOT NULL DEFAULT 'held',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `discard_reason` varchar(50) DEFAULT NULL,
  `discard_note` varchar(255) DEFAULT NULL,
  `discarded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`held_sale_id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_held_sales_cashier_status` (`cashier_id`,`status`),
  KEY `idx_held_sales_shift_status` (`shift_id`,`status`),
  CONSTRAINT `held_sales_ibfk_1` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_held_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_held_sales_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_held_sales_discarded_by` FOREIGN KEY (`discarded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `uq_inventory_product` (`product_id`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `chk_inventory_quantity_on_hand` CHECK (`quantity_on_hand` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_adjustments`
--

DROP TABLE IF EXISTS `inventory_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_adjustments` (
  `adjustment_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `adjustment_qty` int(11) NOT NULL,
  `adjustment_type` enum('damaged','missing','expired','other') NOT NULL,
  `reported_by` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','returned','cancelled') DEFAULT 'pending',
  `form` varchar(20) NOT NULL DEFAULT 'piece',
  `reported_qty` int(11) NOT NULL DEFAULT 0,
  `pack_size` int(11) DEFAULT NULL,
  PRIMARY KEY (`adjustment_id`),
  KEY `product_id` (`product_id`),
  KEY `reported_by` (`reported_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_inventory_adjustments_shift` (`shift_id`),
  KEY `idx_inventory_adjustments_status` (`status`),
  CONSTRAINT `inventory_adjustments_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `inventory_adjustments_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `inventory_adjustments_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_inventory_adjustments_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_adjustment_revisions`
--

DROP TABLE IF EXISTS `inventory_adjustment_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_adjustment_revisions` (
  `revision_id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`revision_id`),
  KEY `idx_adjustment_revisions_adjustment` (`adjustment_id`),
  CONSTRAINT `fk_adjustment_revisions_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_counts`
--

DROP TABLE IF EXISTS `inventory_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_counts` (
  `count_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `system_quantity` int(11) NOT NULL,
  `physical_quantity` int(11) NOT NULL,
  `difference_qty` int(11) NOT NULL,
  `system_sealed_quantity` int(11) NOT NULL DEFAULT 0,
  `system_loose_quantity` int(11) NOT NULL DEFAULT 0,
  `physical_sealed_quantity` int(11) NOT NULL DEFAULT 0,
  `physical_loose_quantity` int(11) NOT NULL DEFAULT 0,
  `difference_sealed_qty` int(11) NOT NULL DEFAULT 0,
  `difference_loose_qty` int(11) NOT NULL DEFAULT 0,
  `discrepancy_reason` text NOT NULL,
  `counted_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `counted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `related_adjustment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`count_id`),
  KEY `idx_inventory_counts_status` (`status`),
  KEY `idx_inventory_counts_product_counted_at` (`product_id`,`counted_at`),
  KEY `counted_by` (`counted_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_inventory_counts_related_adjustment` (`related_adjustment_id`),
  CONSTRAINT `inventory_counts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `inventory_counts_ibfk_2` FOREIGN KEY (`counted_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `inventory_counts_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_inventory_counts_related_adjustment` FOREIGN KEY (`related_adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_count_batches`
--

DROP TABLE IF EXISTS `inventory_count_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_count_batches` (
  `count_batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `count_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `system_quantity` int(11) NOT NULL DEFAULT 0,
  `physical_quantity` int(11) NOT NULL DEFAULT 0,
  `difference_qty` int(11) NOT NULL DEFAULT 0,
  `system_sealed_quantity` int(11) NOT NULL DEFAULT 0,
  `system_loose_quantity` int(11) NOT NULL DEFAULT 0,
  `physical_sealed_quantity` int(11) NOT NULL DEFAULT 0,
  `physical_loose_quantity` int(11) NOT NULL DEFAULT 0,
  `difference_sealed_qty` int(11) NOT NULL DEFAULT 0,
  `difference_loose_qty` int(11) NOT NULL DEFAULT 0,
  `expiration_date` date DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`count_batch_id`),
  KEY `idx_inventory_count_batches_count_id` (`count_id`),
  KEY `idx_inventory_count_batches_batch_id` (`batch_id`),
  CONSTRAINT `fk_inventory_count_batches_count` FOREIGN KEY (`count_id`) REFERENCES `inventory_counts` (`count_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_count_batches_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_login_attempts_identity` (`username`,`ip_address`,`attempted_at`),
  KEY `idx_login_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ml_settings`
--

DROP TABLE IF EXISTS `ml_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ml_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `ml_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `model_training_runs`
--

DROP TABLE IF EXISTS `model_training_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_training_runs` (
  `training_run_id` int(11) NOT NULL AUTO_INCREMENT,
  `model_name` varchar(100) NOT NULL,
  `model_version` varchar(30) NOT NULL,
  `trigger_type` enum('manual','scheduled','automatic','cli') NOT NULL DEFAULT 'cli',
  `status` enum('running','completed','failed','skipped') NOT NULL DEFAULT 'running',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` decimal(10,2) DEFAULT NULL,
  `sales_records_used` int(11) DEFAULT 0,
  `eligible_products` int(11) DEFAULT 0,
  `metrics_json` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`training_run_id`),
  KEY `idx_model_training_runs_started_at` (`started_at`),
  KEY `idx_model_training_runs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_preferences`
--

DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_preferences` (
  `pref_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `notify_low_stock` tinyint(1) DEFAULT 1,
  `notify_adjustment` tinyint(1) DEFAULT 0,
  `notify_email` tinyint(1) DEFAULT 0,
  `notify_inapp` tinyint(1) DEFAULT 1,
  `low_stock_threshold` int(11) DEFAULT 10,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pref_id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `workspace` varchar(30) NOT NULL DEFAULT '',
  `inapp_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email_pending` tinyint(1) NOT NULL DEFAULT 0,
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('low_stock','replenishment','adjustment','system','fiscal_period','expiring_stock','expired_stock','attention') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `attention_key` varchar(120) DEFAULT NULL,
  `attention_severity` varchar(20) DEFAULT NULL,
  `attention_count` int(11) DEFAULT NULL,
  `attention_destination` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user_id` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  KEY `idx_notifications_workspace` (`user_id`,`workspace`,`inapp_enabled`,`is_read`),
  KEY `idx_notifications_attention` (`user_id`,`attention_key`,`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `reset_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_password_reset_tokens_expiry` (`expires_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `platform_settings`
--

DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `fk_platform_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_platform_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_batches`
--

DROP TABLE IF EXISTS `product_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_batches` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `receiving_id` int(11) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `remaining_quantity` int(11) NOT NULL,
  `sealed_quantity` int(11) NOT NULL DEFAULT 0,
  `loose_quantity` int(11) NOT NULL DEFAULT 0,
  `expiration_date` date DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `date_received` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`batch_id`),
  KEY `idx_product_batches_product_expiry` (`product_id`,`expiration_date`),
  KEY `idx_product_batches_remaining_expiry` (`remaining_quantity`,`expiration_date`),
  KEY `receiving_id` (`receiving_id`),
  CONSTRAINT `product_batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `product_batches_ibfk_2` FOREIGN KEY (`receiving_id`) REFERENCES `stock_receiving` (`receiving_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `barcode` varchar(50) NOT NULL,
  `case_barcode` varchar(80) DEFAULT NULL,
  `pack_size` int(11) DEFAULT NULL,
  `pack_barcode` varchar(80) DEFAULT NULL,
  `pack_price` decimal(10,2) DEFAULT NULL,
  `parent_product_id` int(11) DEFAULT NULL,
  `variant_label` varchar(100) DEFAULT NULL,
  `product_name` varchar(150) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `quantity_purchased` int(11) NOT NULL DEFAULT 0,
  `quantity_sold` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) DEFAULT 10,
  `supplier` varchar(150) DEFAULT NULL,
  `preferred_supplier` varchar(150) DEFAULT NULL,
  `supplier_lead_time_days` int(11) NOT NULL DEFAULT 7,
  `safety_stock` int(11) NOT NULL DEFAULT 0,
  `minimum_order_quantity` int(11) NOT NULL DEFAULT 1,
  `units_per_package` int(11) NOT NULL DEFAULT 1,
  `base_unit` varchar(40) NOT NULL DEFAULT 'piece',
  `receiving_unit` varchar(40) NOT NULL DEFAULT 'package',
  `expiration_date` date DEFAULT NULL,
  `warning_lead_time_days` int(11) DEFAULT NULL,
  `product_image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `uq_products_branch_sku` (`branch_id`,`sku`),
  UNIQUE KEY `uq_products_branch_barcode` (`branch_id`,`barcode`),
  UNIQUE KEY `uq_products_branch_case_barcode` (`branch_id`,`case_barcode`),
  UNIQUE KEY `uq_products_branch_pack_barcode` (`branch_id`,`pack_barcode`),
  KEY `idx_products_product_name` (`product_name`),
  KEY `idx_products_status` (`status`),
  KEY `idx_products_branch_id` (`branch_id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`),
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `products_ibfk_3` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_products_unit_price` CHECK (`unit_price` >= 0),
  CONSTRAINT `chk_products_cost_price` CHECK (`cost_price` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `promotions`
--

DROP TABLE IF EXISTS `promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promotions` (
  `promotion_id` int(11) NOT NULL AUTO_INCREMENT,
  `promotion_name` varchar(150) NOT NULL,
  `discount_type` enum('percentage','fixed') NOT NULL,
  `discount_value` decimal(12,2) NOT NULL,
  `scope` enum('all','product','category') NOT NULL DEFAULT 'all',
  `product_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `minimum_quantity` int(11) NOT NULL DEFAULT 1,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`promotion_id`),
  KEY `idx_promotions_active_window` (`status`,`starts_at`,`ends_at`),
  KEY `product_id` (`product_id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `promotions_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `promotions_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE,
  CONSTRAINT `promotions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_history`
--

DROP TABLE IF EXISTS `purchase_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_history` (
  `purchase_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `quantity_purchased` int(11) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `purchase_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_cost` decimal(10,2) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`purchase_id`),
  KEY `product_id` (`product_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `purchase_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `purchase_history_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recovery_accounts`
--

DROP TABLE IF EXISTS `recovery_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recovery_accounts` (
  `account_key` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activation_secret_hash` varchar(255) NOT NULL,
  `activated_at` datetime DEFAULT NULL,
  `sealed_at` datetime DEFAULT NULL,
  `credentials_rotated_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`account_key`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_recovery_account_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `registers`
--

DROP TABLE IF EXISTS `registers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `registers` (
  `register_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `paper_width_mm` enum('80','58') NOT NULL DEFAULT '80',
  `disabled_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`register_id`),
  UNIQUE KEY `register_name` (`name`),
  KEY `idx_registers_status` (`status`),
  KEY `fk_registers_created_by` (`created_by`),
  CONSTRAINT `fk_registers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_item_batches`
--

DROP TABLE IF EXISTS `sale_item_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_item_batches` (
  `sale_item_batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_item_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `cost_status` enum('recorded','estimated','missing') NOT NULL DEFAULT 'missing',
  `cost_source` varchar(50) DEFAULT NULL,
  `cost_provenance` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`sale_item_batch_id`),
  KEY `idx_sale_item_batches_sale_item` (`sale_item_id`),
  KEY `idx_sale_item_batches_batch` (`batch_id`),
  CONSTRAINT `sale_item_batches_ibfk_1` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`sale_item_id`),
  CONSTRAINT `sale_item_batches_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_attempts` (
  `cashier_id` int(11) NOT NULL,
  `attempt_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `result_json` longtext NOT NULL,
  PRIMARY KEY (`cashier_id`,`attempt_id`),
  UNIQUE KEY `uq_checkout_attempt_sale` (`sale_id`),
  CONSTRAINT `fk_checkout_attempt_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_checkout_attempt_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `selling_form` enum('piece','pack') NOT NULL DEFAULT 'piece',
  `pack_size` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `idx_sale_items_product_id` (`product_id`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`),
  CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_reversal_items`
--

DROP TABLE IF EXISTS `sale_reversal_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_reversal_items` (
  `reversal_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `reversal_id` int(11) NOT NULL,
  `sale_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`reversal_item_id`),
  KEY `reversal_id` (`reversal_id`),
  KEY `sale_item_id` (`sale_item_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `sale_reversal_items_ibfk_1` FOREIGN KEY (`reversal_id`) REFERENCES `sale_reversals` (`reversal_id`),
  CONSTRAINT `sale_reversal_items_ibfk_2` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`sale_item_id`),
  CONSTRAINT `sale_reversal_items_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_reversals`
--

DROP TABLE IF EXISTS `sale_reversals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_reversals` (
  `reversal_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `reversal_type` enum('cancel','return','refund','exchange') NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reason` text NOT NULL,
  `settlement_method` enum('none','cash','card','ewallet','exchange') NOT NULL DEFAULT 'none',
  `refund_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  PRIMARY KEY (`reversal_id`),
  KEY `sale_id` (`sale_id`),
  KEY `requested_by` (`requested_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `sale_reversals_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`),
  CONSTRAINT `sale_reversals_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `sale_reversals_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `gross_amount` decimal(12,2) DEFAULT NULL,
  `discount_type` enum('none','percentage','fixed') NOT NULL DEFAULT 'none',
  `discount_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(255) DEFAULT NULL,
  `discount_authorized_by` int(11) DEFAULT NULL,
  `promotion_id` int(11) DEFAULT NULL,
  `promotion_name` varchar(150) DEFAULT NULL,
  `payment_method` enum('cash','card','ewallet') DEFAULT 'cash',
  `cash_received` decimal(10,2) DEFAULT NULL,
  `change_due` decimal(10,2) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `sale_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sale_id`),
  KEY `idx_sales_sale_date` (`sale_date`),
  KEY `cashier_id` (`cashier_id`),
  KEY `idx_sales_shift` (`shift_id`),
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_receipt_details`
-- Customer-facing Sale Receipt details are immutable transaction-time data.
DROP TABLE IF EXISTS `sale_receipt_details`;
CREATE TABLE `sale_receipt_details` (
  `sale_id` int(11) NOT NULL,
  `details_json` longtext NOT NULL,
  PRIMARY KEY (`sale_id`),
  CONSTRAINT `fk_sale_receipt_details_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `schema_migrations`
-- Customer-facing Refund Receipt details share the refund transaction.
DROP TABLE IF EXISTS `refund_receipt_details`;
CREATE TABLE `refund_receipt_details` (
  `refund_id` int(11) NOT NULL,
  `details_json` longtext NOT NULL,
  PRIMARY KEY (`refund_id`),
  CONSTRAINT `fk_refund_receipt_details_refund` FOREIGN KEY (`refund_id`) REFERENCES `cash_refunds` (`refund_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `migration_key` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `movement_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `change_qty` int(11) NOT NULL,
  `reason` enum('purchase','sale','adjustment','return','supplier_pullout') NOT NULL,
  `moved_by` int(11) DEFAULT NULL,
  `adjustment_id` int(11) DEFAULT NULL,
  `pullout_id` int(11) DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `idx_stock_movements_product_id` (`product_id`),
  KEY `idx_stock_movements_moved_at` (`moved_at`),
  KEY `idx_stock_movements_adjustment` (`adjustment_id`),
  KEY `idx_stock_movements_pullout` (`pullout_id`),
  KEY `moved_by` (`moved_by`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`moved_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_stock_movements_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_movements_pullout` FOREIGN KEY (`pullout_id`) REFERENCES `supplier_pullouts` (`pullout_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_issue_batches`
--

DROP TABLE IF EXISTS `stock_issue_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_issue_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `form` varchar(20) NOT NULL DEFAULT 'piece',
  `reported_qty` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stock_issue_batches_adjustment` (`adjustment_id`),
  KEY `idx_stock_issue_batches_batch` (`batch_id`),
  CONSTRAINT `fk_stock_issue_batches_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_issue_batches_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_predictions`
--

DROP TABLE IF EXISTS `stock_predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_predictions` (
  `prediction_id` int(11) NOT NULL AUTO_INCREMENT,
  `forecast_run_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `forecast_period_days` int(11) NOT NULL DEFAULT 30,
  `forecast_value` int(11) DEFAULT NULL,
  `predicted_demand_next_7_days` int(11) DEFAULT NULL,
  `predicted_demand_next_30_days` int(11) DEFAULT NULL,
  `forecasted_demand_during_lead_time` int(11) DEFAULT NULL,
  `actual_demand` int(11) DEFAULT NULL,
  `evaluation_result` longtext DEFAULT NULL,
  `supplier_lead_time_days` int(11) DEFAULT NULL,
  `safety_stock_used` int(11) DEFAULT NULL,
  `current_stock_used` int(11) DEFAULT NULL,
  `incoming_stock_used` int(11) DEFAULT NULL,
  `minimum_order_quantity_used` int(11) DEFAULT NULL,
  `units_per_package_used` int(11) DEFAULT NULL,
  `reorder_suggested` tinyint(1) DEFAULT 0,
  `suggested_reorder_qty` int(11) DEFAULT NULL,
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `lower_bound_7_days` int(11) DEFAULT NULL,
  `upper_bound_7_days` int(11) DEFAULT NULL,
  `lower_bound_30_days` int(11) DEFAULT NULL,
  `upper_bound_30_days` int(11) DEFAULT NULL,
  `lead_time_lower_bound` int(11) DEFAULT NULL,
  `lead_time_upper_bound` int(11) DEFAULT NULL,
  `forecast_explanation` text DEFAULT NULL,
  `model_version` varchar(20) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`prediction_id`),
  UNIQUE KEY `uq_stock_predictions_run_product_period` (`forecast_run_id`,`product_id`,`forecast_period_days`),
  KEY `idx_stock_predictions_forecast_run_id` (`forecast_run_id`),
  KEY `idx_stock_predictions_product_id` (`product_id`),
  CONSTRAINT `stock_predictions_ibfk_1` FOREIGN KEY (`forecast_run_id`) REFERENCES `forecast_runs` (`forecast_run_id`),
  CONSTRAINT `stock_predictions_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_receiving`
--

--
-- Table structure for table `supplier_pullouts`
--

DROP TABLE IF EXISTS `supplier_pullouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_pullouts` (
  `pullout_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `pulled_packs` int(11) NOT NULL DEFAULT 0,
  `pulled_loose` int(11) NOT NULL DEFAULT 0,
  `pack_size_used` int(11) NOT NULL DEFAULT 1,
  `reason` varchar(255) NOT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `pulled_by` int(11) NOT NULL,
  `pulled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`pullout_id`),
  KEY `idx_supplier_pullouts_product` (`product_id`),
  KEY `idx_supplier_pullouts_batch` (`batch_id`),
  KEY `idx_supplier_pullouts_pulled_by` (`pulled_by`),
  KEY `idx_supplier_pullouts_pulled_at` (`pulled_at`),
  CONSTRAINT `fk_supplier_pullouts_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `fk_supplier_pullouts_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`),
  CONSTRAINT `fk_supplier_pullouts_user` FOREIGN KEY (`pulled_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `stock_receiving_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_receiving_groups` (
  `group_id` int(11) NOT NULL AUTO_INCREMENT,
  `received_by` int(11) NOT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier` varchar(150) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`group_id`),
  KEY `idx_stock_receiving_groups_received_by` (`received_by`),
  KEY `idx_stock_receiving_groups_received_at` (`received_at`),
  CONSTRAINT `fk_stock_receiving_groups_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `stock_receiving`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_receiving` (
  `receiving_id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `received_qty` int(11) NOT NULL,
  `received_packages` int(11) NOT NULL DEFAULT 0,
  `units_per_package_used` int(11) NOT NULL DEFAULT 1,
  `accepted_qty` int(11) NOT NULL DEFAULT 0,
  `damaged_qty` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `received_by` int(11) NOT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier` varchar(150) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `discrepancy_type` enum('none','short','over','damaged','documentation','other') NOT NULL DEFAULT 'none',
  `discrepancy_qty` int(11) NOT NULL DEFAULT 0,
  `discrepancy_notes` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `pullout_id` int(11) DEFAULT NULL,
  `received_packs` int(11) NOT NULL DEFAULT 0,
  `accepted_packs` int(11) NOT NULL DEFAULT 0,
  `damaged_packs` int(11) NOT NULL DEFAULT 0,
  `rejected_packs` int(11) NOT NULL DEFAULT 0,
  `received_loose` int(11) NOT NULL DEFAULT 0,
  `accepted_loose` int(11) NOT NULL DEFAULT 0,
  `damaged_loose` int(11) NOT NULL DEFAULT 0,
  `rejected_loose` int(11) NOT NULL DEFAULT 0,
  `pack_size_used` int(11) DEFAULT NULL,
  PRIMARY KEY (`receiving_id`),
  KEY `idx_stock_receiving_group_id` (`group_id`),
  KEY `idx_stock_receiving_pullout_id` (`pullout_id`),
  KEY `product_id` (`product_id`),
  KEY `received_by` (`received_by`),
  CONSTRAINT `fk_stock_receiving_group` FOREIGN KEY (`group_id`) REFERENCES `stock_receiving_groups` (`group_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_receiving_pullout` FOREIGN KEY (`pullout_id`) REFERENCES `supplier_pullouts` (`pullout_id`) ON DELETE SET NULL,
  CONSTRAINT `stock_receiving_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_receiving_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `store_settings`
--

DROP TABLE IF EXISTS `store_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `store_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `store_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `theme_preference` enum('light','dark','system') NOT NULL DEFAULT 'system',
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `status` enum('active','disabled') DEFAULT 'active',
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `session_version` int(11) NOT NULL DEFAULT 1,
  `password_changed_at` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `branch_id` int(11) DEFAULT NULL,
  `is_recovery_account` tinyint(1) NOT NULL DEFAULT 0,
  `disabled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_branch_id` (`branch_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  CONSTRAINT `users_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'inventory_system'
--

--
-- Dumping routines for database 'inventory_system'
--

-- Tables added by reconciled migrations.

DROP TABLE IF EXISTS `backup_operations`;
CREATE TABLE `backup_operations` (
  `operation_key` varchar(64) NOT NULL,
  `active_slot` tinyint DEFAULT '1',
  `state` enum('capturing','completed','failed','abandoned') NOT NULL DEFAULT 'capturing',
  `requested_by` int DEFAULT NULL,
  `requested_by_role` varchar(32) DEFAULT NULL,
  `artifact_token` varchar(64) DEFAULT NULL,
  `artifact_path` varchar(255) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `file_size` bigint DEFAULT NULL,
  `envelope_version` varchar(16) DEFAULT NULL,
  `cipher` varchar(64) DEFAULT NULL,
  `snapshot_at` datetime DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `heartbeat_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `detail` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`operation_key`),
  UNIQUE KEY `uq_backup_operations_active` (`active_slot`),
  KEY `idx_backup_operations_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `store_write_gate`;
CREATE TABLE `store_write_gate` (
  `gate_key` varchar(64) NOT NULL,
  `paused_at` datetime DEFAULT NULL,
  `paused_by` int DEFAULT NULL,
  PRIMARY KEY (`gate_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `user_id` int NOT NULL,
  `role_id` int NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role` (`role_id`),
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed

-- Fresh-install identity and application defaults. Product and transaction tables start empty.
INSERT INTO branches (branch_id,branch_name,branch_code,status)
VALUES (1,'RetailMind Store','RETAILMIND-STORE','active');
INSERT INTO roles (role_id,role_name)
VALUES (1,'super_admin'),(2,'admin'),(3,'inventory_manager'),(4,'cashier');
INSERT INTO users (user_id,full_name,username,password_hash,role_id,status,must_change_password,branch_id)
VALUES (1,'Super Administrator','superadmin','$2y$12$NyXeX.4NatKwKV7BTtORJOlgve1O9ZaxtEEs1gUZihUecU6rkPcIS',1,'active',1,1);
INSERT INTO store_settings (setting_key,setting_value,updated_by) VALUES
('store_name','RetailMind Store',NULL),
('store_address','',NULL),
('currency_symbol','₱',NULL),
('timezone','Asia/Manila',NULL),
('receipt_footer','Thank you for shopping with us.',NULL);
INSERT INTO categories (category_name) VALUES
('Food'),('Snacks'),('Drinks'),('Bread'),('Personal'),('Household'),('School');
-- Required migration backfills and defaults for fresh installations.
INSERT IGNORE INTO user_roles (user_id,role_id,is_primary) SELECT user_id,role_id,1 FROM users WHERE role_id IS NOT NULL;
INSERT IGNORE INTO store_write_gate (gate_key) VALUES ('store_writes');
INSERT IGNORE INTO platform_settings (setting_key,setting_value) VALUES ('dormancy_disable_days','45'),('dormancy_warn_days','30');
INSERT INTO schema_migrations (migration_key,description) VALUES ('2026_07_operational_updates','Suppliers, purchase orders, shifts, held sales, forecast decisions, units, and inventory insights');
INSERT INTO schema_migrations (migration_key,description) VALUES ('202608040001_auth_security','Authentication security tables and mandatory password-change support') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202608040002_operational_updates','Operational workflow tables and columns') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202608040003_integrity_constraints','Foreign-key protections for upgraded operational databases') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130001_branch_management','Branches, branch-scoped users and privilege assignments') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130002_role_access_update','Make administrator inventory access read-only') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130003_superadmin_inventory_read_only','Make super administrator inventory access read-only') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130004_remove_seller_role','Replace the duplicate seller role with cashier') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609140001_branch_scoped_product_identifiers','Allow product identifiers to be reused across branches') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609170001_admin_profile_images','Nullable generated profile image filename for administrator accounts') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609170001_staff_profile_images','Optional profile image filename for staff accounts') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609170001_profile_images','Optional generated profile image filename for user accounts') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180001_singleton_store_scope','Resolve the singleton Store compatibility identity') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180002_audit_record_categories','Categorize Protected Audit Records for authority-scoped visibility') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180003_emergency_access','Durable reason-bound Emergency Access sessions and audit correlation') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180004_recovery_account','Sealed Recovery Account identity and offline lifecycle state') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180005_attention_foundation','Shared live attention settings, active state, and notification keys') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609230001_cashier_stock_issues','Link cashier stock-issue reports to shifts and stock movements (ticket #29)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609230002_stock_issue_corrections','Stock-issue correction lifecycle: returned/cancelled states and append-only revision trail (ticket #30)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609240001_stock_issue_oversight_correction_link','Link correction inventory counts to approved stock-issue reports for oversight history (ticket #31)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609240002_dormancy_policy_settings','Seed Dormancy Policy disable-days and warn-days Platform Settings (ticket #60)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609240003_user_disabled_at','Record when a user account transitions to Disabled (ticket #61)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609250001_user_multi_roles','Allow users to hold multiple role templates') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609260001_shared_database_backups','Shared encrypted Database Backup coordination for Administrator and Super Administrator') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290001_store_registers','Named physical Registers maintained by Administrators') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290002_cashier_shift_registers','Bind each open Cashier Shift to an exclusively owned Register (ticket #88)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290003_sale_shift_attribution','Attribute each new sale to the Cashier and Cashier Shift that authorized it (ticket #89)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290004_cashier_shift_register_lock','Let a Cashier lock an open Cashier Shift and resume it with their own password (ticket #90)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290005_held_sale_shift_ownership','Keep held sales inside the Cashier Shift that authorized them (ticket #91)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290006_append_only_cash_refunds','Record append-only Cash Refunds against completed sales (ticket #92)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300001_accountable_drawer_movements','Record accountable Cashier drawer movements (ticket #94)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300001_sale_receipt_details','Preserve customer-facing Sale Receipt details at checkout') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300002_cashier_shift_reconciliation','Preserve Cashier Shift reconciliation and review status (ticket #95)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300003_administrator_shift_intervention','Preserve the closing actor and intervention reason for Cashier Shifts (ticket #96)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300003_refund_receipt_details','Preserve customer-facing Refund Receipt details atomically') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300004_legacy_register_columns','Upgrade existing Register columns to the current Cashier schema') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300005_register_paper_width','Configure thermal receipt paper width per Register') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610010001_checkout_attempts','Persist Cashier checkout identities with committed sale outcomes') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610170001_cancel_checkout_attempts','Retain cancelled checkout identities without creating a sale') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610010003_safe_refund_exceptions','Preserve external settlement and specific Cash Refund exception attribution (#113)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610040001_user_theme','Personal display theme for every Staff account') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610180003_product_warning_lead_time','Optional warning_lead_time_days on products (ticket #223)') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610190004_gcash_funding_and_corrections','GCash service funding movements, linked corrections, and discrepancy waiver') ON DUPLICATE KEY UPDATE description=VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610240001_gcash_receipt_details','Preserve completed GCash Service Receipts') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Forecast defaults; retain any configured values.
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('minimum_history_days','30');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('preferred_history_days','90');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('minimum_nonzero_sales_days','5');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('history_window_days','365');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('forecast_period_days','30');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('n_estimators','300');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('max_depth','18');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('min_samples_split','4');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('min_samples_leaf','2');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('retrain_frequency_days','7');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('retrain_new_sales_records','100');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('accuracy_threshold_wape','35.0');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('prediction_interval_lower','10');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('prediction_interval_upper','90');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('holiday_dates','');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('schedule_daily_forecast_time','23:00');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('schedule_weekly_retrain_day','Sunday');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('schedule_weekly_retrain_time','01:00');

CREATE TABLE `gcash_service_sessions` (
  `session_id` int NOT NULL AUTO_INCREMENT,
  `shift_id` int NOT NULL,
  `cashier_id` int NOT NULL,
  `opening_service_cash` decimal(12,2) NOT NULL DEFAULT '0.00',
  `opening_wallet_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `opened_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `expected_service_cash` decimal(12,2) DEFAULT NULL,
  `expected_wallet_balance` decimal(12,2) DEFAULT NULL,
  `closing_service_cash` decimal(12,2) DEFAULT NULL,
  `closing_wallet_balance` decimal(12,2) DEFAULT NULL,
  `cash_variance` decimal(12,2) DEFAULT NULL,
  `wallet_variance` decimal(12,2) DEFAULT NULL,
  `closing_notes` text DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` int DEFAULT NULL,
  `unresolved_carryover_count` int NOT NULL DEFAULT '0',
  `waiver_notes` text DEFAULT NULL,
  `waived_by` int DEFAULT NULL,
  `waived_at` timestamp NULL DEFAULT NULL,
  `responsible_user_id` int DEFAULT NULL,
  `responsible_role` varchar(20) DEFAULT NULL,
  `responsibility_notes` text DEFAULT NULL,
  `responsibility_assigned_at` datetime DEFAULT NULL,
  `is_open` int GENERATED ALWAYS AS (if(`status` = 'open',1,NULL)) STORED,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uq_gcash_open_session` (`is_open`),
  KEY `idx_gcash_sessions_shift` (`shift_id`),
  KEY `idx_gcash_sessions_cashier` (`cashier_id`),
  KEY `idx_gcash_sessions_closed_by` (`closed_by`),
  KEY `idx_gcash_sessions_waived_by` (`waived_by`),
  KEY `idx_gcash_session_responsible` (`responsible_user_id`),
  CONSTRAINT `fk_gcash_session_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`),
  CONSTRAINT `fk_gcash_session_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_gcash_session_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_gcash_session_waived_by` FOREIGN KEY (`waived_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gcash_funding_movements` (
  `movement_id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `movement_type` enum('replenishment','withdrawal','product_drawer_transfer','owner_capital') NOT NULL,
  `fund_type` enum('cash','wallet') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `evidence_reference` varchar(120) DEFAULT NULL,
  `reason` varchar(255) NOT NULL,
  `authorized_by` int NOT NULL,
  `recorded_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `idx_gcash_funding_session` (`session_id`),
  KEY `idx_gcash_funding_auth` (`authorized_by`),
  KEY `idx_gcash_funding_rec` (`recorded_by`),
  CONSTRAINT `fk_gcash_funding_session` FOREIGN KEY (`session_id`) REFERENCES `gcash_service_sessions` (`session_id`),
  CONSTRAINT `fk_gcash_funding_auth` FOREIGN KEY (`authorized_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_gcash_funding_rec` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gcash_transactions` (
  `transaction_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int DEFAULT NULL,
  `shift_id` int DEFAULT NULL,
  `transaction_type` enum('Cash-In','Cash-Out','Payment') NOT NULL,
  `reference_number` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `principal_amount` decimal(12,2) DEFAULT NULL,
  `fee_amount` decimal(12,2) DEFAULT NULL,
  `fee_method` enum('cash','wallet') DEFAULT NULL,
  `service_cash_delta` decimal(12,2) NOT NULL DEFAULT '0.00',
  `wallet_delta` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gross_fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cashier_id` int NOT NULL,
  `responsible_user_id` int DEFAULT NULL,
  `responsible_role` varchar(20) DEFAULT NULL,
  `responsibility_notes` text DEFAULT NULL,
  `responsibility_assigned_at` datetime DEFAULT NULL,
  `confirmed_wallet` tinyint(1) NOT NULL DEFAULT 0,
  `confirmed_cash` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `verification_status` enum('Pending','Verified','Rejected','Completed','Needs checking') NOT NULL DEFAULT 'Pending',
  `verified_by` int DEFAULT NULL,
  `verification_note` varchar(500) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `receipt_details_json` longtext DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  UNIQUE KEY `uq_gcash_reference` (`reference_number`),
  KEY `idx_gcash_cashier_history` (`cashier_id`,`transaction_id`),
  KEY `idx_gcash_tx_responsible` (`responsible_user_id`),
  KEY `idx_gcash_transaction_session` (`session_id`),
  KEY `idx_gcash_transaction_shift` (`shift_id`),
  KEY `fk_gcash_reviewer` (`verified_by`),
  CONSTRAINT `fk_gcash_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_gcash_reviewer` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_gcash_transaction_session` FOREIGN KEY (`session_id`) REFERENCES `gcash_service_sessions` (`session_id`),
  CONSTRAINT `fk_gcash_transaction_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gcash_adjustments` (
  `adjustment_id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint unsigned NOT NULL,
  `session_id` int NOT NULL,
  `service_cash_delta` decimal(12,2) NOT NULL DEFAULT '0.00',
  `wallet_delta` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reason` varchar(255) NOT NULL,
  `authorized_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`adjustment_id`),
  KEY `idx_gcash_adj_tx` (`transaction_id`),
  KEY `idx_gcash_adj_session` (`session_id`),
  KEY `idx_gcash_adj_auth` (`authorized_by`),
  CONSTRAINT `fk_gcash_adj_tx` FOREIGN KEY (`transaction_id`) REFERENCES `gcash_transactions` (`transaction_id`),
  CONSTRAINT `fk_gcash_adj_session` FOREIGN KEY (`session_id`) REFERENCES `gcash_service_sessions` (`session_id`),
  CONSTRAINT `fk_gcash_adj_auth` FOREIGN KEY (`authorized_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ml_jobs (
    job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trigger_type VARCHAR(50) NOT NULL COMMENT 'e.g. forecast_generation, model_retraining',
    status VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued, running, succeeded, failed',
    requester_id INT NULL COMMENT 'User ID who requested it, NULL if scheduled',
    queued_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    operator_message VARCHAR(255) NULL COMMENT 'Safe user-facing message',
    diagnostic_reference TEXT NULL COMMENT 'Technical failure details',
    INDEX (status, queued_at),
    FOREIGN KEY (requester_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory draft encryption keys are delivered only to their authenticated Inventory Manager.
CREATE TABLE IF NOT EXISTS inventory_draft_keys (
    user_id INTEGER PRIMARY KEY,
    encryption_key VARCHAR(64) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_submissions (
    user_id INTEGER NOT NULL,
    operation_kind VARCHAR(40) NOT NULL,
    operation_token VARCHAR(64) NOT NULL,
    payload_hash VARCHAR(64) NOT NULL,
    result_json TEXT NOT NULL,
    PRIMARY KEY (user_id, operation_kind, operation_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `store_print_jobs` (
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

-- 202610250002_identity_provenance: Historical Actors cannot have ordinary roles.
ALTER TABLE users MODIFY role_id INT NULL;
CREATE TABLE `store_record_origins` (
    origin_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    table_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    local_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_details LONGTEXT NULL,
    PRIMARY KEY (origin_id, table_name, source_key),
    KEY idx_store_record_local (table_name, local_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description)
VALUES ('202610250002_identity_provenance','Retain roleless Historical Actors and installation-aware record provenance')
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Complete fresh-install structures also created by the PHP upgrade path.
CREATE TABLE `store_calendar` (
  `calendar_id` int NOT NULL AUTO_INCREMENT,
  `calendar_date` date NOT NULL,
  `status` enum('open','closed','holiday','exceptional-closure') NOT NULL,
  `recorded_by` int DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`calendar_id`),
  KEY `fk_store_calendar_user` (`recorded_by`),
  KEY `idx_store_calendar_history` (`calendar_date`,`recorded_at` DESC),
  CONSTRAINT `fk_store_calendar_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ml_model_evaluations` (
  `evaluation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_version` varchar(50) NOT NULL,
  `horizon_days` int NOT NULL COMMENT '7, 14, or 28',
  `mae` decimal(10,4) DEFAULT NULL,
  `rmse` decimal(10,4) DEFAULT NULL,
  `wape` decimal(10,4) DEFAULT NULL,
  `baseline_mae` decimal(10,4) DEFAULT NULL,
  `baseline_rmse` decimal(10,4) DEFAULT NULL,
  `baseline_wape` decimal(10,4) DEFAULT NULL,
  `missing_metric_reason` varchar(255) DEFAULT NULL,
  `data_window_start` date NOT NULL,
  `data_window_end` date NOT NULL,
  `candidate_gate_outcome` varchar(50) NOT NULL COMMENT 'passed, failed, incomplete',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_ml_model_evaluations_model_version` (`model_version`),
  KEY `idx_ml_model_evaluations_horizon` (`horizon_days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `demand_forecasts` (
  `forecast_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `product_id` int NOT NULL,
  `forecast_origin` date NOT NULL,
  `target_date` date NOT NULL,
  `horizon_day` int NOT NULL COMMENT '1 to 28',
  `predicted_units` int NOT NULL,
  `model_version` varchar(50) NOT NULL,
  `readiness_level` varchar(20) NOT NULL COMMENT 'Insufficient, Low, Medium, High',
  `limitation_marker` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`forecast_id`),
  KEY `idx_demand_forecasts_product_origin` (`product_id`,`forecast_origin`),
  KEY `idx_demand_forecasts_job` (`job_id`),
  CONSTRAINT `demand_forecasts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `demand_forecasts_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `ml_jobs` (`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `forecast_sales_imports` (
  `product_id` int NOT NULL,
  `sale_date` date NOT NULL,
  `quantity` int unsigned NOT NULL,
  `imported_by` int DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`product_id`,`sale_date`),
  KEY `imported_by` (`imported_by`),
  CONSTRAINT `forecast_sales_imports_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `forecast_sales_imports_ibfk_2` FOREIGN KEY (`imported_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scheduled_demand_drivers` (
  `driver_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `driver_type` varchar(50) NOT NULL,
  `effective_date` date NOT NULL,
  `scheduled_at` timestamp NOT NULL,
  `details_json` json NOT NULL,
  PRIMARY KEY (`driver_id`),
  KEY `idx_scheduled_demand_drivers_history` (`product_id`,`effective_date`,`scheduled_at` DESC),
  CONSTRAINT `fk_scheduled_demand_drivers_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration_key,description) VALUES
('202610090001_store_business_calendar','Record Store open days, holidays, and exceptional closures'),
('202610100000_ml_tables','Create ML jobs, evaluation, and demand forecasts tables'),
('202610100001_forecast_history_import','Imported daily demand history for forecasting'),
('202610100001_scheduled_demand_drivers','Record and track price and promotion schedules over time'),
('202610100002_forecast_history_backup_compatibility','Keep imported forecast history compatible with SQL database backups'),
('202610100002_forecasting_schedule_defaults','Add default forecasting schedules'),
('202610120001_forecast_decision_source','Retain demand forecast job/product sources alongside legacy prediction history'),
('202610150001_receiving_groups','Dedicated grouped receiving history and stable shopping trip grouping'),
('202610150002_retire_procurement_attention','Remove retired purchasing notifications and approval thresholds (#186)'),
('202610150003_retire_procurement_storage','Retire development procurement storage; preserve receiving and operational records'),
('202610180001_gcash_transactions','GCash reference records with explicit verification and unique references'),
('202610180001_inventory_count_batches','Track batch-level counted quantities, shortages, extras, and undated reconciliation items'),
('202610180002_stock_issue_batches','Track affected batches deducted during stock issue approval'),
('202610180004_supplier_pullouts','Record batch-specific Supplier Pullouts under Inventory Control (ticket #224)'),
('202610180005_sold_stock_cost_snapshots','Gross Profit: preserve sold-stock costs during checkout (ticket #228)'),
('202610180006_historical_sale_cost_recovery','Gross Profit: establish stable historical costs for older sales (ticket #230)'),
('202610190001_attention_notification_type','Allow shared attention notifications in the daily operational check'),
('202610190002_gcash_service_sessions','GCash service sessions for tracking service cash, wallet balances, and cashier handover'),
('202610190003_gcash_accountable_movements','GCash accountable money movements with principal, fee, deltas, and shift attribution'),
('202610200001_sales_packs_and_stock_forms','Support Sales Pack configuration and batch-level sealed and loose stock forms (issue #246)'),
('202610200002_stock_receiving_sales_pack_forms','Support Sales Pack and loose piece forms in stock receiving (issue #247)'),
('202610200003_sale_items_sales_pack_forms','Support selling_form and pack_size on sale_items (issue #251)'),
('202610200004_stock_counts_and_issues_forms','Support Sales Pack and loose piece forms in inventory counts and stock issues (issue #248)'),
('202610200005_supplier_movements_stock_forms','Preserve sealed packs and loose pieces on supplier pullouts and replacement receiving (issue #249)'),
('202610200006_cash_refund_sales_pack_forms','Support selling_form and pack_size on cash_refund_items (issue #252)'),
('202610210001_notification_workspaces','Scope notification history and pending daily email by Workspace'),
('202610220001_fiscal_period_lifecycle','Enforce one open fiscal period and fiscal lifecycle metadata'),
('202610250001_retire_unused_storage','Remove retired tables, unused columns, and Emergency Access setting'),
('202610250001_store_receipt_print_jobs','Queue saved receipts for the Store PC printer'),
('202610250002_inventory_recovery','Private inventory draft keys and committed submission identities (#316)'),
('202610270001_starter_product_categories','Add common Product categories when the Store has none');
