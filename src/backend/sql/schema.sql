 -- RetailMind canonical database schema and seed data
-- This file is the single source of truth for fresh database imports.
-- It contains the complete schema plus Shalom Main, accounts, products, stock, and required settings.
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
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `backup_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `backup_type` enum('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
  `file_size` bigint(20) DEFAULT NULL,
  `status` enum('completed','failed') NOT NULL DEFAULT 'completed',
  `performed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`backup_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `backup_history_ibfk_1` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `movement_type` enum('pay_in','pay_out') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`drawer_movement_id`),
  KEY `idx_drawer_movements_shift` (`shift_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `cash_drawer_movements_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`),
  CONSTRAINT `cash_drawer_movements_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
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
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `opening_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `closed_at` timestamp NULL DEFAULT NULL,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `actual_cash` decimal(12,2) DEFAULT NULL,
  `cash_variance` decimal(12,2) DEFAULT NULL,
  `closing_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`shift_id`),
  KEY `idx_cashier_shifts_cashier_status` (`cashier_id`,`status`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `cashier_shifts_ibfk_1` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `cashier_shifts_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3078 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `error_details` text DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `emergency_access_sessions`
--

DROP TABLE IF EXISTS `emergency_access_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `emergency_access_sessions` (
  `session_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `activated_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `duration_minutes` smallint(5) unsigned NOT NULL,
  `status` enum('active','expired','revoked') NOT NULL DEFAULT 'active',
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_emergency_actor_status` (`actor_user_id`,`status`,`expires_at`),
  KEY `fk_emergency_revoker` (`revoked_by`),
  CONSTRAINT `fk_emergency_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_emergency_revoker` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  PRIMARY KEY (`period_id`),
  UNIQUE KEY `period_name` (`period_name`),
  KEY `created_by` (`created_by`),
  KEY `closed_by` (`closed_by`),
  CONSTRAINT `fiscal_periods_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fiscal_periods_ibfk_2` FOREIGN KEY (`closed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `forecast_decisions`
--

DROP TABLE IF EXISTS `forecast_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `forecast_decisions` (
  `forecast_decision_id` int(11) NOT NULL AUTO_INCREMENT,
  `prediction_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `original_suggested_qty` int(11) NOT NULL DEFAULT 0,
  `final_quantity` int(11) NOT NULL DEFAULT 0,
  `decision` enum('accepted','modified','rejected') NOT NULL,
  `override_reason` text DEFAULT NULL,
  `decided_by` int(11) NOT NULL,
  `decided_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `replenishment_request_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`forecast_decision_id`),
  KEY `idx_forecast_decisions_prediction` (`prediction_id`),
  KEY `product_id` (`product_id`),
  KEY `decided_by` (`decided_by`),
  KEY `replenishment_request_id` (`replenishment_request_id`),
  CONSTRAINT `forecast_decisions_ibfk_1` FOREIGN KEY (`prediction_id`) REFERENCES `stock_predictions` (`prediction_id`),
  CONSTRAINT `forecast_decisions_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `forecast_decisions_ibfk_3` FOREIGN KEY (`decided_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `forecast_decisions_ibfk_4` FOREIGN KEY (`replenishment_request_id`) REFERENCES `replenishment_requests` (`request_id`) ON DELETE SET NULL
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
  `evaluation_records` int(11) NOT NULL DEFAULT 0,
  `actual_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `predicted_total` decimal(14,2) NOT NULL DEFAULT 0.00,
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
  `status` enum('held','resumed','cancelled','expired') NOT NULL DEFAULT 'held',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`held_sale_id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_held_sales_cashier_status` (`cashier_id`,`status`),
  KEY `shift_id` (`shift_id`),
  CONSTRAINT `held_sales_ibfk_1` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `held_sales_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL
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
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `host_name` varchar(150) DEFAULT NULL,
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
  `notify_replenishment` tinyint(1) DEFAULT 1,
  `notify_adjustment` tinyint(1) DEFAULT 0,
  `notify_email` tinyint(1) DEFAULT 0,
  `notify_inapp` tinyint(1) DEFAULT 1,
  `low_stock_threshold` int(11) DEFAULT 10,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pref_id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('low_stock','replenishment','adjustment','system','fiscal_period','expiring_stock','expired_stock') NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
-- Table structure for table `privileges`
--

DROP TABLE IF EXISTS `privileges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `privileges` (
  `privilege_id` int(11) NOT NULL AUTO_INCREMENT,
  `privilege_key` varchar(80) NOT NULL,
  `privilege_name` varchar(120) NOT NULL,
  PRIMARY KEY (`privilege_id`),
  UNIQUE KEY `privilege_key` (`privilege_key`),
  UNIQUE KEY `privilege_name` (`privilege_name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `expiration_date` date DEFAULT NULL,
  `date_received` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`batch_id`),
  KEY `idx_product_batches_product_expiry` (`product_id`,`expiration_date`),
  KEY `idx_product_batches_remaining_expiry` (`remaining_quantity`,`expiration_date`),
  KEY `receiving_id` (`receiving_id`),
  CONSTRAINT `product_batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `product_batches_ibfk_2` FOREIGN KEY (`receiving_id`) REFERENCES `stock_receiving` (`receiving_id`)
) ENGINE=InnoDB AUTO_INCREMENT=517 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `product_image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `uq_products_branch_sku` (`branch_id`,`sku`),
  UNIQUE KEY `uq_products_branch_barcode` (`branch_id`,`barcode`),
  UNIQUE KEY `uq_products_branch_case_barcode` (`branch_id`,`case_barcode`),
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
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=517 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `purchase_order_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(11) NOT NULL,
  `replenishment_request_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `ordered_qty` int(11) NOT NULL,
  `received_qty` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`purchase_order_item_id`),
  UNIQUE KEY `uq_po_request` (`purchase_order_id`,`replenishment_request_id`),
  KEY `idx_po_items_product` (`product_id`),
  KEY `replenishment_request_id` (`replenishment_request_id`),
  CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`purchase_order_id`),
  CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`replenishment_request_id`) REFERENCES `replenishment_requests` (`request_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_items_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `purchase_order_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(60) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `status` enum('draft','approved','sent','partially_received','fully_received','cancelled') NOT NULL DEFAULT 'draft',
  `expected_delivery_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`purchase_order_id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `idx_purchase_orders_status` (`status`),
  KEY `idx_purchase_orders_supplier` (`supplier_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
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
-- Table structure for table `replenishment_requests`
--

DROP TABLE IF EXISTS `replenishment_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `replenishment_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `request_qty` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','partially_received','rejected','received') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `source` enum('manual','ml_forecast') DEFAULT 'manual',
  `forecast_prediction_id` int(11) DEFAULT NULL,
  `original_suggested_qty` int(11) DEFAULT NULL,
  `override_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `product_id` (`product_id`),
  KEY `requested_by` (`requested_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `replenishment_requests_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `replenishment_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `replenishment_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_replenishment_requests_quantity` CHECK (`request_qty` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_privileges`
--

DROP TABLE IF EXISTS `role_privileges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_privileges` (
  `role_id` int(11) NOT NULL,
  `privilege_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`privilege_id`),
  KEY `privilege_id` (`privilege_id`),
  CONSTRAINT `role_privileges_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `role_privileges_ibfk_2` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`privilege_id`) ON DELETE CASCADE
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  PRIMARY KEY (`sale_item_batch_id`),
  KEY `idx_sale_item_batches_sale_item` (`sale_item_id`),
  KEY `idx_sale_item_batches_batch` (`batch_id`),
  CONSTRAINT `sale_item_batches_ibfk_1` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`sale_item_id`),
  CONSTRAINT `sale_item_batches_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19207 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `idx_sale_items_product_id` (`product_id`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`),
  CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19239 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `exchange_details` text DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9545 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schema_migrations`
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
  `reason` enum('purchase','sale','adjustment','return') NOT NULL,
  `moved_by` int(11) DEFAULT NULL,
  `adjustment_id` int(11) DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `idx_stock_movements_product_id` (`product_id`),
  KEY `idx_stock_movements_moved_at` (`moved_at`),
  KEY `idx_stock_movements_adjustment` (`adjustment_id`),
  KEY `moved_by` (`moved_by`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`moved_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_stock_movements_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19723 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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

DROP TABLE IF EXISTS `stock_receiving`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_receiving` (
  `receiving_id` int(11) NOT NULL AUTO_INCREMENT,
  `replenishment_request_id` int(11) DEFAULT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `purchase_order_item_id` int(11) DEFAULT NULL,
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
  `po_number` varchar(100) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `discrepancy_type` enum('none','short','over','damaged','documentation','other') NOT NULL DEFAULT 'none',
  `discrepancy_qty` int(11) NOT NULL DEFAULT 0,
  `discrepancy_notes` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`receiving_id`),
  KEY `replenishment_request_id` (`replenishment_request_id`),
  KEY `product_id` (`product_id`),
  KEY `received_by` (`received_by`),
  CONSTRAINT `stock_receiving_ibfk_1` FOREIGN KEY (`replenishment_request_id`) REFERENCES `replenishment_requests` (`request_id`),
  CONSTRAINT `stock_receiving_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_receiving_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=469 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
-- Table structure for table `supplier_products`
--

DROP TABLE IF EXISTS `supplier_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_products` (
  `supplier_product_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `supplier_sku` varchar(100) DEFAULT NULL,
  `last_unit_cost` decimal(12,2) DEFAULT NULL,
  `minimum_order_quantity` int(11) NOT NULL DEFAULT 1,
  `lead_time_days` int(11) NOT NULL DEFAULT 7,
  `is_preferred` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`supplier_product_id`),
  UNIQUE KEY `uq_supplier_product` (`supplier_id`,`product_id`),
  KEY `idx_supplier_products_product` (`product_id`),
  CONSTRAINT `supplier_products_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  CONSTRAINT `supplier_products_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `standard_lead_time_days` int(11) NOT NULL DEFAULT 7,
  `minimum_order_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `supplier_name` (`supplier_name`),
  KEY `idx_suppliers_status` (`status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_privileges`
--

DROP TABLE IF EXISTS `user_privileges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_privileges` (
  `user_id` int(11) NOT NULL,
  `privilege_id` int(11) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`,`privilege_id`),
  KEY `privilege_id` (`privilege_id`),
  CONSTRAINT `user_privileges_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_privileges_ibfk_2` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`privilege_id`) ON DELETE CASCADE
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_branch_id` (`branch_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  CONSTRAINT `users_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'inventory_system'
--

--
-- Dumping routines for database 'inventory_system'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed

-- Portable application data
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
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_code`, `status`, `created_by`, `created_at`, `updated_at`) VALUES (1,'Shalom Main','0001','active',1,'2026-09-14 22:27:15','2026-09-14 22:27:15');
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` (`role_id`, `role_name`) VALUES (2,'admin'),(4,'cashier'),(3,'inventory_manager'),(1,'super_admin');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `privileges`
--

LOCK TABLES `privileges` WRITE;
/*!40000 ALTER TABLE `privileges` DISABLE KEYS */;
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES (1,'manage_users','Manage user accounts'),(2,'manage_roles','Assign user roles'),(3,'manage_privileges','Manage user privileges'),(4,'manage_branches','Create and manage branches'),(5,'manage_inventory','Manage branch inventory'),(6,'view_inventory','View branch inventory');
/*!40000 ALTER TABLE `privileges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `role_privileges`
--

LOCK TABLES `role_privileges` WRITE;
/*!40000 ALTER TABLE `role_privileges` DISABLE KEYS */;
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES (1,1),(1,2),(1,3),(1,4),(1,6),(2,1),(2,2),(2,3),(2,4),(2,6),(3,5),(3,6),(4,6);
/*!40000 ALTER TABLE `role_privileges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `profile_image`, `password_hash`, `role_id`, `status`, `failed_login_attempts`, `locked_until`, `last_login_at`, `session_version`, `password_changed_at`, `must_change_password`, `branch_id`, `is_recovery_account`, `created_at`) VALUES (1,'Super Admin','superadmin','superadmin@inventory.local','b8603d0f10c1be3fc3c819919e417a58429f910bcb825a291689da684886960d.png','$2y$10$jyoib.jrVfRdSWBvxaKT9eBEqBXT7a9YIuxfSAmpzp9hemBttdO4y',1,'active',0,NULL,'2026-09-18 14:14:22',2,'2026-09-14 15:30:05',0,NULL,0,'2026-09-14 07:29:33'),(2,'MIKE SORDILLA','Mike','sordillamike1@gmail.com',NULL,'$2y$10$SeRPHt6JdMTWd7EnxPfCBe/Z8HapmxoUHOEOwI93YNne042BIPeiy',2,'active',0,NULL,'2026-09-17 21:33:41',5,'2026-09-17 21:37:42',0,1,0,'2026-09-14 22:27:46'),(9,'RetailMind Sales Trend Seed','rm_seed_sales_trend','rm-seed-sales-trend@example.test',NULL,'$2y$12$8epqsnlICawcEEQb5rgyt.m5e9/rLuvicEGJNJMH.XRAYWXFPB4..',4,'disabled',0,NULL,NULL,1,NULL,1,1,0,'2026-09-17 00:39:26'),(11,'RetailMind Sales Seed Receiver','rm_seed_sales_receiver','rm-seed-sales-receiver@example.test','b1e1ee08e88abf5c8f0eda5f2e4352ac6f64c3db035d22e3ac8b6751dfa882f7.png','$2y$12$x9KFvnz7f2g8.dy827WKI.fb0SRc2QxFW2jJUkbwlHuKz0IiV9PMq',2,'disabled',0,NULL,NULL,1,NULL,1,1,0,'2026-09-17 00:43:42');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `user_privileges`
--

LOCK TABLES `user_privileges` WRITE;
/*!40000 ALTER TABLE `user_privileges` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_privileges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `recovery_accounts`
--

LOCK TABLES `recovery_accounts` WRITE;
/*!40000 ALTER TABLE `recovery_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `recovery_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `notification_preferences`
--

LOCK TABLES `notification_preferences` WRITE;
/*!40000 ALTER TABLE `notification_preferences` DISABLE KEYS */;
INSERT INTO `notification_preferences` (`pref_id`, `user_id`, `notify_low_stock`, `notify_replenishment`, `notify_adjustment`, `notify_email`, `notify_inapp`, `low_stock_threshold`, `updated_at`) VALUES (1,1,1,1,1,1,1,10,'2026-09-14 23:15:22');
/*!40000 ALTER TABLE `notification_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` (`category_id`, `category_name`) VALUES (1,'Beverages'),(2,'Snacks'),(3,'Pantry'),(4,'Dairy'),(5,'Bakery'),(6,'Produce'),(7,'Frozen Foods'),(8,'Household'),(9,'Personal Care'),(10,'Baby Care'),(11,'Pet Care');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES (1,'SKU-BEV-001','890100000001',NULL,NULL,NULL,'Spring Water 500ml','AquaPure',1,0.75,0.32,206,94,24,'ClearSpring Distributors','ClearSpring Distributors',3,12,24,24,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(2,'SKU-BEV-002','890100000002',NULL,NULL,NULL,'Spring Water 1.5L','AquaPure',1,1.35,0.62,244,111,18,'ClearSpring Distributors','ClearSpring Distributors',3,8,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(3,'SKU-BEV-003','890100000003',NULL,NULL,NULL,'Sparkling Water 330ml','FizzWell',1,1.10,0.48,90,0,18,'ClearSpring Distributors','ClearSpring Distributors',4,8,24,24,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(4,'SKU-BEV-004','890100000004',NULL,NULL,NULL,'Cola Can 330ml','RefreshCo',1,1.25,0.58,340,66,24,'Metro Beverage Supply','Metro Beverage Supply',4,12,24,24,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(5,'SKU-BEV-005','890100000005',NULL,NULL,NULL,'Orange Soda 500ml','RefreshCo',1,1.50,0.70,0,0,18,'Metro Beverage Supply','Metro Beverage Supply',4,8,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(6,'SKU-BEV-006','890100000006',NULL,NULL,NULL,'Apple Juice 1L','Orchard Gold',1,2.85,1.45,211,96,12,'FreshFields Wholesale','FreshFields Wholesale',5,6,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(7,'SKU-BEV-007','890100000007',NULL,NULL,NULL,'Iced Tea Lemon 500ml','TeaTrail',1,1.75,0.82,216,74,18,'Metro Beverage Supply','Metro Beverage Supply',4,8,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(8,'SKU-BEV-008','890100000008',NULL,NULL,NULL,'Energy Drink 250ml','VoltUp',1,2.40,1.20,243,74,18,'Metro Beverage Supply','Metro Beverage Supply',4,8,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(9,'SKU-SNK-001','890100000009',NULL,NULL,NULL,'Potato Chips Sea Salt 150g','CrispHouse',2,2.25,1.05,314,108,16,'SnackSource Ltd','SnackSource Ltd',5,8,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(10,'SKU-SNK-002','890100000010',NULL,NULL,NULL,'Tortilla Chips Nacho 200g','CrispHouse',2,2.75,1.32,0,0,12,'SnackSource Ltd','SnackSource Ltd',5,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(11,'SKU-SNK-003','890100000011',NULL,NULL,NULL,'Peanuts Roasted 250g','NutHarvest',2,3.20,1.70,210,84,10,'SnackSource Ltd','SnackSource Ltd',6,5,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(12,'SKU-SNK-004','890100000012',NULL,NULL,NULL,'Trail Mix 200g','NutHarvest',2,4.50,2.45,233,95,8,'SnackSource Ltd','SnackSource Ltd',6,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(13,'SKU-SNK-005','890100000013',NULL,NULL,NULL,'Chocolate Bar Milk 45g','CocoaPeak',2,1.40,0.62,96,0,24,'SweetGoods Supply','SweetGoods Supply',5,12,24,24,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(14,'SKU-SNK-006','890100000014',NULL,NULL,NULL,'Granola Bar Oats 6 Pack','DailyGrain',2,3.90,1.95,46,0,10,'FreshFields Wholesale','FreshFields Wholesale',5,5,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(15,'SKU-SNK-007','890100000015',NULL,NULL,NULL,'Popcorn Microwave 3 Pack','KernelJoy',2,3.50,1.72,0,0,8,'SnackSource Ltd','SnackSource Ltd',6,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(16,'SKU-SNK-008','890100000016',NULL,NULL,NULL,'Crackers Original 250g','TableTime',2,2.60,1.20,5,0,10,'SnackSource Ltd','SnackSource Ltd',5,5,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(17,'SKU-DRY-001','890100000017',NULL,NULL,NULL,'Long Grain Rice 1kg','HarvestHome',3,3.25,1.68,298,147,12,'Global Grocers','Global Grocers',7,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(18,'SKU-DRY-002','890100000018',NULL,NULL,NULL,'All Purpose Flour 1kg','HarvestHome',3,2.40,1.15,46,0,10,'Global Grocers','Global Grocers',7,5,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(19,'SKU-DRY-003','890100000019',NULL,NULL,NULL,'Granulated Sugar 1kg','HarvestHome',3,2.55,1.20,82,0,10,'Global Grocers','Global Grocers',7,5,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(20,'SKU-DRY-004','890100000020',NULL,NULL,NULL,'Spaghetti 500g','PastaMio',3,1.85,0.82,233,103,14,'Global Grocers','Global Grocers',7,7,20,20,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(21,'SKU-DRY-005','890100000021',NULL,NULL,NULL,'Macaroni 500g','PastaMio',3,1.85,0.82,7,0,14,'Global Grocers','Global Grocers',7,7,20,20,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(22,'SKU-DRY-006','890100000022',NULL,NULL,NULL,'Baked Beans 400g','PantryBest',3,1.65,0.74,42,0,18,'Global Grocers','Global Grocers',7,9,24,24,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(23,'SKU-DRY-007','890100000023',NULL,NULL,NULL,'Tomato Sauce 500g','PantryBest',3,2.35,1.08,48,0,12,'Global Grocers','Global Grocers',7,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(24,'SKU-DRY-008','890100000024',NULL,NULL,NULL,'Peanut Butter 340g','NutHarvest',3,4.75,2.45,316,139,8,'Global Grocers','Global Grocers',7,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(25,'SKU-DRY-009','890100000025',NULL,NULL,NULL,'Instant Noodles Chicken 5 Pack','NoodleTime',3,3.20,1.50,0,0,16,'Global Grocers','Global Grocers',7,8,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(26,'SKU-DRY-010','890100000026',NULL,NULL,NULL,'Cooking Oil Sunflower 1L','GoldenDrop',3,4.60,2.30,4,0,8,'Global Grocers','Global Grocers',7,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(27,'SKU-DAI-001','890100000027',NULL,NULL,NULL,'Whole Milk 1L','MeadowFresh',4,2.10,1.22,24,0,12,'FreshFields Wholesale','FreshFields Wholesale',2,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(28,'SKU-DAI-002','890100000028',NULL,NULL,NULL,'Low Fat Milk 1L','MeadowFresh',4,2.10,1.22,48,0,12,'FreshFields Wholesale','FreshFields Wholesale',2,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(29,'SKU-DAI-003','890100000029',NULL,NULL,NULL,'Plain Yogurt 500g','CreamVale',4,3.25,1.72,44,0,8,'FreshFields Wholesale','FreshFields Wholesale',2,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(30,'SKU-DAI-004','890100000030',NULL,NULL,NULL,'Greek Yogurt 150g','CreamVale',4,1.45,0.72,0,0,12,'FreshFields Wholesale','FreshFields Wholesale',2,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(31,'SKU-DAI-005','890100000031',NULL,NULL,NULL,'Cheddar Cheese 200g','CheeseCraft',4,4.90,2.65,4,0,8,'FreshFields Wholesale','FreshFields Wholesale',3,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(32,'SKU-DAI-006','890100000032',NULL,NULL,NULL,'Butter Salted 250g','MeadowFresh',4,4.25,2.20,14,0,8,'FreshFields Wholesale','FreshFields Wholesale',3,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(33,'SKU-BRD-001','890100000033',NULL,NULL,NULL,'White Bread Loaf 600g','BakeHouse',5,2.80,1.42,40,0,10,'DailyBake Bakery','DailyBake Bakery',1,5,10,10,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(34,'SKU-BRD-002','890100000034',NULL,NULL,NULL,'Whole Wheat Bread Loaf 600g','BakeHouse',5,3.10,1.58,70,0,10,'DailyBake Bakery','DailyBake Bakery',1,5,10,10,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(35,'SKU-BRD-003','890100000035',NULL,NULL,NULL,'Croissants 4 Pack','BakeHouse',5,4.20,2.18,0,0,6,'DailyBake Bakery','DailyBake Bakery',1,3,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(36,'SKU-BRD-004','890100000036',NULL,NULL,NULL,'Burger Buns 6 Pack','BakeHouse',5,3.40,1.70,4,0,8,'DailyBake Bakery','DailyBake Bakery',1,4,8,8,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(37,'SKU-PRO-001','890100000037',NULL,NULL,NULL,'Red Apples 1kg','Orchard Gold',6,3.75,1.95,14,0,8,'FreshFields Wholesale','FreshFields Wholesale',2,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(38,'SKU-PRO-002','890100000038',NULL,NULL,NULL,'Bananas 1kg','GreenValley',6,2.25,1.05,28,0,10,'FreshFields Wholesale','FreshFields Wholesale',2,5,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(39,'SKU-PRO-003','890100000039',NULL,NULL,NULL,'Oranges 1kg','Orchard Gold',6,3.40,1.70,44,0,8,'FreshFields Wholesale','FreshFields Wholesale',2,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(40,'SKU-PRO-004','890100000040',NULL,NULL,NULL,'Potatoes 2kg','GreenValley',6,4.10,2.05,0,0,8,'FreshFields Wholesale','FreshFields Wholesale',2,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(41,'SKU-PRO-005','890100000041',NULL,NULL,NULL,'Onions 1kg','GreenValley',6,2.70,1.25,4,0,8,'FreshFields Wholesale','FreshFields Wholesale',2,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(42,'SKU-PRO-006','890100000042',NULL,NULL,NULL,'Tomatoes 500g','GreenValley',6,2.90,1.45,14,0,8,'FreshFields Wholesale','FreshFields Wholesale',2,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(43,'SKU-FRZ-001','890100000043',NULL,NULL,NULL,'Frozen Peas 500g','FrostGarden',7,3.60,1.85,26,0,8,'ColdChain Suppliers','ColdChain Suppliers',5,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(44,'SKU-FRZ-002','890100000044',NULL,NULL,NULL,'Frozen Mixed Vegetables 1kg','FrostGarden',7,5.25,2.72,42,0,6,'ColdChain Suppliers','ColdChain Suppliers',5,3,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(45,'SKU-FRZ-003','890100000045',NULL,NULL,NULL,'Frozen French Fries 1kg','FrostGarden',7,4.80,2.40,0,0,8,'ColdChain Suppliers','ColdChain Suppliers',5,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(46,'SKU-FRZ-004','890100000046',NULL,NULL,NULL,'Vanilla Ice Cream 1L','IceCrown',7,6.50,3.35,3,0,6,'ColdChain Suppliers','ColdChain Suppliers',5,3,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(47,'SKU-HOU-001','890100000047',NULL,NULL,NULL,'Dishwashing Liquid 750ml','CleanNest',8,3.85,1.90,14,0,8,'HomeCare Distributors','HomeCare Distributors',6,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(48,'SKU-HOU-002','890100000048',NULL,NULL,NULL,'Laundry Detergent 2L','CleanNest',8,8.90,4.55,18,0,6,'HomeCare Distributors','HomeCare Distributors',6,3,4,4,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(49,'SKU-HOU-003','890100000049',NULL,NULL,NULL,'Paper Towels 2 Roll','SoftNest',8,3.75,1.82,82,0,10,'HomeCare Distributors','HomeCare Distributors',6,5,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(50,'SKU-HOU-004','890100000050',NULL,NULL,NULL,'Toilet Tissue 4 Roll','SoftNest',8,5.90,2.95,0,0,10,'HomeCare Distributors','HomeCare Distributors',6,5,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(51,'SKU-HOU-005','890100000051',NULL,NULL,NULL,'Garbage Bags Medium 20 Pack','CleanNest',8,4.40,2.10,4,0,8,'HomeCare Distributors','HomeCare Distributors',6,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(52,'SKU-PER-001','890100000052',NULL,NULL,NULL,'Bar Soap Aloe 100g','FreshTouch',9,1.60,0.68,36,0,12,'PersonalCare Wholesale','PersonalCare Wholesale',7,6,24,24,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(53,'SKU-PER-002','890100000053',NULL,NULL,NULL,'Shampoo Daily Care 400ml','FreshTouch',9,5.75,2.80,26,0,8,'PersonalCare Wholesale','PersonalCare Wholesale',7,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(54,'SKU-PER-003','890100000054',NULL,NULL,NULL,'Toothpaste Mint 100ml','SmileBright',9,3.20,1.42,84,0,12,'PersonalCare Wholesale','PersonalCare Wholesale',7,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(55,'SKU-PER-004','890100000055',NULL,NULL,NULL,'Toothbrush Medium','SmileBright',9,2.50,1.05,0,0,12,'PersonalCare Wholesale','PersonalCare Wholesale',7,6,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(56,'SKU-PER-005','890100000056',NULL,NULL,NULL,'Deodorant Roll On 50ml','FreshTouch',9,4.10,1.95,4,0,8,'PersonalCare Wholesale','PersonalCare Wholesale',7,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(57,'SKU-BAB-001','890100000057',NULL,NULL,NULL,'Baby Wipes 64 Count','LittleSteps',10,4.90,2.45,14,0,8,'FamilyGoods Supply','FamilyGoods Supply',7,4,6,6,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(58,'SKU-BAB-002','890100000058',NULL,NULL,NULL,'Baby Diapers Size 4 20 Pack','LittleSteps',10,11.50,6.10,18,0,6,'FamilyGoods Supply','FamilyGoods Supply',7,3,4,4,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(59,'SKU-PET-001','890100000059',NULL,NULL,NULL,'Dog Food Chicken 2kg','PawPantry',11,9.75,5.20,30,0,6,'PetSupply Wholesale','PetSupply Wholesale',7,3,4,4,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52'),(60,'SKU-PET-002','890100000060',NULL,NULL,NULL,'Cat Food Tuna 400g','PawPantry',11,2.90,1.42,0,0,10,'PetSupply Wholesale','PetSupply Wholesale',7,5,12,12,'piece','package',NULL,NULL,'active',NULL,1,'2026-09-16 23:18:52');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `inventory`
--

LOCK TABLES `inventory` WRITE;
/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES (1,1,112,'2026-09-18 01:53:02'),(2,2,133,'2026-09-18 01:53:02'),(3,3,90,'2026-09-16 23:38:58'),(4,4,274,'2026-09-18 01:53:02'),(5,5,0,'2026-09-16 23:18:52'),(6,6,115,'2026-09-18 01:53:02'),(7,7,142,'2026-09-18 01:53:02'),(8,8,169,'2026-09-18 01:53:02'),(9,9,206,'2026-09-18 01:53:02'),(10,10,0,'2026-09-16 23:18:52'),(11,11,126,'2026-09-18 01:53:02'),(12,12,138,'2026-09-18 01:53:02'),(13,13,96,'2026-09-16 23:38:58'),(14,14,46,'2026-09-16 23:38:58'),(15,15,0,'2026-09-16 23:18:52'),(16,16,5,'2026-09-16 23:38:58'),(17,17,151,'2026-09-18 01:53:02'),(18,18,46,'2026-09-16 23:38:58'),(19,19,82,'2026-09-16 23:38:58'),(20,20,130,'2026-09-18 01:53:02'),(21,21,7,'2026-09-16 23:38:58'),(22,22,42,'2026-09-16 23:38:58'),(23,23,48,'2026-09-16 23:38:58'),(24,24,177,'2026-09-18 01:53:02'),(25,25,0,'2026-09-16 23:18:52'),(26,26,4,'2026-09-16 23:38:58'),(27,27,24,'2026-09-16 23:38:58'),(28,28,48,'2026-09-16 23:38:58'),(29,29,44,'2026-09-16 23:38:58'),(30,30,0,'2026-09-16 23:18:52'),(31,31,4,'2026-09-16 23:38:58'),(32,32,14,'2026-09-16 23:38:58'),(33,33,40,'2026-09-16 23:38:58'),(34,34,70,'2026-09-16 23:38:58'),(35,35,0,'2026-09-16 23:18:52'),(36,36,4,'2026-09-16 23:38:58'),(37,37,14,'2026-09-16 23:38:58'),(38,38,28,'2026-09-16 23:38:58'),(39,39,44,'2026-09-16 23:38:58'),(40,40,0,'2026-09-16 23:18:52'),(41,41,4,'2026-09-16 23:38:58'),(42,42,14,'2026-09-16 23:38:58'),(43,43,26,'2026-09-16 23:38:58'),(44,44,42,'2026-09-16 23:38:58'),(45,45,0,'2026-09-16 23:18:52'),(46,46,3,'2026-09-16 23:38:58'),(47,47,14,'2026-09-16 23:38:58'),(48,48,18,'2026-09-16 23:38:58'),(49,49,82,'2026-09-16 23:38:58'),(50,50,0,'2026-09-16 23:18:52'),(51,51,4,'2026-09-16 23:38:58'),(52,52,36,'2026-09-16 23:38:58'),(53,53,26,'2026-09-16 23:38:58'),(54,54,84,'2026-09-16 23:38:58'),(55,55,0,'2026-09-16 23:18:52'),(56,56,4,'2026-09-16 23:38:58'),(57,57,14,'2026-09-16 23:38:58'),(58,58,18,'2026-09-16 23:38:58'),(59,59,30,'2026-09-16 23:38:58'),(60,60,0,'2026-09-16 23:18:52');
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `stock_receiving`
--

LOCK TABLES `stock_receiving` WRITE;
/*!40000 ALTER TABLE `stock_receiving` DISABLE KEYS */;
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES (457,NULL,NULL,NULL,1,194,0,1,194,0,0.32,62.08,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-1','RM_SEED_SALES_TREND_V1-20260918-1',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(458,NULL,NULL,NULL,2,214,0,1,214,0,0.62,132.68,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-2','RM_SEED_SALES_TREND_V1-20260918-2',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(459,NULL,NULL,NULL,4,172,0,1,172,0,0.58,99.76,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-4','RM_SEED_SALES_TREND_V1-20260918-4',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(460,NULL,NULL,NULL,6,205,0,1,205,0,1.45,297.25,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-6','RM_SEED_SALES_TREND_V1-20260918-6',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(461,NULL,NULL,NULL,7,186,0,1,186,0,0.82,152.52,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-7','RM_SEED_SALES_TREND_V1-20260918-7',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(462,NULL,NULL,NULL,8,189,0,1,189,0,1.20,226.80,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-8','RM_SEED_SALES_TREND_V1-20260918-8',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(463,NULL,NULL,NULL,9,226,0,1,226,0,1.05,237.30,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-9','RM_SEED_SALES_TREND_V1-20260918-9',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(464,NULL,NULL,NULL,11,205,0,1,205,0,1.70,348.50,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-11','RM_SEED_SALES_TREND_V1-20260918-11',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(465,NULL,NULL,NULL,12,219,0,1,219,0,2.45,536.55,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-12','RM_SEED_SALES_TREND_V1-20260918-12',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(466,NULL,NULL,NULL,17,274,0,1,274,0,1.68,460.32,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-17','RM_SEED_SALES_TREND_V1-20260918-17',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(467,NULL,NULL,NULL,20,233,0,1,233,0,0.82,191.06,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-20','RM_SEED_SALES_TREND_V1-20260918-20',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock'),(468,NULL,NULL,NULL,24,272,0,1,272,0,2.45,666.40,11,'2026-06-18 23:00:00','RetailMind Seed Supplier','RM_SEED_SALES_TREND_V1','RM_SEED_SALES_TREND_V1-20260918-24','RM_SEED_SALES_TREND_V1-20260918-24',NULL,'none',0,NULL,'Deterministic dashboard sales-trend simulation stock');
/*!40000 ALTER TABLE `stock_receiving` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `product_batches`
--

LOCK TABLES `product_batches` WRITE;
/*!40000 ALTER TABLE `product_batches` DISABLE KEYS */;
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES (1,1,NULL,'RM-SEED-OPENING-V1-SKU-BEV-001',12,12,NULL,'2026-09-16 23:38:57','RetailMind development seed','2026-09-16 23:38:57'),(2,2,NULL,'RM-SEED-OPENING-V1-SKU-BEV-002',30,30,NULL,'2026-09-16 23:38:57','RetailMind development seed','2026-09-16 23:38:57'),(3,3,NULL,'RM-SEED-OPENING-V1-SKU-BEV-003',90,90,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(4,4,NULL,'RM-SEED-OPENING-V1-SKU-BEV-004',168,168,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(5,6,NULL,'RM-SEED-OPENING-V1-SKU-BEV-006',6,6,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(6,7,NULL,'RM-SEED-OPENING-V1-SKU-BEV-007',30,30,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(7,8,NULL,'RM-SEED-OPENING-V1-SKU-BEV-008',54,54,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(8,9,NULL,'RM-SEED-OPENING-V1-SKU-SNK-001',88,88,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(9,11,NULL,'RM-SEED-OPENING-V1-SKU-SNK-003',5,5,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(10,12,NULL,'RM-SEED-OPENING-V1-SKU-SNK-004',14,14,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(11,13,NULL,'RM-SEED-OPENING-V1-SKU-SNK-005',96,96,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(12,14,NULL,'RM-SEED-OPENING-V1-SKU-SNK-006',46,46,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(13,16,NULL,'RM-SEED-OPENING-V1-SKU-SNK-008',5,5,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(14,17,NULL,'RM-SEED-OPENING-V1-SKU-DRY-001',24,24,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(15,18,NULL,'RM-SEED-OPENING-V1-SKU-DRY-002',46,46,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(16,19,NULL,'RM-SEED-OPENING-V1-SKU-DRY-003',82,82,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(17,21,NULL,'RM-SEED-OPENING-V1-SKU-DRY-005',7,7,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(18,22,NULL,'RM-SEED-OPENING-V1-SKU-DRY-006',42,42,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(19,23,NULL,'RM-SEED-OPENING-V1-SKU-DRY-007',48,48,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(20,24,NULL,'RM-SEED-OPENING-V1-SKU-DRY-008',44,44,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(21,26,NULL,'RM-SEED-OPENING-V1-SKU-DRY-010',4,4,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(22,27,NULL,'RM-SEED-OPENING-V1-SKU-DAI-001',24,24,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(23,28,NULL,'RM-SEED-OPENING-V1-SKU-DAI-002',48,48,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(24,29,NULL,'RM-SEED-OPENING-V1-SKU-DAI-003',44,44,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(25,31,NULL,'RM-SEED-OPENING-V1-SKU-DAI-005',4,4,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(26,32,NULL,'RM-SEED-OPENING-V1-SKU-DAI-006',14,14,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(27,33,NULL,'RM-SEED-OPENING-V1-SKU-BRD-001',40,40,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(28,34,NULL,'RM-SEED-OPENING-V1-SKU-BRD-002',70,70,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(29,36,NULL,'RM-SEED-OPENING-V1-SKU-BRD-004',4,4,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(30,37,NULL,'RM-SEED-OPENING-V1-SKU-PRO-001',14,14,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(31,38,NULL,'RM-SEED-OPENING-V1-SKU-PRO-002',28,28,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(32,39,NULL,'RM-SEED-OPENING-V1-SKU-PRO-003',44,44,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(33,41,NULL,'RM-SEED-OPENING-V1-SKU-PRO-005',4,4,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(34,42,NULL,'RM-SEED-OPENING-V1-SKU-PRO-006',14,14,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(35,43,NULL,'RM-SEED-OPENING-V1-SKU-FRZ-001',26,26,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(36,44,NULL,'RM-SEED-OPENING-V1-SKU-FRZ-002',42,42,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(37,46,NULL,'RM-SEED-OPENING-V1-SKU-FRZ-004',3,3,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(38,47,NULL,'RM-SEED-OPENING-V1-SKU-HOU-001',14,14,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(39,48,NULL,'RM-SEED-OPENING-V1-SKU-HOU-002',18,18,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(40,49,NULL,'RM-SEED-OPENING-V1-SKU-HOU-003',82,82,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(41,51,NULL,'RM-SEED-OPENING-V1-SKU-HOU-005',4,4,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(42,52,NULL,'RM-SEED-OPENING-V1-SKU-PER-001',36,36,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(43,53,NULL,'RM-SEED-OPENING-V1-SKU-PER-002',26,26,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(44,54,NULL,'RM-SEED-OPENING-V1-SKU-PER-003',84,84,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(45,56,NULL,'RM-SEED-OPENING-V1-SKU-PER-005',4,4,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(46,57,NULL,'RM-SEED-OPENING-V1-SKU-BAB-001',14,14,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(47,58,NULL,'RM-SEED-OPENING-V1-SKU-BAB-002',18,18,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(48,59,NULL,'RM-SEED-OPENING-V1-SKU-PET-001',30,30,NULL,'2026-09-16 23:38:58','RetailMind development seed','2026-09-16 23:38:58'),(505,1,457,'RM_SEED_SALES_TREND_V1-20260918-1',194,100,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(506,2,458,'RM_SEED_SALES_TREND_V1-20260918-2',214,103,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(507,4,459,'RM_SEED_SALES_TREND_V1-20260918-4',172,106,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(508,6,460,'RM_SEED_SALES_TREND_V1-20260918-6',205,109,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(509,7,461,'RM_SEED_SALES_TREND_V1-20260918-7',186,112,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(510,8,462,'RM_SEED_SALES_TREND_V1-20260918-8',189,115,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(511,9,463,'RM_SEED_SALES_TREND_V1-20260918-9',226,118,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(512,11,464,'RM_SEED_SALES_TREND_V1-20260918-11',205,121,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(513,12,465,'RM_SEED_SALES_TREND_V1-20260918-12',219,124,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(514,17,466,'RM_SEED_SALES_TREND_V1-20260918-17',274,127,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(515,20,467,'RM_SEED_SALES_TREND_V1-20260918-20',233,130,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01'),(516,24,468,'RM_SEED_SALES_TREND_V1-20260918-24',272,133,NULL,'2026-06-18 23:00:00','RetailMind Seed Supplier','2026-09-18 01:53:01');
/*!40000 ALTER TABLE `product_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `purchase_history`
--

LOCK TABLES `purchase_history` WRITE;
/*!40000 ALTER TABLE `purchase_history` DISABLE KEYS */;
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES (1,1,'RetailMind development seed',12,0.32,'2026-09-16 23:38:57',3.84,NULL),(2,2,'RetailMind development seed',30,0.62,'2026-09-16 23:38:57',18.60,NULL),(3,3,'RetailMind development seed',90,0.48,'2026-09-16 23:38:58',43.20,NULL),(4,4,'RetailMind development seed',168,0.58,'2026-09-16 23:38:58',97.44,NULL),(5,6,'RetailMind development seed',6,1.45,'2026-09-16 23:38:58',8.70,NULL),(6,7,'RetailMind development seed',30,0.82,'2026-09-16 23:38:58',24.60,NULL),(7,8,'RetailMind development seed',54,1.20,'2026-09-16 23:38:58',64.80,NULL),(8,9,'RetailMind development seed',88,1.05,'2026-09-16 23:38:58',92.40,NULL),(9,11,'RetailMind development seed',5,1.70,'2026-09-16 23:38:58',8.50,NULL),(10,12,'RetailMind development seed',14,2.45,'2026-09-16 23:38:58',34.30,NULL),(11,13,'RetailMind development seed',96,0.62,'2026-09-16 23:38:58',59.52,NULL),(12,14,'RetailMind development seed',46,1.95,'2026-09-16 23:38:58',89.70,NULL),(13,16,'RetailMind development seed',5,1.20,'2026-09-16 23:38:58',6.00,NULL),(14,17,'RetailMind development seed',24,1.68,'2026-09-16 23:38:58',40.32,NULL),(15,18,'RetailMind development seed',46,1.15,'2026-09-16 23:38:58',52.90,NULL),(16,19,'RetailMind development seed',82,1.20,'2026-09-16 23:38:58',98.40,NULL),(17,21,'RetailMind development seed',7,0.82,'2026-09-16 23:38:58',5.74,NULL),(18,22,'RetailMind development seed',42,0.74,'2026-09-16 23:38:58',31.08,NULL),(19,23,'RetailMind development seed',48,1.08,'2026-09-16 23:38:58',51.84,NULL),(20,24,'RetailMind development seed',44,2.45,'2026-09-16 23:38:58',107.80,NULL),(21,26,'RetailMind development seed',4,2.30,'2026-09-16 23:38:58',9.20,NULL),(22,27,'RetailMind development seed',24,1.22,'2026-09-16 23:38:58',29.28,NULL),(23,28,'RetailMind development seed',48,1.22,'2026-09-16 23:38:58',58.56,NULL),(24,29,'RetailMind development seed',44,1.72,'2026-09-16 23:38:58',75.68,NULL),(25,31,'RetailMind development seed',4,2.65,'2026-09-16 23:38:58',10.60,NULL),(26,32,'RetailMind development seed',14,2.20,'2026-09-16 23:38:58',30.80,NULL),(27,33,'RetailMind development seed',40,1.42,'2026-09-16 23:38:58',56.80,NULL),(28,34,'RetailMind development seed',70,1.58,'2026-09-16 23:38:58',110.60,NULL),(29,36,'RetailMind development seed',4,1.70,'2026-09-16 23:38:58',6.80,NULL),(30,37,'RetailMind development seed',14,1.95,'2026-09-16 23:38:58',27.30,NULL),(31,38,'RetailMind development seed',28,1.05,'2026-09-16 23:38:58',29.40,NULL),(32,39,'RetailMind development seed',44,1.70,'2026-09-16 23:38:58',74.80,NULL),(33,41,'RetailMind development seed',4,1.25,'2026-09-16 23:38:58',5.00,NULL),(34,42,'RetailMind development seed',14,1.45,'2026-09-16 23:38:58',20.30,NULL),(35,43,'RetailMind development seed',26,1.85,'2026-09-16 23:38:58',48.10,NULL),(36,44,'RetailMind development seed',42,2.72,'2026-09-16 23:38:58',114.24,NULL),(37,46,'RetailMind development seed',3,3.35,'2026-09-16 23:38:58',10.05,NULL),(38,47,'RetailMind development seed',14,1.90,'2026-09-16 23:38:58',26.60,NULL),(39,48,'RetailMind development seed',18,4.55,'2026-09-16 23:38:58',81.90,NULL),(40,49,'RetailMind development seed',82,1.82,'2026-09-16 23:38:58',149.24,NULL),(41,51,'RetailMind development seed',4,2.10,'2026-09-16 23:38:58',8.40,NULL),(42,52,'RetailMind development seed',36,0.68,'2026-09-16 23:38:58',24.48,NULL),(43,53,'RetailMind development seed',26,2.80,'2026-09-16 23:38:58',72.80,NULL),(44,54,'RetailMind development seed',84,1.42,'2026-09-16 23:38:58',119.28,NULL),(45,56,'RetailMind development seed',4,1.95,'2026-09-16 23:38:58',7.80,NULL),(46,57,'RetailMind development seed',14,2.45,'2026-09-16 23:38:58',34.30,NULL),(47,58,'RetailMind development seed',18,6.10,'2026-09-16 23:38:58',109.80,NULL),(48,59,'RetailMind development seed',30,5.20,'2026-09-16 23:38:58',156.00,NULL),(505,1,'RetailMind Seed Supplier',194,0.32,'2026-06-18 23:00:00',62.08,11),(506,2,'RetailMind Seed Supplier',214,0.62,'2026-06-18 23:00:00',132.68,11),(507,4,'RetailMind Seed Supplier',172,0.58,'2026-06-18 23:00:00',99.76,11),(508,6,'RetailMind Seed Supplier',205,1.45,'2026-06-18 23:00:00',297.25,11),(509,7,'RetailMind Seed Supplier',186,0.82,'2026-06-18 23:00:00',152.52,11),(510,8,'RetailMind Seed Supplier',189,1.20,'2026-06-18 23:00:00',226.80,11),(511,9,'RetailMind Seed Supplier',226,1.05,'2026-06-18 23:00:00',237.30,11),(512,11,'RetailMind Seed Supplier',205,1.70,'2026-06-18 23:00:00',348.50,11),(513,12,'RetailMind Seed Supplier',219,2.45,'2026-06-18 23:00:00',536.55,11),(514,17,'RetailMind Seed Supplier',274,1.68,'2026-06-18 23:00:00',460.32,11),(515,20,'RetailMind Seed Supplier',233,0.82,'2026-06-18 23:00:00',191.06,11),(516,24,'RetailMind Seed Supplier',272,2.45,'2026-06-18 23:00:00',666.40,11);
/*!40000 ALTER TABLE `purchase_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `platform_settings`
--

LOCK TABLES `platform_settings` WRITE;
/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('emergency_access_duration_minutes','15',NULL,'2026-09-18 06:13:38'),('dormancy_disable_days','45',NULL,'2026-09-24 00:00:00'),('dormancy_warn_days','30',NULL,'2026-09-24 00:00:00');
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `store_settings`
--

LOCK TABLES `store_settings` WRITE;
/*!40000 ALTER TABLE `store_settings` DISABLE KEYS */;
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('business_identifier','',NULL,'2026-09-14 07:29:33'),('currency_symbol','₱',NULL,'2026-09-14 07:29:33'),('receipt_footer','Thank you for shopping with us.',NULL,'2026-09-14 07:29:33'),('store_address','Tangub City',NULL,'2026-09-14 07:29:33'),('store_email','',NULL,'2026-09-14 07:29:33'),('store_name','Shalom Store',NULL,'2026-09-14 07:29:33'),('store_phone','',NULL,'2026-09-14 07:29:33'),('timezone','Asia/Manila',NULL,'2026-09-14 07:29:33');
/*!40000 ALTER TABLE `store_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `ml_settings`
--

LOCK TABLES `ml_settings` WRITE;
/*!40000 ALTER TABLE `ml_settings` DISABLE KEYS */;
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('accuracy_threshold_wape','35',NULL,'2026-09-14 07:29:33'),('forecast_period_days','30',NULL,'2026-09-14 07:29:33'),('history_window_days','365',NULL,'2026-09-14 07:29:33'),('holiday_dates','',NULL,'2026-09-14 07:29:33'),('max_depth','18',NULL,'2026-09-14 07:29:33'),('min_samples_leaf','2',NULL,'2026-09-14 07:29:33'),('min_samples_split','4',NULL,'2026-09-14 07:29:33'),('minimum_history_days','30',NULL,'2026-09-14 07:29:33'),('minimum_nonzero_sales_days','5',NULL,'2026-09-14 07:29:33'),('n_estimators','300',NULL,'2026-09-14 07:29:33'),('prediction_interval_lower','10',NULL,'2026-09-14 07:29:33'),('prediction_interval_upper','90',NULL,'2026-09-14 07:29:33'),('preferred_history_days','90',NULL,'2026-09-14 07:29:33'),('retrain_frequency_days','7',NULL,'2026-09-14 07:29:33'),('retrain_new_sales_records','100',NULL,'2026-09-14 07:29:33');
/*!40000 ALTER TABLE `ml_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('2026_07_operational_updates','Suppliers, purchase orders, shifts, held sales, forecast decisions, units, and inventory insights','2026-09-14 07:29:34'),('202608040001_auth_security','Authentication security tables and mandatory password-change support','2026-09-16 23:47:10'),('202608040002_operational_updates','Operational workflow tables and columns','2026-09-16 23:47:10'),('202608040003_integrity_constraints','Foreign-key protections for upgraded operational databases','2026-09-16 23:47:11'),('202609130001_branch_management','Branches, branch-scoped users and privilege assignments','2026-09-16 23:47:11'),('202609130002_role_access_update','Make administrator inventory access read-only','2026-09-16 23:47:11'),('202609130003_superadmin_inventory_read_only','Make super administrator inventory access read-only','2026-09-16 23:47:11'),('202609130004_remove_seller_role','Replace the duplicate seller role with cashier','2026-09-16 23:47:11'),('202609140001_branch_scoped_product_identifiers','Allow product identifiers to be reused across branches','2026-09-16 23:47:11'),('202609170001_admin_profile_images','Nullable generated profile image filename for administrator accounts','2026-09-16 23:47:11'),('202609170001_profile_images','Optional generated profile image filename for user accounts','2026-09-17 01:06:29'),('202609170001_staff_profile_images','Optional profile image filename for staff accounts','2026-09-16 23:59:31'),('202609180002_audit_record_categories','Categorize Protected Audit Records for authority-scoped visibility','2026-09-18 06:13:38'),('202609180003_emergency_access','Durable reason-bound Emergency Access sessions and audit correlation','2026-09-18 06:13:38'),('202609180004_recovery_account','Sealed Recovery Account identity and offline lifecycle state','2026-09-18 06:13:38'),('202609180005_attention_foundation','Shared live attention settings, active state, and notification keys','2026-09-18 06:13:38');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

-- The canonical dump excludes transactional sales. Remove the matching generated
-- sales-trend stock snapshot and restore opening-stock counters so the fresh seed
-- remains internally consistent and the deterministic trend seeder can run.
UPDATE `inventory` i
JOIN (
  SELECT `product_id`, SUM(`remaining_quantity`) AS `remaining_quantity`
  FROM `product_batches`
  WHERE `batch_number` LIKE 'RM\_SEED\_SALES\_TREND\_V1-%'
  GROUP BY `product_id`
) seed_stock ON seed_stock.`product_id` = i.`product_id`
SET i.`quantity_on_hand` = GREATEST(0, i.`quantity_on_hand` - seed_stock.`remaining_quantity`);

UPDATE `products` p
JOIN (
  SELECT `product_id`, SUM(`quantity`) AS `received_quantity`,
         SUM(`quantity` - `remaining_quantity`) AS `sold_quantity`
  FROM `product_batches`
  WHERE `batch_number` LIKE 'RM\_SEED\_SALES\_TREND\_V1-%'
  GROUP BY `product_id`
) seed_stock ON seed_stock.`product_id` = p.`product_id`
SET p.`quantity_purchased` = GREATEST(0, p.`quantity_purchased` - seed_stock.`received_quantity`),
    p.`quantity_sold` = GREATEST(0, p.`quantity_sold` - seed_stock.`sold_quantity`);

DELETE FROM `product_batches` WHERE `batch_number` LIKE 'RM\_SEED\_SALES\_TREND\_V1-%';
DELETE FROM `purchase_history` WHERE `supplier` = 'RetailMind Seed Supplier';
DELETE FROM `stock_receiving` WHERE `batch_number` LIKE 'RM\_SEED\_SALES\_TREND\_V1-%';

-- Canonical fresh-install Super Administrator credentials (change after first login).
UPDATE `users` SET `password_hash` = '$2y$12$pmB8fB3r3Br3bBIzEE2v9.RgnbJxZCjCCMqefPpwmP26V8veMKj7q', `must_change_password` = 1 WHERE `username` = 'superadmin';

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
