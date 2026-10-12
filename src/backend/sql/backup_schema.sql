-- RetailMind database backup
-- Generated: 2026-09-18T14:06:13+08:00
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';


-- Table activity_log
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `category` enum('store_operation','security','recovery','platform_setting','recovery_account') NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `previous_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_activity_log_category_created` (`category`,`created_at`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('1', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"status\":\"success\"}', '::1', '2026-09-14 15:29:41');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('2', '1', 'Mandatory password change completed', 'security', 'Authentication', '1', NULL, '{\"status\":\"completed\"}', '::1', '2026-09-14 15:30:05');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('3', '1', 'Login failure', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:09:59');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('4', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"status\":\"success\"}', '::1', '2026-09-15 06:10:06');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('5', '1', 'User creation', 'store_operation', 'Users', '2', NULL, '{\"user_id\":2,\"full_name\":\"MIKE SORDILLA\",\"username\":\"Mike\",\"email\":\"sordillamike1@gmail.com\",\"role_id\":3,\"status\":\"active\"}', '::1', '2026-09-15 06:27:46');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('6', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 06:35:03');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('7', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 06:35:19');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('8', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sd\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:35:48');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('9', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"not-a-real-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:36:31');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('10', '1', 'Login failure', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:36:40');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('11', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sdw\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:37:59');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('12', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 06:38:26');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('13', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sds\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:39:11');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('14', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"wrong-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:39:53');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('15', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"wrong-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:40:09');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('16', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"wrong-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:40:30');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('17', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sds\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:42:08');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('18', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"wrong-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:43:00');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('19', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"wrong-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:43:18');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('20', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"wrong-user\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 06:43:35');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('21', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 06:43:46');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('22', '2', 'Login success', 'security', 'Authentication', '2', NULL, '{\"username\":\"Mike\",\"role\":\"inventory_manager\",\"action\":\"success\"}', '::1', '2026-09-15 06:44:30');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('23', '2', 'Mandatory password change completed', 'security', 'Authentication', '2', NULL, '{\"status\":\"completed\"}', '::1', '2026-09-15 06:45:44');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('24', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 06:48:00');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('25', '1', 'Login failure', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 07:04:00');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('26', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 07:04:07');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('27', '1', 'Updated notification preferences', 'store_operation', NULL, NULL, NULL, NULL, '::1', '2026-09-15 07:15:22');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('28', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 14:28:40');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('29', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 14:28:58');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('30', '2', 'Login failure', 'security', 'Authentication', '2', NULL, '{\"username\":\"Mike\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-15 14:36:14');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('31', '2', 'Login success', 'security', 'Authentication', '2', NULL, '{\"username\":\"Mike\",\"role\":\"inventory_manager\",\"action\":\"success\"}', '::1', '2026-09-15 14:36:19');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('32', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 14:37:00');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('33', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 14:37:23');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('34', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 23:36:55');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('35', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-15 23:41:32');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('36', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-16 07:24:19');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('37', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-16 11:41:19');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('38', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-16 12:11:53');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('39', '1', 'Database backup', 'recovery', 'Backup & Restore', NULL, NULL, '{\"filename\":\"retailmind-backup-20260916-122048.sql\",\"size\":67374}', '::1', '2026-09-16 12:20:48');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('40', '1', 'Fiscal period creation', 'store_operation', 'Fiscal Periods', '1', NULL, '{\"period_id\":1,\"period_name\":\"Q1\",\"start_date\":\"2026-09-16\",\"end_date\":\"2026-10-28\",\"status\":\"open\"}', '::1', '2026-09-16 12:46:24');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('41', '1', 'Fiscal-period closing', 'store_operation', 'Fiscal Periods', '1', '{\"period_id\":1,\"period_name\":\"Q1\",\"start_date\":\"2026-09-16\",\"end_date\":\"2026-10-28\",\"status\":\"open\",\"created_by\":1,\"created_at\":\"2026-09-16 12:46:24\",\"closed_at\":null,\"closed_by\":null}', '{\"period_id\":1,\"period_name\":\"Q1\",\"start_date\":\"2026-09-16\",\"end_date\":\"2026-10-28\",\"status\":\"closed\",\"created_by\":1,\"created_at\":\"2026-09-16 12:46:24\",\"closed_at\":\"2026-09-16 12:46:31\",\"closed_by\":1}', '::1', '2026-09-16 12:46:31');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('42', '1', 'Fiscal period locked', 'store_operation', 'Fiscal Periods', '1', '{\"period_id\":1,\"period_name\":\"Q1\",\"start_date\":\"2026-09-16\",\"end_date\":\"2026-10-28\",\"status\":\"closed\",\"created_by\":1,\"created_at\":\"2026-09-16 12:46:24\",\"closed_at\":\"2026-09-16 12:46:31\",\"closed_by\":1}', '{\"period_id\":1,\"period_name\":\"Q1\",\"start_date\":\"2026-09-16\",\"end_date\":\"2026-10-28\",\"status\":\"locked\",\"created_by\":1,\"created_at\":\"2026-09-16 12:46:24\",\"closed_at\":\"2026-09-16 12:46:31\",\"closed_by\":1}', '::1', '2026-09-16 12:46:36');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('43', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-16 22:17:47');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('44', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-17 07:14:48');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('45', '1', 'Fiscal period creation', 'store_operation', 'Fiscal Periods', '2', NULL, '{\"period_id\":2,\"period_name\":\"Testing\",\"start_date\":\"2026-09-17\",\"end_date\":\"2026-10-17\",\"status\":\"open\"}', '::1', '2026-09-17 07:38:01');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('46', '1', 'User profile update', 'store_operation', 'Users', '1', NULL, '{\"full_name\":\"Super Admin\",\"email\":\"superadmin@inventory.local\"}', '::1', '2026-09-17 11:36:18');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('47', '1', 'User profile picture update', 'store_operation', 'Users', '1', NULL, '{\"has_custom_profile_image\":true}', '::1', '2026-09-17 11:36:27');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('48', '1', 'User account update', 'store_operation', 'Users', '11', '{\"user_id\":11,\"username\":\"rm_seed_sales_receiver\",\"full_name\":\"RetailMind Sales Seed Receiver\",\"email\":\"rm-seed-sales-receiver@example.test\",\"profile_image\":null,\"status\":\"disabled\",\"role_id\":2,\"branch_id\":2}', '{\"user_id\":11,\"username\":\"rm_seed_sales_receiver\",\"full_name\":\"RetailMind Sales Seed Receiver\",\"email\":\"rm-seed-sales-receiver@example.test\",\"profile_image\":\"b1e1ee08e88abf5c8f0eda5f2e4352ac6f64c3db035d22e3ac8b6751dfa882f7.png\",\"status\":\"disabled\",\"role_id\":2,\"branch_id\":2}', '::1', '2026-09-17 11:42:38');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('49', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-17 11:58:24');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('50', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-17 20:31:57');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('51', '1', 'Login failure', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-17 21:04:46');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('52', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-17 21:04:53');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('53', '1', 'User account update', 'store_operation', 'Users', '2', '{\"user_id\":2,\"username\":\"Mike\",\"full_name\":\"MIKE SORDILLA\",\"email\":\"sordillamike1@gmail.com\",\"profile_image\":null,\"status\":\"active\",\"role_id\":3,\"branch_id\":1}', '{\"user_id\":2,\"username\":\"Mike\",\"full_name\":\"MIKE SORDILLA\",\"email\":\"sordillamike1@gmail.com\",\"profile_image\":null,\"status\":\"active\",\"role_id\":2,\"branch_id\":1}', '::1', '2026-09-17 21:32:58');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('54', '1', 'User account update with password change', 'security', 'Users', '2', '{\"user_id\":2,\"username\":\"Mike\",\"full_name\":\"MIKE SORDILLA\",\"email\":\"sordillamike1@gmail.com\",\"profile_image\":null,\"status\":\"active\",\"role_id\":2,\"branch_id\":1}', '{\"user_id\":2,\"username\":\"Mike\",\"full_name\":\"MIKE SORDILLA\",\"email\":\"sordillamike1@gmail.com\",\"profile_image\":null,\"status\":\"active\",\"role_id\":2,\"branch_id\":1}', '::1', '2026-09-17 21:33:07');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('55', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sordillamike1@gmail.com\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-17 21:33:24');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('56', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sordillamike1@gmail.com\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-17 21:33:30');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('57', NULL, 'Login failure', 'security', 'Authentication', NULL, NULL, '{\"username\":\"sordillamike1@gmail.com\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-17 21:33:32');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('58', '2', 'Login failure', 'security', 'Authentication', '2', NULL, '{\"username\":\"Mike\",\"reason\":\"invalid_credentials\"}', '::1', '2026-09-17 21:33:37');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('59', '2', 'Login success', 'security', 'Authentication', '2', NULL, '{\"username\":\"Mike\",\"role\":\"admin\",\"action\":\"success\"}', '::1', '2026-09-17 21:33:41');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('60', '2', 'Mandatory password change completed', 'security', 'Authentication', '2', NULL, '{\"status\":\"completed\"}', '::1', '2026-09-17 21:37:42');
INSERT INTO `activity_log` (`log_id`, `user_id`, `action`, `category`, `module`, `record_id`, `previous_value`, `new_value`, `ip_address`, `created_at`) VALUES ('61', '1', 'Login success', 'security', 'Authentication', '1', NULL, '{\"username\":\"superadmin\",\"role\":\"super_admin\",\"action\":\"success\"}', '::1', '2026-09-17 21:41:27');

-- Table backup_history
DROP TABLE IF EXISTS `backup_history`;
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
INSERT INTO `backup_history` (`backup_id`, `filename`, `backup_type`, `file_size`, `status`, `performed_by`, `notes`, `created_at`) VALUES ('1', 'retailmind-backup-20260916-122048.sql', 'manual', '67374', 'completed', '1', NULL, '2026-09-16 12:20:48');

-- Table barcode_pairings
DROP TABLE IF EXISTS `barcode_pairings`;
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

-- Table barcode_scans
DROP TABLE IF EXISTS `barcode_scans`;
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

-- Table branches
DROP TABLE IF EXISTS `branches`;
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
INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_code`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'Shalom Main', '0001', 'active', '1', '2026-09-15 06:27:15', '2026-09-15 06:27:15');
INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_code`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'RetailMind Sample Products', 'RM-SEED', 'active', NULL, '2026-09-17 07:18:52', '2026-09-17 21:19:06');

-- Table cash_drawer_movements
DROP TABLE IF EXISTS `cash_drawer_movements`;
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

-- Table cashier_shifts
DROP TABLE IF EXISTS `cashier_shifts`;
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
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3000', '9', '2026-06-20 07:30:00', '1000.00', 'closed', '2026-06-20 21:30:00', '1013.27', '1013.27', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-20', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3001', '9', '2026-06-21 07:30:00', '1100.00', 'closed', '2026-06-21 21:30:00', '1114.52', '1114.52', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-21', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3002', '9', '2026-06-22 07:30:00', '1200.00', 'closed', '2026-06-22 21:30:00', '1230.60', '1230.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-22', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3003', '9', '2026-06-23 07:30:00', '1300.00', 'closed', '2026-06-23 21:30:00', '1316.69', '1316.69', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-23', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3004', '9', '2026-06-25 07:30:00', '1000.00', 'closed', '2026-06-25 21:30:00', '1022.61', '1022.61', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-25', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3005', '9', '2026-06-26 07:30:00', '1100.00', 'closed', '2026-06-26 21:30:00', '1113.58', '1113.58', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-26', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3006', '9', '2026-06-27 07:30:00', '1200.00', 'closed', '2026-06-27 21:30:00', '1220.60', '1220.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-27', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3007', '9', '2026-06-28 07:30:00', '1300.00', 'closed', '2026-06-28 21:30:00', '1329.77', '1329.77', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-28', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3008', '9', '2026-06-30 07:30:00', '1000.00', 'closed', '2026-06-30 21:30:00', '1000.00', '1000.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-06-30', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3009', '9', '2026-07-01 07:30:00', '1100.00', 'closed', '2026-07-01 21:30:00', '1100.00', '1100.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-01', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3010', '9', '2026-07-02 07:30:00', '1200.00', 'closed', '2026-07-02 21:30:00', '1200.71', '1200.71', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-02', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3011', '9', '2026-07-03 07:30:00', '1300.00', 'closed', '2026-07-03 21:30:00', '1300.00', '1300.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-03', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3012', '9', '2026-07-04 07:30:00', '1400.00', 'closed', '2026-07-04 21:30:00', '1430.60', '1430.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-04', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3013', '9', '2026-07-05 07:30:00', '1000.00', 'closed', '2026-07-05 21:30:00', '1014.32', '1014.32', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-05', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3014', '9', '2026-07-06 07:30:00', '1100.00', 'closed', '2026-07-06 21:30:00', '1100.00', '1100.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-06', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3015', '9', '2026-07-08 07:30:00', '1300.00', 'closed', '2026-07-08 21:30:00', '1307.08', '1307.08', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-08', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3016', '9', '2026-07-09 07:30:00', '1400.00', 'closed', '2026-07-09 21:30:00', '1400.00', '1400.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-09', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3017', '9', '2026-07-10 07:30:00', '1000.00', 'closed', '2026-07-10 21:30:00', '1015.00', '1015.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-10', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3018', '9', '2026-07-11 07:30:00', '1100.00', 'closed', '2026-07-11 21:30:00', '1107.84', '1107.84', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-11', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3019', '9', '2026-07-12 07:30:00', '1200.00', 'closed', '2026-07-12 21:30:00', '1215.91', '1215.91', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-12', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3020', '9', '2026-07-13 07:30:00', '1300.00', 'closed', '2026-07-13 21:30:00', '1312.78', '1312.78', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-13', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3021', '9', '2026-07-14 07:30:00', '1400.00', 'closed', '2026-07-14 21:30:00', '1400.71', '1400.71', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-14', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3022', '9', '2026-07-15 07:30:00', '1000.00', 'closed', '2026-07-15 21:30:00', '1014.52', '1014.52', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-15', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3023', '9', '2026-07-17 07:30:00', '1200.00', 'closed', '2026-07-17 21:30:00', '1214.32', '1214.32', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-17', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3024', '9', '2026-07-18 07:30:00', '1300.00', 'closed', '2026-07-18 21:30:00', '1308.46', '1308.46', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-18', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3025', '9', '2026-07-19 07:30:00', '1400.00', 'closed', '2026-07-19 21:30:00', '1444.53', '1444.53', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-19', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3026', '9', '2026-07-21 07:30:00', '1100.00', 'closed', '2026-07-21 21:30:00', '1111.64', '1111.64', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-21', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3027', '9', '2026-07-22 07:30:00', '1200.00', 'closed', '2026-07-22 21:30:00', '1215.00', '1215.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-22', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3028', '9', '2026-07-23 07:30:00', '1300.00', 'closed', '2026-07-23 21:30:00', '1307.84', '1307.84', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-23', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3029', '9', '2026-07-24 07:30:00', '1400.00', 'closed', '2026-07-24 21:30:00', '1415.91', '1415.91', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-24', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3030', '9', '2026-07-25 07:30:00', '1000.00', 'closed', '2026-07-25 21:30:00', '1037.56', '1037.56', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-25', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3031', '9', '2026-07-26 07:30:00', '1100.00', 'closed', '2026-07-26 21:30:00', '1113.27', '1113.27', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-26', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3032', '9', '2026-07-27 07:30:00', '1200.00', 'closed', '2026-07-27 21:30:00', '1200.00', '1200.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-27', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3033', '9', '2026-07-28 07:30:00', '1300.00', 'closed', '2026-07-28 21:30:00', '1300.00', '1300.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-28', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3034', '9', '2026-07-29 07:30:00', '1400.00', 'closed', '2026-07-29 21:30:00', '1414.32', '1414.32', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-29', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3035', '9', '2026-07-30 07:30:00', '1000.00', 'closed', '2026-07-30 21:30:00', '1000.00', '1000.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-30', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3036', '9', '2026-07-31 07:30:00', '1100.00', 'closed', '2026-07-31 21:30:00', '1100.00', '1100.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-07-31', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3037', '9', '2026-08-01 07:30:00', '1100.00', 'closed', '2026-08-01 21:30:00', '1107.08', '1107.08', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-01', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3038', '9', '2026-08-03 07:30:00', '1300.00', 'closed', '2026-08-03 21:30:00', '1315.00', '1315.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-03', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3039', '9', '2026-08-04 07:30:00', '1400.00', 'closed', '2026-08-04 21:30:00', '1405.70', '1405.70', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-04', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3040', '9', '2026-08-05 07:30:00', '1000.00', 'closed', '2026-08-05 21:30:00', '1000.00', '1000.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-05', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3041', '9', '2026-08-06 07:30:00', '1100.00', 'closed', '2026-08-06 21:30:00', '1112.78', '1112.78', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-06', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3042', '9', '2026-08-07 07:30:00', '1200.00', 'closed', '2026-08-07 21:30:00', '1200.71', '1200.71', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-07', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3043', '9', '2026-08-08 07:30:00', '1300.00', 'closed', '2026-08-08 21:30:00', '1314.52', '1314.52', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-08', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3044', '9', '2026-08-09 07:30:00', '1400.00', 'closed', '2026-08-09 21:30:00', '1430.60', '1430.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-09', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3045', '9', '2026-08-10 07:30:00', '1000.00', 'closed', '2026-08-10 21:30:00', '1014.32', '1014.32', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-10', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3046', '9', '2026-08-11 07:30:00', '1100.00', 'closed', '2026-08-11 21:30:00', '1108.46', '1108.46', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-11', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3047', '9', '2026-08-12 07:30:00', '1200.00', 'closed', '2026-08-12 21:30:00', '1222.61', '1222.61', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-12', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3048', '9', '2026-08-13 07:30:00', '1300.00', 'closed', '2026-08-13 21:30:00', '1307.08', '1307.08', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-13', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3049', '9', '2026-08-14 07:30:00', '1400.00', 'closed', '2026-08-14 21:30:00', '1411.64', '1411.64', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-14', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3050', '9', '2026-08-16 07:30:00', '1100.00', 'closed', '2026-08-16 21:30:00', '1107.84', '1107.84', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-16', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3051', '9', '2026-08-17 07:30:00', '1200.00', 'closed', '2026-08-17 21:30:00', '1215.91', '1215.91', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-17', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3052', '9', '2026-08-18 07:30:00', '1300.00', 'closed', '2026-08-18 21:30:00', '1312.78', '1312.78', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-18', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3053', '9', '2026-08-20 07:30:00', '1000.00', 'closed', '2026-08-20 21:30:00', '1014.52', '1014.52', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-20', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3054', '9', '2026-08-21 07:30:00', '1100.00', 'closed', '2026-08-21 21:30:00', '1130.60', '1130.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-21', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3055', '9', '2026-08-22 07:30:00', '1200.00', 'closed', '2026-08-22 21:30:00', '1216.69', '1216.69', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-22', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3056', '9', '2026-08-23 07:30:00', '1300.00', 'closed', '2026-08-23 21:30:00', '1330.08', '1330.08', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-23', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3057', '9', '2026-08-24 07:30:00', '1400.00', 'closed', '2026-08-24 21:30:00', '1400.00', '1400.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-24', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3058', '9', '2026-08-25 07:30:00', '1000.00', 'closed', '2026-08-25 21:30:00', '1007.08', '1007.08', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-25', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3059', '9', '2026-08-26 07:30:00', '1100.00', 'closed', '2026-08-26 21:30:00', '1100.00', '1100.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-26', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3060', '9', '2026-08-27 07:30:00', '1200.00', 'closed', '2026-08-27 21:30:00', '1200.00', '1200.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-27', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3061', '9', '2026-08-29 07:30:00', '1400.00', 'closed', '2026-08-29 21:30:00', '1415.91', '1415.91', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-29', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3062', '9', '2026-08-30 07:30:00', '1000.00', 'closed', '2026-08-30 21:30:00', '1012.78', '1012.78', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-30', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3063', '9', '2026-08-31 07:30:00', '1100.00', 'closed', '2026-08-31 21:30:00', '1100.71', '1100.71', '0.00', 'RM_SEED_SALES_TREND_V1 2026-08-31', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3064', '9', '2026-09-01 07:30:00', '1100.00', 'closed', '2026-09-01 21:30:00', '1100.00', '1100.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-01', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3065', '9', '2026-09-02 07:30:00', '1200.00', 'closed', '2026-09-02 21:30:00', '1230.60', '1230.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-02', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3066', '9', '2026-09-03 07:30:00', '1300.00', 'closed', '2026-09-03 21:30:00', '1314.32', '1314.32', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-03', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3067', '9', '2026-09-04 07:30:00', '1400.00', 'closed', '2026-09-04 21:30:00', '1400.00', '1400.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-04', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3068', '9', '2026-09-06 07:30:00', '1100.00', 'closed', '2026-09-06 21:30:00', '1113.58', '1113.58', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-06', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3069', '9', '2026-09-07 07:30:00', '1200.00', 'closed', '2026-09-07 21:30:00', '1211.64', '1211.64', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-07', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3070', '9', '2026-09-08 07:30:00', '1300.00', 'closed', '2026-09-08 21:30:00', '1315.00', '1315.00', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-08', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3071', '9', '2026-09-09 07:30:00', '1400.00', 'closed', '2026-09-09 21:30:00', '1405.70', '1405.70', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-09', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3072', '9', '2026-09-11 07:30:00', '1100.00', 'closed', '2026-09-11 21:30:00', '1112.78', '1112.78', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-11', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3073', '9', '2026-09-12 07:30:00', '1200.00', 'closed', '2026-09-12 21:30:00', '1213.27', '1213.27', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-12', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3074', '9', '2026-09-13 07:30:00', '1300.00', 'closed', '2026-09-13 21:30:00', '1314.52', '1314.52', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-13', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3075', '9', '2026-09-14 07:30:00', '1400.00', 'closed', '2026-09-14 21:30:00', '1430.60', '1430.60', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-14', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3076', '9', '2026-09-15 07:30:00', '1000.00', 'closed', '2026-09-15 21:30:00', '1016.69', '1016.69', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-15', NULL, NULL);
INSERT INTO `cashier_shifts` (`shift_id`, `cashier_id`, `opened_at`, `opening_cash`, `status`, `closed_at`, `expected_cash`, `actual_cash`, `cash_variance`, `closing_notes`, `reviewed_by`, `reviewed_at`) VALUES ('3077', '9', '2026-09-18 07:30:00', '1300.00', 'closed', '2026-09-18 21:30:00', '1313.58', '1313.58', '0.00', 'RM_SEED_SALES_TREND_V1 2026-09-18', NULL, NULL);

-- Table categories
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('1', 'Beverages');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('2', 'Snacks');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('3', 'Pantry');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('4', 'Dairy');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('5', 'Bakery');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('6', 'Produce');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('7', 'Frozen Foods');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('8', 'Household');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('9', 'Personal Care');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('10', 'Baby Care');
INSERT INTO `categories` (`category_id`, `category_name`) VALUES ('11', 'Pet Care');

-- Table csv_import_logs
DROP TABLE IF EXISTS `csv_import_logs`;
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

-- Table cycle_count_schedules
DROP TABLE IF EXISTS `cycle_count_schedules`;
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

-- Table email_delivery_log
DROP TABLE IF EXISTS `email_delivery_log`;
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
INSERT INTO `email_delivery_log` (`email_log_id`, `recipient`, `subject`, `status`, `provider`, `error_message`, `created_at`) VALUES ('1', 'sordillamike1@gmail.com', 'RetailMind password reset', 'failed', 'disabled', 'Email is not configured. Install PHPMailer and set MAIL_* values.', '2026-09-15 06:39:29');
INSERT INTO `email_delivery_log` (`email_log_id`, `recipient`, `subject`, `status`, `provider`, `error_message`, `created_at`) VALUES ('2', 'sordillamike1@gmail.com', 'RetailMind email test', 'failed', 'disabled', 'Email is not configured. Install PHPMailer and set MAIL_* values.', '2026-09-15 06:48:23');
INSERT INTO `email_delivery_log` (`email_log_id`, `recipient`, `subject`, `status`, `provider`, `error_message`, `created_at`) VALUES ('3', 'sordillamike1@gmail.com', 'RetailMind email test', 'failed', 'brevo-api', 'Brevo API returned HTTP 401.', '2026-09-15 07:04:16');
INSERT INTO `email_delivery_log` (`email_log_id`, `recipient`, `subject`, `status`, `provider`, `error_message`, `created_at`) VALUES ('4', 'sordillamike1@gmail.com', 'RetailMind email test', 'failed', 'brevo-api', 'Brevo API returned HTTP 401.', '2026-09-15 07:06:53');
INSERT INTO `email_delivery_log` (`email_log_id`, `recipient`, `subject`, `status`, `provider`, `error_message`, `created_at`) VALUES ('5', 'sordillamike1@gmail.com', 'RetailMind email test', 'sent', 'brevo-api', NULL, '2026-09-15 07:14:55');
INSERT INTO `email_delivery_log` (`email_log_id`, `recipient`, `subject`, `status`, `provider`, `error_message`, `created_at`) VALUES ('6', 'sordillamike1@gmail.com', 'RetailMind email test', 'failed', 'brevo-api', 'Brevo API returned HTTP 401.', '2026-09-16 15:24:45');

-- Table fiscal_period_locks
DROP TABLE IF EXISTS `fiscal_period_locks`;
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

-- Table fiscal_periods
DROP TABLE IF EXISTS `fiscal_periods`;
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
INSERT INTO `fiscal_periods` (`period_id`, `period_name`, `start_date`, `end_date`, `status`, `created_by`, `created_at`, `closed_at`, `closed_by`) VALUES ('1', 'Q1', '2026-09-16', '2026-10-28', 'locked', '1', '2026-09-16 12:46:24', '2026-09-16 12:46:31', '1');
INSERT INTO `fiscal_periods` (`period_id`, `period_name`, `start_date`, `end_date`, `status`, `created_by`, `created_at`, `closed_at`, `closed_by`) VALUES ('2', 'Testing', '2026-09-17', '2026-10-17', 'open', '1', '2026-09-17 07:38:01', NULL, NULL);

-- Table forecast_decisions
DROP TABLE IF EXISTS `forecast_decisions`;
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

-- Table forecast_evaluations
DROP TABLE IF EXISTS `forecast_evaluations`;
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

-- Table forecast_runs
DROP TABLE IF EXISTS `forecast_runs`;
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

-- Table held_sales
DROP TABLE IF EXISTS `held_sales`;
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

-- Table inventory
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `uq_inventory_product` (`product_id`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`quantity_on_hand` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('1', '1', '112', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('2', '2', '133', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('3', '3', '90', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('4', '4', '274', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('5', '5', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('6', '6', '115', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('7', '7', '142', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('8', '8', '169', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('9', '9', '206', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('10', '10', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('11', '11', '126', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('12', '12', '138', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('13', '13', '96', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('14', '14', '46', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('15', '15', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('16', '16', '5', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('17', '17', '151', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('18', '18', '46', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('19', '19', '82', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('20', '20', '130', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('21', '21', '7', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('22', '22', '42', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('23', '23', '48', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('24', '24', '177', '2026-09-18 09:53:02');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('25', '25', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('26', '26', '4', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('27', '27', '24', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('28', '28', '48', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('29', '29', '44', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('30', '30', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('31', '31', '4', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('32', '32', '14', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('33', '33', '40', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('34', '34', '70', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('35', '35', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('36', '36', '4', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('37', '37', '14', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('38', '38', '28', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('39', '39', '44', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('40', '40', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('41', '41', '4', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('42', '42', '14', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('43', '43', '26', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('44', '44', '42', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('45', '45', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('46', '46', '3', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('47', '47', '14', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('48', '48', '18', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('49', '49', '82', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('50', '50', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('51', '51', '4', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('52', '52', '36', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('53', '53', '26', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('54', '54', '84', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('55', '55', '0', '2026-09-17 07:18:52');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('56', '56', '4', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('57', '57', '14', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('58', '58', '18', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('59', '59', '30', '2026-09-17 07:38:58');
INSERT INTO `inventory` (`inventory_id`, `product_id`, `quantity_on_hand`, `last_updated`) VALUES ('60', '60', '0', '2026-09-17 07:18:52');

-- Table inventory_adjustments
DROP TABLE IF EXISTS `inventory_adjustments`;
CREATE TABLE `inventory_adjustments` (
  `adjustment_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `adjustment_qty` int(11) NOT NULL,
  `adjustment_type` enum('damaged','missing','expired','other') NOT NULL,
  `reported_by` int(11) NOT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  PRIMARY KEY (`adjustment_id`),
  KEY `product_id` (`product_id`),
  KEY `reported_by` (`reported_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `inventory_adjustments_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `inventory_adjustments_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `inventory_adjustments_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table inventory_counts
DROP TABLE IF EXISTS `inventory_counts`;
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
  PRIMARY KEY (`count_id`),
  KEY `idx_inventory_counts_status` (`status`),
  KEY `idx_inventory_counts_product_counted_at` (`product_id`,`counted_at`),
  KEY `counted_by` (`counted_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `inventory_counts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `inventory_counts_ibfk_2` FOREIGN KEY (`counted_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `inventory_counts_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table login_attempts
DROP TABLE IF EXISTS `login_attempts`;
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

-- Table ml_settings
DROP TABLE IF EXISTS `ml_settings`;
CREATE TABLE `ml_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `ml_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('accuracy_threshold_wape', '35', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('forecast_period_days', '30', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('history_window_days', '365', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('holiday_dates', '', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('max_depth', '18', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('min_samples_leaf', '2', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('min_samples_split', '4', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('minimum_history_days', '30', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('minimum_nonzero_sales_days', '5', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('n_estimators', '300', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('prediction_interval_lower', '10', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('prediction_interval_upper', '90', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('preferred_history_days', '90', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('retrain_frequency_days', '7', NULL, '2026-09-14 15:29:33');
INSERT INTO `ml_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('retrain_new_sales_records', '100', NULL, '2026-09-14 15:29:33');

-- Table model_training_runs
DROP TABLE IF EXISTS `model_training_runs`;
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

-- Table notification_preferences
DROP TABLE IF EXISTS `notification_preferences`;
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
INSERT INTO `notification_preferences` (`pref_id`, `user_id`, `notify_low_stock`, `notify_replenishment`, `notify_adjustment`, `notify_email`, `notify_inapp`, `low_stock_threshold`, `updated_at`) VALUES ('1', '1', '1', '1', '1', '1', '1', '10', '2026-09-15 07:15:22');

-- Table notifications
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('low_stock','replenishment','adjustment','system','fiscal_period','expiring_stock','expired_stock') NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user_id` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table password_reset_tokens
DROP TABLE IF EXISTS `password_reset_tokens`;
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
INSERT INTO `password_reset_tokens` (`reset_id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES ('1', '2', 'bf39156cb200cc0f4cc0dff11f2a90f1fe0e3ef7af83d300f0656ec4d76f1946', '2026-09-15 07:09:29', NULL, '2026-09-15 06:39:29');

-- Table privileges
DROP TABLE IF EXISTS `privileges`;
CREATE TABLE `privileges` (
  `privilege_id` int(11) NOT NULL AUTO_INCREMENT,
  `privilege_key` varchar(80) NOT NULL,
  `privilege_name` varchar(120) NOT NULL,
  PRIMARY KEY (`privilege_id`),
  UNIQUE KEY `privilege_key` (`privilege_key`),
  UNIQUE KEY `privilege_name` (`privilege_name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES ('1', 'manage_users', 'Manage user accounts');
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES ('2', 'manage_roles', 'Assign user roles');
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES ('3', 'manage_privileges', 'Manage user privileges');
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES ('4', 'manage_branches', 'Create and manage branches');
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES ('5', 'manage_inventory', 'Manage branch inventory');
INSERT INTO `privileges` (`privilege_id`, `privilege_key`, `privilege_name`) VALUES ('6', 'view_inventory', 'View branch inventory');

-- Table product_batches
DROP TABLE IF EXISTS `product_batches`;
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
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('1', '1', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-001', '12', '12', NULL, '2026-09-17 07:38:57', 'RetailMind development seed', '2026-09-17 07:38:57');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('2', '2', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-002', '30', '30', NULL, '2026-09-17 07:38:57', 'RetailMind development seed', '2026-09-17 07:38:57');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('3', '3', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-003', '90', '90', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('4', '4', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-004', '168', '168', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('5', '6', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-006', '6', '6', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('6', '7', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-007', '30', '30', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('7', '8', NULL, 'RM-SEED-OPENING-V1-SKU-BEV-008', '54', '54', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('8', '9', NULL, 'RM-SEED-OPENING-V1-SKU-SNK-001', '88', '88', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('9', '11', NULL, 'RM-SEED-OPENING-V1-SKU-SNK-003', '5', '5', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('10', '12', NULL, 'RM-SEED-OPENING-V1-SKU-SNK-004', '14', '14', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('11', '13', NULL, 'RM-SEED-OPENING-V1-SKU-SNK-005', '96', '96', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('12', '14', NULL, 'RM-SEED-OPENING-V1-SKU-SNK-006', '46', '46', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('13', '16', NULL, 'RM-SEED-OPENING-V1-SKU-SNK-008', '5', '5', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('14', '17', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-001', '24', '24', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('15', '18', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-002', '46', '46', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('16', '19', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-003', '82', '82', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('17', '21', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-005', '7', '7', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('18', '22', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-006', '42', '42', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('19', '23', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-007', '48', '48', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('20', '24', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-008', '44', '44', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('21', '26', NULL, 'RM-SEED-OPENING-V1-SKU-DRY-010', '4', '4', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('22', '27', NULL, 'RM-SEED-OPENING-V1-SKU-DAI-001', '24', '24', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('23', '28', NULL, 'RM-SEED-OPENING-V1-SKU-DAI-002', '48', '48', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('24', '29', NULL, 'RM-SEED-OPENING-V1-SKU-DAI-003', '44', '44', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('25', '31', NULL, 'RM-SEED-OPENING-V1-SKU-DAI-005', '4', '4', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('26', '32', NULL, 'RM-SEED-OPENING-V1-SKU-DAI-006', '14', '14', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('27', '33', NULL, 'RM-SEED-OPENING-V1-SKU-BRD-001', '40', '40', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('28', '34', NULL, 'RM-SEED-OPENING-V1-SKU-BRD-002', '70', '70', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('29', '36', NULL, 'RM-SEED-OPENING-V1-SKU-BRD-004', '4', '4', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('30', '37', NULL, 'RM-SEED-OPENING-V1-SKU-PRO-001', '14', '14', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('31', '38', NULL, 'RM-SEED-OPENING-V1-SKU-PRO-002', '28', '28', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('32', '39', NULL, 'RM-SEED-OPENING-V1-SKU-PRO-003', '44', '44', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('33', '41', NULL, 'RM-SEED-OPENING-V1-SKU-PRO-005', '4', '4', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('34', '42', NULL, 'RM-SEED-OPENING-V1-SKU-PRO-006', '14', '14', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('35', '43', NULL, 'RM-SEED-OPENING-V1-SKU-FRZ-001', '26', '26', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('36', '44', NULL, 'RM-SEED-OPENING-V1-SKU-FRZ-002', '42', '42', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('37', '46', NULL, 'RM-SEED-OPENING-V1-SKU-FRZ-004', '3', '3', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('38', '47', NULL, 'RM-SEED-OPENING-V1-SKU-HOU-001', '14', '14', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('39', '48', NULL, 'RM-SEED-OPENING-V1-SKU-HOU-002', '18', '18', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('40', '49', NULL, 'RM-SEED-OPENING-V1-SKU-HOU-003', '82', '82', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('41', '51', NULL, 'RM-SEED-OPENING-V1-SKU-HOU-005', '4', '4', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('42', '52', NULL, 'RM-SEED-OPENING-V1-SKU-PER-001', '36', '36', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('43', '53', NULL, 'RM-SEED-OPENING-V1-SKU-PER-002', '26', '26', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('44', '54', NULL, 'RM-SEED-OPENING-V1-SKU-PER-003', '84', '84', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('45', '56', NULL, 'RM-SEED-OPENING-V1-SKU-PER-005', '4', '4', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('46', '57', NULL, 'RM-SEED-OPENING-V1-SKU-BAB-001', '14', '14', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('47', '58', NULL, 'RM-SEED-OPENING-V1-SKU-BAB-002', '18', '18', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('48', '59', NULL, 'RM-SEED-OPENING-V1-SKU-PET-001', '30', '30', NULL, '2026-09-17 07:38:58', 'RetailMind development seed', '2026-09-17 07:38:58');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('505', '1', '457', 'RM_SEED_SALES_TREND_V1-20260918-1', '194', '100', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('506', '2', '458', 'RM_SEED_SALES_TREND_V1-20260918-2', '214', '103', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('507', '4', '459', 'RM_SEED_SALES_TREND_V1-20260918-4', '172', '106', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('508', '6', '460', 'RM_SEED_SALES_TREND_V1-20260918-6', '205', '109', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('509', '7', '461', 'RM_SEED_SALES_TREND_V1-20260918-7', '186', '112', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('510', '8', '462', 'RM_SEED_SALES_TREND_V1-20260918-8', '189', '115', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('511', '9', '463', 'RM_SEED_SALES_TREND_V1-20260918-9', '226', '118', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('512', '11', '464', 'RM_SEED_SALES_TREND_V1-20260918-11', '205', '121', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('513', '12', '465', 'RM_SEED_SALES_TREND_V1-20260918-12', '219', '124', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('514', '17', '466', 'RM_SEED_SALES_TREND_V1-20260918-17', '274', '127', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('515', '20', '467', 'RM_SEED_SALES_TREND_V1-20260918-20', '233', '130', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');
INSERT INTO `product_batches` (`batch_id`, `product_id`, `receiving_id`, `batch_number`, `quantity`, `remaining_quantity`, `expiration_date`, `date_received`, `supplier`, `created_at`) VALUES ('516', '24', '468', 'RM_SEED_SALES_TREND_V1-20260918-24', '272', '133', NULL, '2026-06-19 07:00:00', 'RetailMind Seed Supplier', '2026-09-18 09:53:01');

-- Table products
DROP TABLE IF EXISTS `products`;
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
  CONSTRAINT `CONSTRAINT_1` CHECK (`unit_price` >= 0),
  CONSTRAINT `CONSTRAINT_2` CHECK (`cost_price` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('1', 'SKU-BEV-001', '890100000001', NULL, NULL, NULL, 'Spring Water 500ml', 'AquaPure', '1', '0.75', '0.32', '206', '94', '24', 'ClearSpring Distributors', 'ClearSpring Distributors', '3', '12', '24', '24', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('2', 'SKU-BEV-002', '890100000002', NULL, NULL, NULL, 'Spring Water 1.5L', 'AquaPure', '1', '1.35', '0.62', '244', '111', '18', 'ClearSpring Distributors', 'ClearSpring Distributors', '3', '8', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('3', 'SKU-BEV-003', '890100000003', NULL, NULL, NULL, 'Sparkling Water 330ml', 'FizzWell', '1', '1.10', '0.48', '90', '0', '18', 'ClearSpring Distributors', 'ClearSpring Distributors', '4', '8', '24', '24', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('4', 'SKU-BEV-004', '890100000004', NULL, NULL, NULL, 'Cola Can 330ml', 'RefreshCo', '1', '1.25', '0.58', '340', '66', '24', 'Metro Beverage Supply', 'Metro Beverage Supply', '4', '12', '24', '24', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('5', 'SKU-BEV-005', '890100000005', NULL, NULL, NULL, 'Orange Soda 500ml', 'RefreshCo', '1', '1.50', '0.70', '0', '0', '18', 'Metro Beverage Supply', 'Metro Beverage Supply', '4', '8', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('6', 'SKU-BEV-006', '890100000006', NULL, NULL, NULL, 'Apple Juice 1L', 'Orchard Gold', '1', '2.85', '1.45', '211', '96', '12', 'FreshFields Wholesale', 'FreshFields Wholesale', '5', '6', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('7', 'SKU-BEV-007', '890100000007', NULL, NULL, NULL, 'Iced Tea Lemon 500ml', 'TeaTrail', '1', '1.75', '0.82', '216', '74', '18', 'Metro Beverage Supply', 'Metro Beverage Supply', '4', '8', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('8', 'SKU-BEV-008', '890100000008', NULL, NULL, NULL, 'Energy Drink 250ml', 'VoltUp', '1', '2.40', '1.20', '243', '74', '18', 'Metro Beverage Supply', 'Metro Beverage Supply', '4', '8', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('9', 'SKU-SNK-001', '890100000009', NULL, NULL, NULL, 'Potato Chips Sea Salt 150g', 'CrispHouse', '2', '2.25', '1.05', '314', '108', '16', 'SnackSource Ltd', 'SnackSource Ltd', '5', '8', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('10', 'SKU-SNK-002', '890100000010', NULL, NULL, NULL, 'Tortilla Chips Nacho 200g', 'CrispHouse', '2', '2.75', '1.32', '0', '0', '12', 'SnackSource Ltd', 'SnackSource Ltd', '5', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('11', 'SKU-SNK-003', '890100000011', NULL, NULL, NULL, 'Peanuts Roasted 250g', 'NutHarvest', '2', '3.20', '1.70', '210', '84', '10', 'SnackSource Ltd', 'SnackSource Ltd', '6', '5', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('12', 'SKU-SNK-004', '890100000012', NULL, NULL, NULL, 'Trail Mix 200g', 'NutHarvest', '2', '4.50', '2.45', '233', '95', '8', 'SnackSource Ltd', 'SnackSource Ltd', '6', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('13', 'SKU-SNK-005', '890100000013', NULL, NULL, NULL, 'Chocolate Bar Milk 45g', 'CocoaPeak', '2', '1.40', '0.62', '96', '0', '24', 'SweetGoods Supply', 'SweetGoods Supply', '5', '12', '24', '24', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('14', 'SKU-SNK-006', '890100000014', NULL, NULL, NULL, 'Granola Bar Oats 6 Pack', 'DailyGrain', '2', '3.90', '1.95', '46', '0', '10', 'FreshFields Wholesale', 'FreshFields Wholesale', '5', '5', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('15', 'SKU-SNK-007', '890100000015', NULL, NULL, NULL, 'Popcorn Microwave 3 Pack', 'KernelJoy', '2', '3.50', '1.72', '0', '0', '8', 'SnackSource Ltd', 'SnackSource Ltd', '6', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('16', 'SKU-SNK-008', '890100000016', NULL, NULL, NULL, 'Crackers Original 250g', 'TableTime', '2', '2.60', '1.20', '5', '0', '10', 'SnackSource Ltd', 'SnackSource Ltd', '5', '5', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('17', 'SKU-DRY-001', '890100000017', NULL, NULL, NULL, 'Long Grain Rice 1kg', 'HarvestHome', '3', '3.25', '1.68', '298', '147', '12', 'Global Grocers', 'Global Grocers', '7', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('18', 'SKU-DRY-002', '890100000018', NULL, NULL, NULL, 'All Purpose Flour 1kg', 'HarvestHome', '3', '2.40', '1.15', '46', '0', '10', 'Global Grocers', 'Global Grocers', '7', '5', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('19', 'SKU-DRY-003', '890100000019', NULL, NULL, NULL, 'Granulated Sugar 1kg', 'HarvestHome', '3', '2.55', '1.20', '82', '0', '10', 'Global Grocers', 'Global Grocers', '7', '5', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('20', 'SKU-DRY-004', '890100000020', NULL, NULL, NULL, 'Spaghetti 500g', 'PastaMio', '3', '1.85', '0.82', '233', '103', '14', 'Global Grocers', 'Global Grocers', '7', '7', '20', '20', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('21', 'SKU-DRY-005', '890100000021', NULL, NULL, NULL, 'Macaroni 500g', 'PastaMio', '3', '1.85', '0.82', '7', '0', '14', 'Global Grocers', 'Global Grocers', '7', '7', '20', '20', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('22', 'SKU-DRY-006', '890100000022', NULL, NULL, NULL, 'Baked Beans 400g', 'PantryBest', '3', '1.65', '0.74', '42', '0', '18', 'Global Grocers', 'Global Grocers', '7', '9', '24', '24', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('23', 'SKU-DRY-007', '890100000023', NULL, NULL, NULL, 'Tomato Sauce 500g', 'PantryBest', '3', '2.35', '1.08', '48', '0', '12', 'Global Grocers', 'Global Grocers', '7', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('24', 'SKU-DRY-008', '890100000024', NULL, NULL, NULL, 'Peanut Butter 340g', 'NutHarvest', '3', '4.75', '2.45', '316', '139', '8', 'Global Grocers', 'Global Grocers', '7', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('25', 'SKU-DRY-009', '890100000025', NULL, NULL, NULL, 'Instant Noodles Chicken 5 Pack', 'NoodleTime', '3', '3.20', '1.50', '0', '0', '16', 'Global Grocers', 'Global Grocers', '7', '8', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('26', 'SKU-DRY-010', '890100000026', NULL, NULL, NULL, 'Cooking Oil Sunflower 1L', 'GoldenDrop', '3', '4.60', '2.30', '4', '0', '8', 'Global Grocers', 'Global Grocers', '7', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('27', 'SKU-DAI-001', '890100000027', NULL, NULL, NULL, 'Whole Milk 1L', 'MeadowFresh', '4', '2.10', '1.22', '24', '0', '12', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('28', 'SKU-DAI-002', '890100000028', NULL, NULL, NULL, 'Low Fat Milk 1L', 'MeadowFresh', '4', '2.10', '1.22', '48', '0', '12', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('29', 'SKU-DAI-003', '890100000029', NULL, NULL, NULL, 'Plain Yogurt 500g', 'CreamVale', '4', '3.25', '1.72', '44', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('30', 'SKU-DAI-004', '890100000030', NULL, NULL, NULL, 'Greek Yogurt 150g', 'CreamVale', '4', '1.45', '0.72', '0', '0', '12', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('31', 'SKU-DAI-005', '890100000031', NULL, NULL, NULL, 'Cheddar Cheese 200g', 'CheeseCraft', '4', '4.90', '2.65', '4', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '3', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('32', 'SKU-DAI-006', '890100000032', NULL, NULL, NULL, 'Butter Salted 250g', 'MeadowFresh', '4', '4.25', '2.20', '14', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '3', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('33', 'SKU-BRD-001', '890100000033', NULL, NULL, NULL, 'White Bread Loaf 600g', 'BakeHouse', '5', '2.80', '1.42', '40', '0', '10', 'DailyBake Bakery', 'DailyBake Bakery', '1', '5', '10', '10', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('34', 'SKU-BRD-002', '890100000034', NULL, NULL, NULL, 'Whole Wheat Bread Loaf 600g', 'BakeHouse', '5', '3.10', '1.58', '70', '0', '10', 'DailyBake Bakery', 'DailyBake Bakery', '1', '5', '10', '10', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('35', 'SKU-BRD-003', '890100000035', NULL, NULL, NULL, 'Croissants 4 Pack', 'BakeHouse', '5', '4.20', '2.18', '0', '0', '6', 'DailyBake Bakery', 'DailyBake Bakery', '1', '3', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('36', 'SKU-BRD-004', '890100000036', NULL, NULL, NULL, 'Burger Buns 6 Pack', 'BakeHouse', '5', '3.40', '1.70', '4', '0', '8', 'DailyBake Bakery', 'DailyBake Bakery', '1', '4', '8', '8', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('37', 'SKU-PRO-001', '890100000037', NULL, NULL, NULL, 'Red Apples 1kg', 'Orchard Gold', '6', '3.75', '1.95', '14', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('38', 'SKU-PRO-002', '890100000038', NULL, NULL, NULL, 'Bananas 1kg', 'GreenValley', '6', '2.25', '1.05', '28', '0', '10', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '5', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('39', 'SKU-PRO-003', '890100000039', NULL, NULL, NULL, 'Oranges 1kg', 'Orchard Gold', '6', '3.40', '1.70', '44', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('40', 'SKU-PRO-004', '890100000040', NULL, NULL, NULL, 'Potatoes 2kg', 'GreenValley', '6', '4.10', '2.05', '0', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('41', 'SKU-PRO-005', '890100000041', NULL, NULL, NULL, 'Onions 1kg', 'GreenValley', '6', '2.70', '1.25', '4', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('42', 'SKU-PRO-006', '890100000042', NULL, NULL, NULL, 'Tomatoes 500g', 'GreenValley', '6', '2.90', '1.45', '14', '0', '8', 'FreshFields Wholesale', 'FreshFields Wholesale', '2', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('43', 'SKU-FRZ-001', '890100000043', NULL, NULL, NULL, 'Frozen Peas 500g', 'FrostGarden', '7', '3.60', '1.85', '26', '0', '8', 'ColdChain Suppliers', 'ColdChain Suppliers', '5', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('44', 'SKU-FRZ-002', '890100000044', NULL, NULL, NULL, 'Frozen Mixed Vegetables 1kg', 'FrostGarden', '7', '5.25', '2.72', '42', '0', '6', 'ColdChain Suppliers', 'ColdChain Suppliers', '5', '3', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('45', 'SKU-FRZ-003', '890100000045', NULL, NULL, NULL, 'Frozen French Fries 1kg', 'FrostGarden', '7', '4.80', '2.40', '0', '0', '8', 'ColdChain Suppliers', 'ColdChain Suppliers', '5', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('46', 'SKU-FRZ-004', '890100000046', NULL, NULL, NULL, 'Vanilla Ice Cream 1L', 'IceCrown', '7', '6.50', '3.35', '3', '0', '6', 'ColdChain Suppliers', 'ColdChain Suppliers', '5', '3', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('47', 'SKU-HOU-001', '890100000047', NULL, NULL, NULL, 'Dishwashing Liquid 750ml', 'CleanNest', '8', '3.85', '1.90', '14', '0', '8', 'HomeCare Distributors', 'HomeCare Distributors', '6', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('48', 'SKU-HOU-002', '890100000048', NULL, NULL, NULL, 'Laundry Detergent 2L', 'CleanNest', '8', '8.90', '4.55', '18', '0', '6', 'HomeCare Distributors', 'HomeCare Distributors', '6', '3', '4', '4', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('49', 'SKU-HOU-003', '890100000049', NULL, NULL, NULL, 'Paper Towels 2 Roll', 'SoftNest', '8', '3.75', '1.82', '82', '0', '10', 'HomeCare Distributors', 'HomeCare Distributors', '6', '5', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('50', 'SKU-HOU-004', '890100000050', NULL, NULL, NULL, 'Toilet Tissue 4 Roll', 'SoftNest', '8', '5.90', '2.95', '0', '0', '10', 'HomeCare Distributors', 'HomeCare Distributors', '6', '5', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('51', 'SKU-HOU-005', '890100000051', NULL, NULL, NULL, 'Garbage Bags Medium 20 Pack', 'CleanNest', '8', '4.40', '2.10', '4', '0', '8', 'HomeCare Distributors', 'HomeCare Distributors', '6', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('52', 'SKU-PER-001', '890100000052', NULL, NULL, NULL, 'Bar Soap Aloe 100g', 'FreshTouch', '9', '1.60', '0.68', '36', '0', '12', 'PersonalCare Wholesale', 'PersonalCare Wholesale', '7', '6', '24', '24', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('53', 'SKU-PER-002', '890100000053', NULL, NULL, NULL, 'Shampoo Daily Care 400ml', 'FreshTouch', '9', '5.75', '2.80', '26', '0', '8', 'PersonalCare Wholesale', 'PersonalCare Wholesale', '7', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('54', 'SKU-PER-003', '890100000054', NULL, NULL, NULL, 'Toothpaste Mint 100ml', 'SmileBright', '9', '3.20', '1.42', '84', '0', '12', 'PersonalCare Wholesale', 'PersonalCare Wholesale', '7', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('55', 'SKU-PER-004', '890100000055', NULL, NULL, NULL, 'Toothbrush Medium', 'SmileBright', '9', '2.50', '1.05', '0', '0', '12', 'PersonalCare Wholesale', 'PersonalCare Wholesale', '7', '6', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('56', 'SKU-PER-005', '890100000056', NULL, NULL, NULL, 'Deodorant Roll On 50ml', 'FreshTouch', '9', '4.10', '1.95', '4', '0', '8', 'PersonalCare Wholesale', 'PersonalCare Wholesale', '7', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('57', 'SKU-BAB-001', '890100000057', NULL, NULL, NULL, 'Baby Wipes 64 Count', 'LittleSteps', '10', '4.90', '2.45', '14', '0', '8', 'FamilyGoods Supply', 'FamilyGoods Supply', '7', '4', '6', '6', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('58', 'SKU-BAB-002', '890100000058', NULL, NULL, NULL, 'Baby Diapers Size 4 20 Pack', 'LittleSteps', '10', '11.50', '6.10', '18', '0', '6', 'FamilyGoods Supply', 'FamilyGoods Supply', '7', '3', '4', '4', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('59', 'SKU-PET-001', '890100000059', NULL, NULL, NULL, 'Dog Food Chicken 2kg', 'PawPantry', '11', '9.75', '5.20', '30', '0', '6', 'PetSupply Wholesale', 'PetSupply Wholesale', '7', '3', '4', '4', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');
INSERT INTO `products` (`product_id`, `sku`, `barcode`, `case_barcode`, `parent_product_id`, `variant_label`, `product_name`, `brand`, `category_id`, `unit_price`, `cost_price`, `quantity_purchased`, `quantity_sold`, `reorder_level`, `supplier`, `preferred_supplier`, `supplier_lead_time_days`, `safety_stock`, `minimum_order_quantity`, `units_per_package`, `base_unit`, `receiving_unit`, `expiration_date`, `product_image`, `status`, `created_by`, `branch_id`, `created_at`) VALUES ('60', 'SKU-PET-002', '890100000060', NULL, NULL, NULL, 'Cat Food Tuna 400g', 'PawPantry', '11', '2.90', '1.42', '0', '0', '10', 'PetSupply Wholesale', 'PetSupply Wholesale', '7', '5', '12', '12', 'piece', 'package', NULL, NULL, 'active', NULL, '2', '2026-09-17 07:18:52');

-- Table promotions
DROP TABLE IF EXISTS `promotions`;
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

-- Table purchase_history
DROP TABLE IF EXISTS `purchase_history`;
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
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('1', '1', 'RetailMind development seed', '12', '0.32', '2026-09-17 07:38:57', '3.84', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('2', '2', 'RetailMind development seed', '30', '0.62', '2026-09-17 07:38:57', '18.60', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('3', '3', 'RetailMind development seed', '90', '0.48', '2026-09-17 07:38:58', '43.20', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('4', '4', 'RetailMind development seed', '168', '0.58', '2026-09-17 07:38:58', '97.44', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('5', '6', 'RetailMind development seed', '6', '1.45', '2026-09-17 07:38:58', '8.70', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('6', '7', 'RetailMind development seed', '30', '0.82', '2026-09-17 07:38:58', '24.60', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('7', '8', 'RetailMind development seed', '54', '1.20', '2026-09-17 07:38:58', '64.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('8', '9', 'RetailMind development seed', '88', '1.05', '2026-09-17 07:38:58', '92.40', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('9', '11', 'RetailMind development seed', '5', '1.70', '2026-09-17 07:38:58', '8.50', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('10', '12', 'RetailMind development seed', '14', '2.45', '2026-09-17 07:38:58', '34.30', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('11', '13', 'RetailMind development seed', '96', '0.62', '2026-09-17 07:38:58', '59.52', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('12', '14', 'RetailMind development seed', '46', '1.95', '2026-09-17 07:38:58', '89.70', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('13', '16', 'RetailMind development seed', '5', '1.20', '2026-09-17 07:38:58', '6.00', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('14', '17', 'RetailMind development seed', '24', '1.68', '2026-09-17 07:38:58', '40.32', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('15', '18', 'RetailMind development seed', '46', '1.15', '2026-09-17 07:38:58', '52.90', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('16', '19', 'RetailMind development seed', '82', '1.20', '2026-09-17 07:38:58', '98.40', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('17', '21', 'RetailMind development seed', '7', '0.82', '2026-09-17 07:38:58', '5.74', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('18', '22', 'RetailMind development seed', '42', '0.74', '2026-09-17 07:38:58', '31.08', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('19', '23', 'RetailMind development seed', '48', '1.08', '2026-09-17 07:38:58', '51.84', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('20', '24', 'RetailMind development seed', '44', '2.45', '2026-09-17 07:38:58', '107.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('21', '26', 'RetailMind development seed', '4', '2.30', '2026-09-17 07:38:58', '9.20', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('22', '27', 'RetailMind development seed', '24', '1.22', '2026-09-17 07:38:58', '29.28', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('23', '28', 'RetailMind development seed', '48', '1.22', '2026-09-17 07:38:58', '58.56', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('24', '29', 'RetailMind development seed', '44', '1.72', '2026-09-17 07:38:58', '75.68', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('25', '31', 'RetailMind development seed', '4', '2.65', '2026-09-17 07:38:58', '10.60', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('26', '32', 'RetailMind development seed', '14', '2.20', '2026-09-17 07:38:58', '30.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('27', '33', 'RetailMind development seed', '40', '1.42', '2026-09-17 07:38:58', '56.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('28', '34', 'RetailMind development seed', '70', '1.58', '2026-09-17 07:38:58', '110.60', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('29', '36', 'RetailMind development seed', '4', '1.70', '2026-09-17 07:38:58', '6.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('30', '37', 'RetailMind development seed', '14', '1.95', '2026-09-17 07:38:58', '27.30', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('31', '38', 'RetailMind development seed', '28', '1.05', '2026-09-17 07:38:58', '29.40', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('32', '39', 'RetailMind development seed', '44', '1.70', '2026-09-17 07:38:58', '74.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('33', '41', 'RetailMind development seed', '4', '1.25', '2026-09-17 07:38:58', '5.00', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('34', '42', 'RetailMind development seed', '14', '1.45', '2026-09-17 07:38:58', '20.30', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('35', '43', 'RetailMind development seed', '26', '1.85', '2026-09-17 07:38:58', '48.10', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('36', '44', 'RetailMind development seed', '42', '2.72', '2026-09-17 07:38:58', '114.24', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('37', '46', 'RetailMind development seed', '3', '3.35', '2026-09-17 07:38:58', '10.05', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('38', '47', 'RetailMind development seed', '14', '1.90', '2026-09-17 07:38:58', '26.60', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('39', '48', 'RetailMind development seed', '18', '4.55', '2026-09-17 07:38:58', '81.90', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('40', '49', 'RetailMind development seed', '82', '1.82', '2026-09-17 07:38:58', '149.24', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('41', '51', 'RetailMind development seed', '4', '2.10', '2026-09-17 07:38:58', '8.40', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('42', '52', 'RetailMind development seed', '36', '0.68', '2026-09-17 07:38:58', '24.48', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('43', '53', 'RetailMind development seed', '26', '2.80', '2026-09-17 07:38:58', '72.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('44', '54', 'RetailMind development seed', '84', '1.42', '2026-09-17 07:38:58', '119.28', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('45', '56', 'RetailMind development seed', '4', '1.95', '2026-09-17 07:38:58', '7.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('46', '57', 'RetailMind development seed', '14', '2.45', '2026-09-17 07:38:58', '34.30', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('47', '58', 'RetailMind development seed', '18', '6.10', '2026-09-17 07:38:58', '109.80', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('48', '59', 'RetailMind development seed', '30', '5.20', '2026-09-17 07:38:58', '156.00', NULL);
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('505', '1', 'RetailMind Seed Supplier', '194', '0.32', '2026-06-19 07:00:00', '62.08', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('506', '2', 'RetailMind Seed Supplier', '214', '0.62', '2026-06-19 07:00:00', '132.68', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('507', '4', 'RetailMind Seed Supplier', '172', '0.58', '2026-06-19 07:00:00', '99.76', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('508', '6', 'RetailMind Seed Supplier', '205', '1.45', '2026-06-19 07:00:00', '297.25', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('509', '7', 'RetailMind Seed Supplier', '186', '0.82', '2026-06-19 07:00:00', '152.52', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('510', '8', 'RetailMind Seed Supplier', '189', '1.20', '2026-06-19 07:00:00', '226.80', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('511', '9', 'RetailMind Seed Supplier', '226', '1.05', '2026-06-19 07:00:00', '237.30', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('512', '11', 'RetailMind Seed Supplier', '205', '1.70', '2026-06-19 07:00:00', '348.50', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('513', '12', 'RetailMind Seed Supplier', '219', '2.45', '2026-06-19 07:00:00', '536.55', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('514', '17', 'RetailMind Seed Supplier', '274', '1.68', '2026-06-19 07:00:00', '460.32', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('515', '20', 'RetailMind Seed Supplier', '233', '0.82', '2026-06-19 07:00:00', '191.06', '11');
INSERT INTO `purchase_history` (`purchase_id`, `product_id`, `supplier`, `quantity_purchased`, `cost_price`, `purchase_date`, `total_cost`, `recorded_by`) VALUES ('516', '24', 'RetailMind Seed Supplier', '272', '2.45', '2026-06-19 07:00:00', '666.40', '11');

-- Table purchase_order_items
DROP TABLE IF EXISTS `purchase_order_items`;
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

-- Table purchase_orders
DROP TABLE IF EXISTS `purchase_orders`;
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

-- Table replenishment_requests
DROP TABLE IF EXISTS `replenishment_requests`;
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
  CONSTRAINT `CONSTRAINT_1` CHECK (`request_qty` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table role_privileges
DROP TABLE IF EXISTS `role_privileges`;
CREATE TABLE `role_privileges` (
  `role_id` int(11) NOT NULL,
  `privilege_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`privilege_id`),
  KEY `privilege_id` (`privilege_id`),
  CONSTRAINT `role_privileges_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `role_privileges_ibfk_2` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`privilege_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('1', '1');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('1', '2');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('1', '3');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('1', '4');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('1', '6');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('2', '1');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('2', '2');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('2', '3');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('2', '4');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('2', '6');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('3', '5');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('3', '6');
INSERT INTO `role_privileges` (`role_id`, `privilege_id`) VALUES ('4', '6');

-- Table roles
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `roles` (`role_id`, `role_name`) VALUES ('2', 'admin');
INSERT INTO `roles` (`role_id`, `role_name`) VALUES ('4', 'cashier');
INSERT INTO `roles` (`role_id`, `role_name`) VALUES ('3', 'inventory_manager');
INSERT INTO `roles` (`role_id`, `role_name`) VALUES ('1', 'super_admin');

-- Table sale_item_batches
DROP TABLE IF EXISTS `sale_item_batches`;
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
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18728', '18760', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18729', '18761', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18730', '18762', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18731', '18763', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18732', '18764', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18733', '18765', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18734', '18766', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18735', '18767', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18736', '18768', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18737', '18769', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18738', '18770', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18739', '18771', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18740', '18772', '506', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18741', '18773', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18742', '18774', '511', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18743', '18775', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18744', '18776', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18745', '18777', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18746', '18778', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18747', '18779', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18748', '18780', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18749', '18781', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18750', '18782', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18751', '18783', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18752', '18784', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18753', '18785', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18754', '18786', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18755', '18787', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18756', '18788', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18757', '18789', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18758', '18790', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18759', '18791', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18760', '18792', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18761', '18793', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18762', '18794', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18763', '18795', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18764', '18796', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18765', '18797', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18766', '18798', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18767', '18799', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18768', '18800', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18769', '18801', '506', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18770', '18802', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18771', '18803', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18772', '18804', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18773', '18805', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18774', '18806', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18775', '18807', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18776', '18808', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18777', '18809', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18778', '18810', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18779', '18811', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18780', '18812', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18781', '18813', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18782', '18814', '512', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18783', '18815', '510', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18784', '18816', '505', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18785', '18817', '515', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18786', '18818', '510', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18787', '18819', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18788', '18820', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18789', '18821', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18790', '18822', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18791', '18823', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18792', '18824', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18793', '18825', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18794', '18826', '510', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18795', '18827', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18796', '18828', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18797', '18829', '515', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18798', '18830', '510', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18799', '18831', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18800', '18832', '508', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18801', '18833', '515', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18802', '18834', '506', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18803', '18835', '513', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18804', '18836', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18805', '18837', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18806', '18838', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18807', '18839', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18808', '18840', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18809', '18841', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18810', '18842', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18811', '18843', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18812', '18844', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18813', '18845', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18814', '18846', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18815', '18847', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18816', '18848', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18817', '18849', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18818', '18850', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18819', '18851', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18820', '18852', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18821', '18853', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18822', '18854', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18823', '18855', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18824', '18856', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18825', '18857', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18826', '18858', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18827', '18859', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18828', '18860', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18829', '18861', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18830', '18862', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18831', '18863', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18832', '18864', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18833', '18865', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18834', '18866', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18835', '18867', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18836', '18868', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18837', '18869', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18838', '18870', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18839', '18871', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18840', '18872', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18841', '18873', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18842', '18874', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18843', '18875', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18844', '18876', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18845', '18877', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18846', '18878', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18847', '18879', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18848', '18880', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18849', '18881', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18850', '18882', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18851', '18883', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18852', '18884', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18853', '18885', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18854', '18886', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18855', '18887', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18856', '18888', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18857', '18889', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18858', '18890', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18859', '18891', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18860', '18892', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18861', '18893', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18862', '18894', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18863', '18895', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18864', '18896', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18865', '18897', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18866', '18898', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18867', '18899', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18868', '18900', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18869', '18901', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18870', '18902', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18871', '18903', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18872', '18904', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18873', '18905', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18874', '18906', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18875', '18907', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18876', '18908', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18877', '18909', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18878', '18910', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18879', '18911', '510', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18880', '18912', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18881', '18913', '515', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18882', '18914', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18883', '18915', '508', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18884', '18916', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18885', '18917', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18886', '18918', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18887', '18919', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18888', '18920', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18889', '18921', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18890', '18922', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18891', '18923', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18892', '18924', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18893', '18925', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18894', '18926', '506', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18895', '18927', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18896', '18928', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18897', '18929', '511', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18898', '18930', '506', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18899', '18931', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18900', '18932', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18901', '18933', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18902', '18934', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18903', '18935', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18904', '18936', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18905', '18937', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18906', '18938', '512', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18907', '18939', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18908', '18940', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18909', '18941', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18910', '18942', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18911', '18943', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18912', '18944', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18913', '18945', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18914', '18946', '510', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18915', '18947', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18916', '18948', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18917', '18949', '515', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18918', '18950', '510', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18919', '18951', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18920', '18952', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18921', '18953', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18922', '18954', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18923', '18955', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18924', '18956', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18925', '18957', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18926', '18958', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18927', '18959', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18928', '18960', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18929', '18961', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18930', '18962', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18931', '18963', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18932', '18964', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18933', '18965', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18934', '18966', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18935', '18967', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18936', '18968', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18937', '18969', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18938', '18970', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18939', '18971', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18940', '18972', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18941', '18973', '512', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18942', '18974', '507', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18943', '18975', '510', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18944', '18976', '505', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18945', '18977', '512', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18946', '18978', '515', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18947', '18979', '510', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18948', '18980', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18949', '18981', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18950', '18982', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18951', '18983', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18952', '18984', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18953', '18985', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18954', '18986', '506', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18955', '18987', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18956', '18988', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18957', '18989', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18958', '18990', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18959', '18991', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18960', '18992', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18961', '18993', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18962', '18994', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18963', '18995', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18964', '18996', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18965', '18997', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18966', '18998', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18967', '18999', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18968', '19000', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18969', '19001', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18970', '19002', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18971', '19003', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18972', '19004', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18973', '19005', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18974', '19006', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18975', '19007', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18976', '19008', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18977', '19009', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18978', '19010', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18979', '19011', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18980', '19012', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18981', '19013', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18982', '19014', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18983', '19015', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18984', '19016', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18985', '19017', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18986', '19018', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18987', '19019', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18988', '19020', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18989', '19021', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18990', '19022', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18991', '19023', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18992', '19024', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18993', '19025', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18994', '19026', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18995', '19027', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18996', '19028', '506', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18997', '19029', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18998', '19030', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('18999', '19031', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19000', '19032', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19001', '19033', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19002', '19034', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19003', '19035', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19004', '19036', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19005', '19037', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19006', '19038', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19007', '19039', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19008', '19040', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19009', '19041', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19010', '19042', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19011', '19043', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19012', '19044', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19013', '19045', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19014', '19046', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19015', '19047', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19016', '19048', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19017', '19049', '510', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19018', '19050', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19019', '19051', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19020', '19052', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19021', '19053', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19022', '19054', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19023', '19055', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19024', '19056', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19025', '19057', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19026', '19058', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19027', '19059', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19028', '19060', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19029', '19061', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19030', '19062', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19031', '19063', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19032', '19064', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19033', '19065', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19034', '19066', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19035', '19067', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19036', '19068', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19037', '19069', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19038', '19070', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19039', '19071', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19040', '19072', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19041', '19073', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19042', '19074', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19043', '19075', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19044', '19076', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19045', '19077', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19046', '19078', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19047', '19079', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19048', '19080', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19049', '19081', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19050', '19082', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19051', '19083', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19052', '19084', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19053', '19085', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19054', '19086', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19055', '19087', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19056', '19088', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19057', '19089', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19058', '19090', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19059', '19091', '512', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19060', '19092', '507', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19061', '19093', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19062', '19094', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19063', '19095', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19064', '19096', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19065', '19097', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19066', '19098', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19067', '19099', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19068', '19100', '506', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19069', '19101', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19070', '19102', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19071', '19103', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19072', '19104', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19073', '19105', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19074', '19106', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19075', '19107', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19076', '19108', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19077', '19109', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19078', '19110', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19079', '19111', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19080', '19112', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19081', '19113', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19082', '19114', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19083', '19115', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19084', '19116', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19085', '19117', '510', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19086', '19118', '515', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19087', '19119', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19088', '19120', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19089', '19121', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19090', '19122', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19091', '19123', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19092', '19124', '510', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19093', '19125', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19094', '19126', '515', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19095', '19127', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19096', '19128', '508', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19097', '19129', '506', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19098', '19130', '513', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19099', '19131', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19100', '19132', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19101', '19133', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19102', '19134', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19103', '19135', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19104', '19136', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19105', '19137', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19106', '19138', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19107', '19139', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19108', '19140', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19109', '19141', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19110', '19142', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19111', '19143', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19112', '19144', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19113', '19145', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19114', '19146', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19115', '19147', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19116', '19148', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19117', '19149', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19118', '19150', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19119', '19151', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19120', '19152', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19121', '19153', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19122', '19154', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19123', '19155', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19124', '19156', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19125', '19157', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19126', '19158', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19127', '19159', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19128', '19160', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19129', '19161', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19130', '19162', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19131', '19163', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19132', '19164', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19133', '19165', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19134', '19166', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19135', '19167', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19136', '19168', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19137', '19169', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19138', '19170', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19139', '19171', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19140', '19172', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19141', '19173', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19142', '19174', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19143', '19175', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19144', '19176', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19145', '19177', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19146', '19178', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19147', '19179', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19148', '19180', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19149', '19181', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19150', '19182', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19151', '19183', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19152', '19184', '512', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19153', '19185', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19154', '19186', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19155', '19187', '505', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19156', '19188', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19157', '19189', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19158', '19190', '510', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19159', '19191', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19160', '19192', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19161', '19193', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19162', '19194', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19163', '19195', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19164', '19196', '509', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19165', '19197', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19166', '19198', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19167', '19199', '514', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19168', '19200', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19169', '19201', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19170', '19202', '507', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19171', '19203', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19172', '19204', '505', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19173', '19205', '510', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19174', '19206', '515', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19175', '19207', '508', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19176', '19208', '513', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19177', '19209', '508', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19178', '19210', '515', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19179', '19211', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19180', '19212', '508', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19181', '19213', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19182', '19214', '513', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19183', '19215', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19184', '19216', '506', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19185', '19217', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19186', '19218', '511', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19187', '19219', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19188', '19220', '506', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19189', '19221', '513', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19190', '19222', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19191', '19223', '511', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19192', '19224', '506', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19193', '19225', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19194', '19226', '516', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19195', '19227', '511', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19196', '19228', '514', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19197', '19229', '509', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19198', '19230', '516', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19199', '19231', '514', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19200', '19232', '507', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19201', '19233', '512', '2');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19202', '19234', '505', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19203', '19235', '511', '3');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19204', '19236', '516', '4');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19205', '19237', '509', '1');
INSERT INTO `sale_item_batches` (`sale_item_batch_id`, `sale_item_id`, `batch_id`, `quantity`) VALUES ('19206', '19238', '514', '2');

-- Table sale_items
DROP TABLE IF EXISTS `sale_items`;
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
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18760', '9299', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18761', '9300', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18762', '9301', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18763', '9302', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18764', '9303', '12', '1', '4.28', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18765', '9304', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18766', '9304', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18767', '9305', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18768', '9305', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18769', '9306', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18770', '9306', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18771', '9307', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18772', '9307', '2', '3', '1.35', '4.05');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18773', '9308', '24', '2', '4.75', '9.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18774', '9308', '9', '4', '2.36', '9.44');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18775', '9309', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18776', '9309', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18777', '9309', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18778', '9310', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18779', '9310', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18780', '9310', '2', '4', '1.35', '5.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18781', '9311', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18782', '9311', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18783', '9311', '9', '1', '2.36', '2.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18784', '9312', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18785', '9312', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18786', '9312', '24', '2', '5.23', '10.46');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18787', '9313', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18788', '9314', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18789', '9315', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18790', '9316', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18791', '9317', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18792', '9317', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18793', '9317', '8', '2', '2.64', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18794', '9318', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18795', '9318', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18796', '9318', '20', '3', '1.76', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18797', '9319', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18798', '9319', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18799', '9319', '6', '4', '2.85', '11.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18800', '9320', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18801', '9320', '2', '3', '1.35', '4.05');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18802', '9320', '12', '1', '4.73', '4.73');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18803', '9321', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18804', '9322', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18805', '9323', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18806', '9324', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18807', '9325', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18808', '9325', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18809', '9326', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18810', '9326', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18811', '9327', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18812', '9327', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18813', '9328', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18814', '9328', '11', '1', '3.52', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18815', '9329', '8', '4', '2.64', '10.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18816', '9329', '1', '2', '0.71', '1.42');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18817', '9330', '20', '1', '1.76', '1.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18818', '9330', '8', '3', '2.40', '7.20');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18819', '9331', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18820', '9331', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18821', '9331', '4', '1', '1.31', '1.31');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18822', '9332', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18823', '9332', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18824', '9332', '11', '2', '3.52', '7.04');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18825', '9333', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18826', '9333', '8', '1', '2.64', '2.64');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18827', '9333', '1', '3', '0.71', '2.13');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18828', '9334', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18829', '9334', '20', '2', '1.76', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18830', '9334', '8', '4', '2.40', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18831', '9335', '12', '1', '4.28', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18832', '9335', '6', '3', '2.85', '8.55');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18833', '9335', '20', '1', '1.94', '1.94');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18834', '9336', '2', '2', '1.35', '2.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18835', '9336', '12', '4', '4.73', '18.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18836', '9336', '6', '2', '3.14', '6.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18837', '9337', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18838', '9337', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18839', '9338', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18840', '9338', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18841', '9338', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18842', '9339', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18843', '9340', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18844', '9340', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18845', '9341', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18846', '9341', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18847', '9341', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18848', '9342', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18849', '9342', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18850', '9342', '2', '4', '1.35', '5.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18851', '9343', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18852', '9343', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18853', '9343', '9', '1', '2.36', '2.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18854', '9344', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18855', '9345', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18856', '9346', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18857', '9347', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18858', '9347', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18859', '9348', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18860', '9348', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18861', '9349', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18862', '9350', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18863', '9351', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18864', '9351', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18865', '9352', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18866', '9352', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18867', '9353', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18868', '9353', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18869', '9353', '4', '1', '1.31', '1.31');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18870', '9354', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18871', '9354', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18872', '9354', '11', '2', '3.52', '7.04');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18873', '9355', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18874', '9356', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18875', '9357', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18876', '9358', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18877', '9359', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18878', '9359', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18879', '9360', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18880', '9360', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18881', '9361', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18882', '9361', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18883', '9362', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18884', '9362', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18885', '9363', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18886', '9363', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18887', '9363', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18888', '9364', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18889', '9364', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18890', '9364', '7', '1', '1.84', '1.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18891', '9365', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18892', '9365', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18893', '9365', '17', '2', '3.58', '7.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18894', '9366', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18895', '9367', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18896', '9368', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18897', '9369', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18898', '9369', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18899', '9370', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18900', '9370', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18901', '9371', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18902', '9371', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18903', '9372', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18904', '9373', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18905', '9374', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18906', '9375', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18907', '9375', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18908', '9376', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18909', '9376', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18910', '9377', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18911', '9377', '8', '1', '2.64', '2.64');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18912', '9378', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18913', '9378', '20', '2', '1.76', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18914', '9379', '12', '1', '4.28', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18915', '9379', '6', '3', '2.85', '8.55');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18916', '9380', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18917', '9380', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18918', '9380', '8', '2', '2.64', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18919', '9381', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18920', '9381', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18921', '9381', '20', '3', '1.76', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18922', '9382', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18923', '9382', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18924', '9382', '6', '4', '2.85', '11.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18925', '9383', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18926', '9383', '2', '3', '1.35', '4.05');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18927', '9383', '12', '1', '4.73', '4.73');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18928', '9384', '24', '2', '4.75', '9.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18929', '9384', '9', '4', '2.36', '9.44');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18930', '9384', '2', '2', '1.49', '2.98');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18931', '9385', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18932', '9385', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18933', '9386', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18934', '9386', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18935', '9387', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18936', '9387', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18937', '9388', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18938', '9388', '11', '1', '3.52', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18939', '9389', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18940', '9389', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18941', '9389', '4', '1', '1.31', '1.31');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18942', '9390', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18943', '9390', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18944', '9390', '11', '2', '3.52', '7.04');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18945', '9391', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18946', '9391', '8', '1', '2.64', '2.64');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18947', '9391', '1', '3', '0.71', '2.13');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18948', '9392', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18949', '9392', '20', '2', '1.76', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18950', '9392', '8', '4', '2.40', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18951', '9393', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18952', '9394', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18953', '9395', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18954', '9396', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18955', '9397', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18956', '9397', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18957', '9398', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18958', '9398', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18959', '9399', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18960', '9399', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18961', '9400', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18962', '9400', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18963', '9401', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18964', '9401', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18965', '9401', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18966', '9402', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18967', '9402', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18968', '9402', '7', '1', '1.84', '1.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18969', '9403', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18970', '9403', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18971', '9403', '17', '2', '3.58', '7.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18972', '9404', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18973', '9404', '11', '1', '3.52', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18974', '9404', '4', '3', '1.19', '3.57');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18975', '9405', '8', '4', '2.64', '10.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18976', '9405', '1', '2', '0.71', '1.42');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18977', '9405', '11', '4', '3.20', '12.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18978', '9406', '20', '1', '1.76', '1.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18979', '9406', '8', '3', '2.40', '7.20');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18980', '9406', '1', '1', '0.79', '0.79');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18981', '9407', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18982', '9408', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18983', '9409', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18984', '9410', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18985', '9411', '12', '1', '4.28', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18986', '9412', '2', '2', '1.35', '2.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18987', '9413', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18988', '9413', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18989', '9414', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18990', '9414', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18991', '9414', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18992', '9415', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18993', '9416', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18994', '9416', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18995', '9417', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18996', '9417', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18997', '9417', '8', '2', '2.64', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18998', '9418', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('18999', '9419', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19000', '9420', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19001', '9421', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19002', '9421', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19003', '9421', '4', '1', '1.31', '1.31');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19004', '9422', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19005', '9422', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19006', '9422', '11', '2', '3.52', '7.04');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19007', '9423', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19008', '9424', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19009', '9425', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19010', '9425', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19011', '9426', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19012', '9426', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19013', '9427', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19014', '9427', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19015', '9427', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19016', '9428', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19017', '9428', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19018', '9428', '7', '1', '1.84', '1.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19019', '9429', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19020', '9430', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19021', '9431', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19022', '9431', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19023', '9432', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19024', '9432', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19025', '9433', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19026', '9433', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19027', '9434', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19028', '9434', '2', '3', '1.35', '4.05');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19029', '9435', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19030', '9435', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19031', '9435', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19032', '9436', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19033', '9436', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19034', '9436', '2', '4', '1.35', '5.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19035', '9437', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19036', '9437', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19037', '9437', '9', '1', '2.36', '2.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19038', '9438', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19039', '9438', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19040', '9438', '24', '2', '5.23', '10.46');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19041', '9439', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19042', '9440', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19043', '9441', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19044', '9442', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19045', '9442', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19046', '9443', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19047', '9443', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19048', '9444', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19049', '9444', '8', '1', '2.64', '2.64');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19050', '9445', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19051', '9445', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19052', '9445', '8', '2', '2.64', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19053', '9446', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19054', '9446', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19055', '9446', '20', '3', '1.76', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19056', '9447', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19057', '9447', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19058', '9447', '6', '4', '2.85', '11.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19059', '9448', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19060', '9449', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19061', '9450', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19062', '9451', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19063', '9451', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19064', '9452', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19065', '9452', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19066', '9453', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19067', '9453', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19068', '9454', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19069', '9455', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19070', '9456', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19071', '9457', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19072', '9458', '24', '2', '4.75', '9.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19073', '9459', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19074', '9459', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19075', '9460', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19076', '9460', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19077', '9461', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19078', '9461', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19079', '9462', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19080', '9462', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19081', '9463', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19082', '9463', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19083', '9463', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19084', '9464', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19085', '9464', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19086', '9464', '7', '1', '1.84', '1.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19087', '9465', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19088', '9465', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19089', '9465', '17', '2', '3.58', '7.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19090', '9466', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19091', '9466', '11', '1', '3.52', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19092', '9466', '4', '3', '1.19', '3.57');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19093', '9467', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19094', '9467', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19095', '9468', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19096', '9468', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19097', '9469', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19098', '9469', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19099', '9470', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19100', '9470', '2', '3', '1.35', '4.05');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19101', '9471', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19102', '9471', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19103', '9471', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19104', '9472', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19105', '9472', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19106', '9472', '2', '4', '1.35', '5.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19107', '9473', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19108', '9473', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19109', '9473', '9', '1', '2.36', '2.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19110', '9474', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19111', '9474', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19112', '9474', '24', '2', '5.23', '10.46');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19113', '9475', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19114', '9476', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19115', '9477', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19116', '9478', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19117', '9479', '8', '4', '2.64', '10.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19118', '9480', '20', '1', '1.76', '1.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19119', '9481', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19120', '9481', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19121', '9482', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19122', '9482', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19123', '9483', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19124', '9483', '8', '1', '2.64', '2.64');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19125', '9484', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19126', '9484', '20', '2', '1.76', '3.52');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19127', '9485', '12', '1', '4.28', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19128', '9485', '6', '3', '2.85', '8.55');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19129', '9486', '2', '2', '1.35', '2.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19130', '9486', '12', '4', '4.73', '18.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19131', '9487', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19132', '9487', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19133', '9487', '8', '2', '2.64', '5.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19134', '9488', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19135', '9489', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19136', '9489', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19137', '9490', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19138', '9490', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19139', '9490', '4', '1', '1.31', '1.31');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19140', '9491', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19141', '9491', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19142', '9492', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19143', '9492', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19144', '9493', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19145', '9493', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19146', '9494', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19147', '9494', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19148', '9494', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19149', '9495', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19150', '9495', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19151', '9495', '7', '1', '1.84', '1.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19152', '9496', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19153', '9496', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19154', '9496', '17', '2', '3.58', '7.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19155', '9497', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19156', '9498', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19157', '9499', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19158', '9499', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19159', '9500', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19160', '9500', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19161', '9501', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19162', '9501', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19163', '9501', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19164', '9502', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19165', '9502', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19166', '9502', '2', '4', '1.35', '5.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19167', '9503', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19168', '9504', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19169', '9505', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19170', '9505', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19171', '9506', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19172', '9506', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19173', '9507', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19174', '9508', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19175', '9509', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19176', '9510', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19177', '9511', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19178', '9511', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19179', '9512', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19180', '9512', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19181', '9513', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19182', '9513', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19183', '9514', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19184', '9514', '11', '3', '3.20', '9.60');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19185', '9514', '4', '1', '1.31', '1.31');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19186', '9515', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19187', '9515', '1', '4', '0.79', '3.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19188', '9515', '11', '2', '3.52', '7.04');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19189', '9516', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19190', '9516', '8', '1', '2.64', '2.64');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19191', '9516', '1', '3', '0.71', '2.13');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19192', '9517', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19193', '9518', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19194', '9519', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19195', '9520', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19196', '9520', '7', '2', '1.66', '3.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19197', '9520', '24', '4', '4.75', '19.00');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19198', '9521', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19199', '9521', '17', '3', '3.25', '9.75');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19200', '9521', '7', '1', '1.84', '1.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19201', '9522', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19202', '9522', '4', '4', '1.31', '5.24');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19203', '9522', '17', '2', '3.58', '7.16');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19204', '9523', '1', '1', '0.71', '0.71');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19205', '9524', '8', '2', '2.40', '4.80');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19206', '9525', '20', '3', '1.94', '5.82');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19207', '9526', '6', '4', '3.14', '12.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19208', '9527', '12', '1', '4.28', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19209', '9528', '6', '2', '2.85', '5.70');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19210', '9528', '20', '4', '1.94', '7.76');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19211', '9529', '12', '3', '4.73', '14.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19212', '9529', '6', '1', '3.14', '3.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19213', '9530', '2', '4', '1.49', '5.96');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19214', '9530', '12', '2', '4.28', '8.56');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19215', '9531', '9', '1', '2.14', '2.14');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19216', '9531', '2', '3', '1.35', '4.05');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19217', '9532', '24', '2', '4.75', '9.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19218', '9532', '9', '4', '2.36', '9.44');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19219', '9533', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19220', '9533', '2', '1', '1.49', '1.49');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19221', '9533', '12', '3', '4.28', '12.84');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19222', '9534', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19223', '9534', '9', '2', '2.14', '4.28');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19224', '9534', '2', '4', '1.35', '5.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19225', '9535', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19226', '9535', '24', '3', '4.75', '14.25');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19227', '9535', '9', '1', '2.36', '2.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19228', '9536', '17', '2', '3.25', '6.50');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19229', '9536', '7', '4', '1.84', '7.36');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19230', '9536', '24', '2', '5.23', '10.46');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19231', '9537', '17', '4', '3.58', '14.32');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19232', '9538', '4', '1', '1.19', '1.19');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19233', '9539', '11', '2', '3.20', '6.40');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19234', '9540', '1', '3', '0.79', '2.37');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19235', '9541', '9', '3', '2.36', '7.08');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19236', '9542', '24', '4', '5.23', '20.92');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19237', '9543', '7', '1', '1.66', '1.66');
INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES ('19238', '9544', '17', '2', '3.25', '6.50');

-- Table sale_reversal_items
DROP TABLE IF EXISTS `sale_reversal_items`;
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

-- Table sale_reversals
DROP TABLE IF EXISTS `sale_reversals`;
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

-- Table sales
DROP TABLE IF EXISTS `sales`;
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
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9299', '9', '3000', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-06-20 08:00:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9300', '9', '3000', '4.80', '4.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00002', '2026-06-20 10:17:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9301', '9', '3000', '5.82', '5.82', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00003', '2026-06-20 12:34:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9302', '9', '3000', '12.56', '12.56', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.44', NULL, '2026-06-20 14:51:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9303', '9', '3000', '4.28', '4.28', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00005', '2026-06-20 16:08:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9304', '9', '3001', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00006', '2026-06-21 09:11:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9305', '9', '3001', '17.33', '17.33', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00007', '2026-06-21 11:28:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9306', '9', '3001', '14.52', '14.52', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.48', NULL, '2026-06-21 13:45:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9307', '9', '3001', '6.19', '6.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00009', '2026-06-21 15:02:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9308', '9', '3001', '18.94', '18.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00010', '2026-06-21 17:19:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9309', '9', '3002', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00011', '2026-06-22 10:22:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9310', '9', '3002', '30.60', '30.60', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '40.00', '9.40', NULL, '2026-06-22 12:39:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9311', '9', '3002', '18.27', '18.27', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00013', '2026-06-22 14:56:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9312', '9', '3002', '24.32', '24.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00014', '2026-06-22 16:13:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9313', '9', '3003', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-06-23 11:33:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9314', '9', '3003', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00016', '2026-06-23 13:50:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9315', '9', '3003', '6.40', '6.40', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00017', '2026-06-23 15:07:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9316', '9', '3003', '2.37', '2.37', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '7.63', NULL, '2026-06-23 17:24:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9317', '9', '3004', '18.74', '18.74', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00019', '2026-06-25 13:55:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9318', '9', '3004', '22.61', '22.61', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '30.00', '7.39', NULL, '2026-06-25 15:12:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9319', '9', '3004', '25.92', '25.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00021', '2026-06-25 17:29:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9320', '9', '3004', '10.92', '10.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00022', '2026-06-25 19:46:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9321', '9', '3005', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-06-26 14:06:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9322', '9', '3005', '20.92', '20.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00024', '2026-06-26 16:23:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9323', '9', '3005', '1.66', '1.66', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00025', '2026-06-26 18:40:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9324', '9', '3005', '6.50', '6.50', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '3.50', NULL, '2026-06-26 20:57:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9325', '9', '3006', '17.64', '17.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00027', '2026-06-27 15:17:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9326', '9', '3006', '10.94', '10.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00028', '2026-06-27 17:34:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9327', '9', '3006', '11.64', '11.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '8.36', NULL, '2026-06-27 19:51:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9328', '9', '3006', '5.89', '5.89', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00030', '2026-06-27 08:08:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9329', '9', '3006', '11.98', '11.98', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00031', '2026-06-27 10:25:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9330', '9', '3006', '8.96', '8.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '1.04', NULL, '2026-06-27 12:42:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9331', '9', '3007', '11.62', '11.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00033', '2026-06-28 16:28:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9332', '9', '3007', '15.00', '15.00', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.00', NULL, '2026-06-28 18:45:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9333', '9', '3007', '10.59', '10.59', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00035', '2026-06-28 20:02:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9334', '9', '3007', '25.68', '25.68', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00036', '2026-06-28 09:19:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9335', '9', '3007', '14.77', '14.77', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.23', NULL, '2026-06-28 11:36:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9336', '9', '3007', '27.90', '27.90', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00038', '2026-06-28 13:53:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9337', '9', '3008', '8.57', '8.57', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00039', '2026-06-30 18:50:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9338', '9', '3009', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00040', '2026-07-01 19:01:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9339', '9', '3010', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-07-02 20:12:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9340', '9', '3011', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00042', '2026-07-03 08:23:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9341', '9', '3012', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00043', '2026-07-04 09:34:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9342', '9', '3012', '30.60', '30.60', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '40.00', '9.40', NULL, '2026-07-04 11:51:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9343', '9', '3012', '18.27', '18.27', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00045', '2026-07-04 13:08:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9344', '9', '3013', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-07-05 10:45:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9345', '9', '3013', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00047', '2026-07-05 12:02:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9346', '9', '3013', '6.40', '6.40', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00048', '2026-07-05 14:19:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9347', '9', '3014', '10.31', '10.31', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00049', '2026-07-06 11:56:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9348', '9', '3014', '7.96', '7.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00050', '2026-07-06 13:13:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9349', '9', '3015', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-07-08 13:18:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9350', '9', '3015', '20.92', '20.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00052', '2026-07-08 15:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9351', '9', '3016', '17.64', '17.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00053', '2026-07-09 14:29:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9352', '9', '3016', '10.94', '10.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00054', '2026-07-09 16:46:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9353', '9', '3017', '11.62', '11.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00055', '2026-07-10 15:40:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9354', '9', '3017', '15.00', '15.00', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.00', NULL, '2026-07-10 17:57:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9355', '9', '3018', '5.70', '5.70', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '4.30', NULL, '2026-07-11 16:51:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9356', '9', '3018', '14.19', '14.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00058', '2026-07-11 18:08:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9357', '9', '3018', '5.96', '5.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00059', '2026-07-11 20:25:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9358', '9', '3018', '2.14', '2.14', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '7.86', NULL, '2026-07-11 09:42:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9359', '9', '3019', '8.57', '8.57', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00061', '2026-07-12 17:02:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9360', '9', '3019', '25.20', '25.20', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00062', '2026-07-12 19:19:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9361', '9', '3019', '15.91', '15.91', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '4.09', NULL, '2026-07-12 08:36:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9362', '9', '3019', '13.86', '13.86', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00064', '2026-07-12 10:53:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9363', '9', '3020', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00065', '2026-07-13 18:13:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9364', '9', '3020', '12.78', '12.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.22', NULL, '2026-07-13 20:30:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9365', '9', '3020', '18.80', '18.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00067', '2026-07-13 09:47:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9366', '9', '3021', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-07-14 19:24:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9367', '9', '3021', '4.80', '4.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00069', '2026-07-14 08:41:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9368', '9', '3021', '5.82', '5.82', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00070', '2026-07-14 10:58:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9369', '9', '3022', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00071', '2026-07-15 20:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9370', '9', '3022', '17.33', '17.33', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00072', '2026-07-15 09:52:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9371', '9', '3022', '14.52', '14.52', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.48', NULL, '2026-07-15 11:09:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9372', '9', '3023', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-07-17 09:57:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9373', '9', '3023', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00075', '2026-07-17 11:14:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9374', '9', '3023', '6.40', '6.40', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00076', '2026-07-17 13:31:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9375', '9', '3024', '10.31', '10.31', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00077', '2026-07-18 10:08:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9376', '9', '3024', '7.96', '7.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00078', '2026-07-18 12:25:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9377', '9', '3024', '8.46', '8.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '1.54', NULL, '2026-07-18 14:42:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9378', '9', '3024', '16.08', '16.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00080', '2026-07-18 16:59:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9379', '9', '3024', '12.83', '12.83', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00081', '2026-07-18 18:16:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9380', '9', '3025', '18.74', '18.74', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00082', '2026-07-19 11:19:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9381', '9', '3025', '22.61', '22.61', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '30.00', '7.39', NULL, '2026-07-19 13:36:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9382', '9', '3025', '25.92', '25.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00084', '2026-07-19 15:53:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9383', '9', '3025', '10.92', '10.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00085', '2026-07-19 17:10:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9384', '9', '3025', '21.92', '21.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '30.00', '8.08', NULL, '2026-07-19 19:27:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9385', '9', '3026', '17.64', '17.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00087', '2026-07-21 13:41:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9386', '9', '3026', '10.94', '10.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00088', '2026-07-21 15:58:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9387', '9', '3026', '11.64', '11.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '8.36', NULL, '2026-07-21 17:15:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9388', '9', '3026', '5.89', '5.89', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00090', '2026-07-21 19:32:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9389', '9', '3027', '11.62', '11.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00091', '2026-07-22 14:52:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9390', '9', '3027', '15.00', '15.00', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.00', NULL, '2026-07-22 16:09:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9391', '9', '3027', '10.59', '10.59', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00093', '2026-07-22 18:26:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9392', '9', '3027', '25.68', '25.68', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00094', '2026-07-22 20:43:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9393', '9', '3028', '5.70', '5.70', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '4.30', NULL, '2026-07-23 15:03:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9394', '9', '3028', '14.19', '14.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00096', '2026-07-23 17:20:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9395', '9', '3028', '5.96', '5.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00097', '2026-07-23 19:37:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9396', '9', '3028', '2.14', '2.14', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '7.86', NULL, '2026-07-23 08:54:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9397', '9', '3029', '8.57', '8.57', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00099', '2026-07-24 16:14:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9398', '9', '3029', '25.20', '25.20', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00100', '2026-07-24 18:31:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9399', '9', '3029', '15.91', '15.91', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '4.09', NULL, '2026-07-24 20:48:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9400', '9', '3029', '13.86', '13.86', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00102', '2026-07-24 09:05:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9401', '9', '3030', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00103', '2026-07-25 17:25:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9402', '9', '3030', '12.78', '12.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.22', NULL, '2026-07-25 19:42:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9403', '9', '3030', '18.80', '18.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00105', '2026-07-25 08:59:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9404', '9', '3030', '9.46', '9.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00106', '2026-07-25 10:16:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9405', '9', '3030', '24.78', '24.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '30.00', '5.22', NULL, '2026-07-25 12:33:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9406', '9', '3030', '9.75', '9.75', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00108', '2026-07-25 14:50:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9407', '9', '3031', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-07-26 18:36:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9408', '9', '3031', '4.80', '4.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00110', '2026-07-26 20:53:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9409', '9', '3031', '5.82', '5.82', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00111', '2026-07-26 09:10:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9410', '9', '3031', '12.56', '12.56', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.44', NULL, '2026-07-26 11:27:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9411', '9', '3031', '4.28', '4.28', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00113', '2026-07-26 13:44:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9412', '9', '3031', '2.70', '2.70', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00114', '2026-07-26 15:01:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9413', '9', '3032', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00115', '2026-07-27 19:47:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9414', '9', '3033', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00116', '2026-07-28 20:58:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9415', '9', '3034', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-07-29 08:09:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9416', '9', '3035', '10.31', '10.31', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00118', '2026-07-30 09:20:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9417', '9', '3036', '18.74', '18.74', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00119', '2026-07-31 10:31:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9418', '9', '3037', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-08-01 11:42:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9419', '9', '3037', '20.92', '20.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00121', '2026-08-01 13:59:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9420', '9', '3037', '1.66', '1.66', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00122', '2026-08-01 15:16:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9421', '9', '3038', '11.62', '11.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00123', '2026-08-03 13:04:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9422', '9', '3038', '15.00', '15.00', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.00', NULL, '2026-08-03 15:21:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9423', '9', '3039', '5.70', '5.70', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '4.30', NULL, '2026-08-04 14:15:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9424', '9', '3039', '14.19', '14.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00126', '2026-08-04 16:32:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9425', '9', '3040', '8.57', '8.57', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00127', '2026-08-05 15:26:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9426', '9', '3040', '25.20', '25.20', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00128', '2026-08-05 17:43:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9427', '9', '3041', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00129', '2026-08-06 16:37:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9428', '9', '3041', '12.78', '12.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.22', NULL, '2026-08-06 18:54:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9429', '9', '3042', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-08-07 17:48:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9430', '9', '3042', '4.80', '4.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00132', '2026-08-07 19:05:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9431', '9', '3043', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00133', '2026-08-08 18:59:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9432', '9', '3043', '17.33', '17.33', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00134', '2026-08-08 20:16:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9433', '9', '3043', '14.52', '14.52', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.48', NULL, '2026-08-08 09:33:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9434', '9', '3043', '6.19', '6.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00136', '2026-08-08 11:50:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9435', '9', '3044', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00137', '2026-08-09 19:10:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9436', '9', '3044', '30.60', '30.60', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '40.00', '9.40', NULL, '2026-08-09 08:27:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9437', '9', '3044', '18.27', '18.27', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00139', '2026-08-09 10:44:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9438', '9', '3044', '24.32', '24.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00140', '2026-08-09 12:01:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9439', '9', '3045', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-08-10 20:21:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9440', '9', '3045', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00142', '2026-08-10 09:38:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9441', '9', '3045', '6.40', '6.40', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00143', '2026-08-10 11:55:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9442', '9', '3046', '10.31', '10.31', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00144', '2026-08-11 08:32:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9443', '9', '3046', '7.96', '7.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00145', '2026-08-11 10:49:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9444', '9', '3046', '8.46', '8.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '1.54', NULL, '2026-08-11 12:06:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9445', '9', '3047', '18.74', '18.74', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00147', '2026-08-12 09:43:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9446', '9', '3047', '22.61', '22.61', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '30.00', '7.39', NULL, '2026-08-12 11:00:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9447', '9', '3047', '25.92', '25.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00149', '2026-08-12 13:17:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9448', '9', '3048', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-08-13 10:54:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9449', '9', '3048', '20.92', '20.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00151', '2026-08-13 12:11:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9450', '9', '3048', '1.66', '1.66', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00152', '2026-08-13 14:28:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9451', '9', '3049', '17.64', '17.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00153', '2026-08-14 11:05:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9452', '9', '3049', '10.94', '10.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00154', '2026-08-14 13:22:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9453', '9', '3049', '11.64', '11.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '8.36', NULL, '2026-08-14 15:39:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9454', '9', '3050', '5.70', '5.70', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '4.30', NULL, '2026-08-16 13:27:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9455', '9', '3050', '14.19', '14.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00157', '2026-08-16 15:44:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9456', '9', '3050', '5.96', '5.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00158', '2026-08-16 17:01:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9457', '9', '3050', '2.14', '2.14', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '7.86', NULL, '2026-08-16 19:18:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9458', '9', '3050', '9.50', '9.50', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00160', '2026-08-16 08:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9459', '9', '3051', '8.57', '8.57', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00161', '2026-08-17 14:38:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9460', '9', '3051', '25.20', '25.20', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00162', '2026-08-17 16:55:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9461', '9', '3051', '15.91', '15.91', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '4.09', NULL, '2026-08-17 18:12:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9462', '9', '3051', '13.86', '13.86', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00164', '2026-08-17 20:29:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9463', '9', '3052', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00165', '2026-08-18 15:49:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9464', '9', '3052', '12.78', '12.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.22', NULL, '2026-08-18 17:06:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9465', '9', '3052', '18.80', '18.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00167', '2026-08-18 19:23:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9466', '9', '3052', '9.46', '9.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00168', '2026-08-18 08:40:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9467', '9', '3053', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00169', '2026-08-20 17:11:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9468', '9', '3053', '17.33', '17.33', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00170', '2026-08-20 19:28:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9469', '9', '3053', '14.52', '14.52', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.48', NULL, '2026-08-20 08:45:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9470', '9', '3053', '6.19', '6.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00172', '2026-08-20 10:02:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9471', '9', '3054', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00173', '2026-08-21 18:22:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9472', '9', '3054', '30.60', '30.60', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '40.00', '9.40', NULL, '2026-08-21 20:39:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9473', '9', '3054', '18.27', '18.27', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00175', '2026-08-21 09:56:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9474', '9', '3054', '24.32', '24.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00176', '2026-08-21 11:13:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9475', '9', '3055', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-08-22 19:33:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9476', '9', '3055', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00178', '2026-08-22 08:50:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9477', '9', '3055', '6.40', '6.40', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00179', '2026-08-22 10:07:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9478', '9', '3055', '2.37', '2.37', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '7.63', NULL, '2026-08-22 12:24:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9479', '9', '3055', '10.56', '10.56', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00181', '2026-08-22 14:41:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9480', '9', '3055', '1.76', '1.76', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00182', '2026-08-22 16:58:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9481', '9', '3056', '10.31', '10.31', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00183', '2026-08-23 20:44:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9482', '9', '3056', '7.96', '7.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00184', '2026-08-23 09:01:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9483', '9', '3056', '8.46', '8.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '1.54', NULL, '2026-08-23 11:18:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9484', '9', '3056', '16.08', '16.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00186', '2026-08-23 13:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9485', '9', '3056', '12.83', '12.83', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00187', '2026-08-23 15:52:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9486', '9', '3056', '21.62', '21.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '30.00', '8.38', NULL, '2026-08-23 17:09:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9487', '9', '3057', '18.74', '18.74', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00189', '2026-08-24 08:55:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9488', '9', '3058', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-08-25 09:06:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9489', '9', '3059', '17.64', '17.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00191', '2026-08-26 10:17:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9490', '9', '3060', '11.62', '11.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00192', '2026-08-27 11:28:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9491', '9', '3061', '8.57', '8.57', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00193', '2026-08-29 13:50:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9492', '9', '3061', '25.20', '25.20', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00194', '2026-08-29 15:07:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9493', '9', '3061', '15.91', '15.91', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '4.09', NULL, '2026-08-29 17:24:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9494', '9', '3062', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00196', '2026-08-30 14:01:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9495', '9', '3062', '12.78', '12.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.22', NULL, '2026-08-30 16:18:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9496', '9', '3062', '18.80', '18.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00198', '2026-08-30 18:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9497', '9', '3063', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-08-31 15:12:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9498', '9', '3063', '4.80', '4.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00200', '2026-08-31 17:29:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9499', '9', '3064', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00201', '2026-09-01 16:23:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9500', '9', '3064', '17.33', '17.33', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00202', '2026-09-01 18:40:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9501', '9', '3065', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00203', '2026-09-02 17:34:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9502', '9', '3065', '30.60', '30.60', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '40.00', '9.40', NULL, '2026-09-02 19:51:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9503', '9', '3066', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-09-03 18:45:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9504', '9', '3066', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00206', '2026-09-03 20:02:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9505', '9', '3067', '10.31', '10.31', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00207', '2026-09-04 19:56:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9506', '9', '3067', '7.96', '7.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00208', '2026-09-04 08:13:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9507', '9', '3068', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-09-06 08:18:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9508', '9', '3068', '20.92', '20.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00210', '2026-09-06 10:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9509', '9', '3068', '1.66', '1.66', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00211', '2026-09-06 12:52:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9510', '9', '3068', '6.50', '6.50', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '3.50', NULL, '2026-09-06 14:09:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9511', '9', '3069', '17.64', '17.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00213', '2026-09-07 09:29:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9512', '9', '3069', '10.94', '10.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00214', '2026-09-07 11:46:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9513', '9', '3069', '11.64', '11.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '8.36', NULL, '2026-09-07 13:03:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9514', '9', '3070', '11.62', '11.62', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00216', '2026-09-08 10:40:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9515', '9', '3070', '15.00', '15.00', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.00', NULL, '2026-09-08 12:57:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9516', '9', '3070', '10.59', '10.59', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00218', '2026-09-08 14:14:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9517', '9', '3071', '5.70', '5.70', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '4.30', NULL, '2026-09-09 11:51:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9518', '9', '3071', '14.19', '14.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00220', '2026-09-09 13:08:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9519', '9', '3071', '5.96', '5.96', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00221', '2026-09-09 15:25:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9520', '9', '3072', '36.64', '36.64', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00222', '2026-09-11 13:13:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9521', '9', '3072', '12.78', '12.78', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.22', NULL, '2026-09-11 15:30:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9522', '9', '3072', '18.80', '18.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00224', '2026-09-11 17:47:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9523', '9', '3073', '0.71', '0.71', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '9.29', NULL, '2026-09-12 14:24:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9524', '9', '3073', '4.80', '4.80', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00226', '2026-09-12 16:41:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9525', '9', '3073', '5.82', '5.82', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00227', '2026-09-12 18:58:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9526', '9', '3073', '12.56', '12.56', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '7.44', NULL, '2026-09-12 20:15:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9527', '9', '3073', '4.28', '4.28', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00229', '2026-09-12 09:32:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9528', '9', '3074', '13.46', '13.46', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00230', '2026-09-13 15:35:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9529', '9', '3074', '17.33', '17.33', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00231', '2026-09-13 17:52:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9530', '9', '3074', '14.52', '14.52', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.48', NULL, '2026-09-13 19:09:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9531', '9', '3074', '6.19', '6.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00233', '2026-09-13 08:26:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9532', '9', '3074', '18.94', '18.94', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00234', '2026-09-13 10:43:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9533', '9', '3075', '21.41', '21.41', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00235', '2026-09-14 16:46:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9534', '9', '3075', '30.60', '30.60', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '40.00', '9.40', NULL, '2026-09-14 18:03:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9535', '9', '3075', '18.27', '18.27', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00237', '2026-09-14 20:20:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9536', '9', '3075', '24.32', '24.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00238', '2026-09-14 09:37:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9537', '9', '3076', '14.32', '14.32', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '20.00', '5.68', NULL, '2026-09-15 17:57:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9538', '9', '3076', '1.19', '1.19', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00240', '2026-09-15 19:14:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9539', '9', '3076', '6.40', '6.40', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00241', '2026-09-15 08:31:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9540', '9', '3076', '2.37', '2.37', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '7.63', NULL, '2026-09-15 10:48:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9541', '9', '3077', '7.08', '7.08', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '2.92', NULL, '2026-09-18 20:30:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9542', '9', '3077', '20.92', '20.92', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'card', NULL, NULL, 'RM_SEED_SALES_TREND_V1-CARD-00244', '2026-09-18 09:47:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9543', '9', '3077', '1.66', '1.66', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'ewallet', NULL, NULL, 'RM_SEED_SALES_TREND_V1-EWALLET-00245', '2026-09-18 11:04:00');
INSERT INTO `sales` (`sale_id`, `cashier_id`, `shift_id`, `total_amount`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `discount_reason`, `discount_authorized_by`, `promotion_id`, `promotion_name`, `payment_method`, `cash_received`, `change_due`, `payment_reference`, `sale_date`) VALUES ('9544', '9', '3077', '6.50', '6.50', 'none', '0.00', '0.00', NULL, NULL, NULL, NULL, 'cash', '10.00', '3.50', NULL, '2026-09-18 13:21:00');

-- Table schema_migrations
DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
  `migration_key` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('2026_07_operational_updates', 'Suppliers, purchase orders, shifts, held sales, forecast decisions, units, and inventory insights', '2026-09-14 15:29:34');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202608040001_auth_security', 'Authentication security tables and mandatory password-change support', '2026-09-17 07:47:10');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202608040002_operational_updates', 'Operational workflow tables and columns', '2026-09-17 07:47:10');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202608040003_integrity_constraints', 'Foreign-key protections for upgraded operational databases', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609130001_branch_management', 'Branches, branch-scoped users and privilege assignments', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609130002_role_access_update', 'Make administrator inventory access read-only', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609130003_superadmin_inventory_read_only', 'Make super administrator inventory access read-only', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609130004_remove_seller_role', 'Replace the duplicate seller role with cashier', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609140001_branch_scoped_product_identifiers', 'Allow product identifiers to be reused across branches', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609170001_admin_profile_images', 'Nullable generated profile image filename for administrator accounts', '2026-09-17 07:47:11');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609170001_profile_images', 'Optional generated profile image filename for user accounts', '2026-09-17 09:06:29');
INSERT INTO `schema_migrations` (`migration_key`, `description`, `applied_at`) VALUES ('202609170001_staff_profile_images', 'Optional profile image filename for staff accounts', '2026-09-17 07:59:31');

-- Table stock_movements
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
  `movement_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `change_qty` int(11) NOT NULL,
  `reason` enum('purchase','sale','adjustment','return') NOT NULL,
  `moved_by` int(11) DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `idx_stock_movements_product_id` (`product_id`),
  KEY `idx_stock_movements_moved_at` (`moved_at`),
  KEY `moved_by` (`moved_by`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`moved_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19723 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('1', '1', '12', 'purchase', NULL, '2026-09-17 07:38:57');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('2', '2', '30', 'purchase', NULL, '2026-09-17 07:38:57');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('3', '3', '90', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('4', '4', '168', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('5', '6', '6', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('6', '7', '30', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('7', '8', '54', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('8', '9', '88', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('9', '11', '5', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('10', '12', '14', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('11', '13', '96', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('12', '14', '46', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('13', '16', '5', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('14', '17', '24', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('15', '18', '46', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('16', '19', '82', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('17', '21', '7', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('18', '22', '42', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19', '23', '48', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('20', '24', '44', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('21', '26', '4', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('22', '27', '24', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('23', '28', '48', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('24', '29', '44', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('25', '31', '4', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('26', '32', '14', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('27', '33', '40', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('28', '34', '70', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('29', '36', '4', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('30', '37', '14', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('31', '38', '28', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('32', '39', '44', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('33', '41', '4', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('34', '42', '14', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('35', '43', '26', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('36', '44', '42', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('37', '46', '3', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('38', '47', '14', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('39', '48', '18', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('40', '49', '82', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('41', '51', '4', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('42', '52', '36', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('43', '53', '26', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('44', '54', '84', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('45', '56', '4', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('46', '57', '14', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('47', '58', '18', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('48', '59', '30', 'purchase', NULL, '2026-09-17 07:38:58');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19232', '1', '194', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19233', '2', '214', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19234', '4', '172', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19235', '6', '205', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19236', '7', '186', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19237', '8', '189', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19238', '9', '226', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19239', '11', '205', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19240', '12', '219', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19241', '17', '274', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19242', '20', '233', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19243', '24', '272', 'purchase', '11', '2026-06-19 07:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19244', '1', '-1', 'sale', '9', '2026-06-20 08:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19245', '8', '-2', 'sale', '9', '2026-06-20 10:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19246', '20', '-3', 'sale', '9', '2026-06-20 12:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19247', '6', '-4', 'sale', '9', '2026-06-20 14:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19248', '12', '-1', 'sale', '9', '2026-06-20 16:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19249', '6', '-2', 'sale', '9', '2026-06-21 09:11:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19250', '20', '-4', 'sale', '9', '2026-06-21 09:11:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19251', '12', '-3', 'sale', '9', '2026-06-21 11:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19252', '6', '-1', 'sale', '9', '2026-06-21 11:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19253', '2', '-4', 'sale', '9', '2026-06-21 13:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19254', '12', '-2', 'sale', '9', '2026-06-21 13:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19255', '9', '-1', 'sale', '9', '2026-06-21 15:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19256', '2', '-3', 'sale', '9', '2026-06-21 15:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19257', '24', '-2', 'sale', '9', '2026-06-21 17:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19258', '9', '-4', 'sale', '9', '2026-06-21 17:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19259', '9', '-3', 'sale', '9', '2026-06-22 10:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19260', '2', '-1', 'sale', '9', '2026-06-22 10:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19261', '12', '-3', 'sale', '9', '2026-06-22 10:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19262', '24', '-4', 'sale', '9', '2026-06-22 12:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19263', '9', '-2', 'sale', '9', '2026-06-22 12:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19264', '2', '-4', 'sale', '9', '2026-06-22 12:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19265', '7', '-1', 'sale', '9', '2026-06-22 14:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19266', '24', '-3', 'sale', '9', '2026-06-22 14:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19267', '9', '-1', 'sale', '9', '2026-06-22 14:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19268', '17', '-2', 'sale', '9', '2026-06-22 16:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19269', '7', '-4', 'sale', '9', '2026-06-22 16:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19270', '24', '-2', 'sale', '9', '2026-06-22 16:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19271', '17', '-4', 'sale', '9', '2026-06-23 11:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19272', '4', '-1', 'sale', '9', '2026-06-23 13:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19273', '11', '-2', 'sale', '9', '2026-06-23 15:07:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19274', '1', '-3', 'sale', '9', '2026-06-23 17:24:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19275', '6', '-2', 'sale', '9', '2026-06-25 13:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19276', '20', '-4', 'sale', '9', '2026-06-25 13:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19277', '8', '-2', 'sale', '9', '2026-06-25 13:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19278', '12', '-3', 'sale', '9', '2026-06-25 15:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19279', '6', '-1', 'sale', '9', '2026-06-25 15:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19280', '20', '-3', 'sale', '9', '2026-06-25 15:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19281', '2', '-4', 'sale', '9', '2026-06-25 17:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19282', '12', '-2', 'sale', '9', '2026-06-25 17:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19283', '6', '-4', 'sale', '9', '2026-06-25 17:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19284', '9', '-1', 'sale', '9', '2026-06-25 19:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19285', '2', '-3', 'sale', '9', '2026-06-25 19:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19286', '12', '-1', 'sale', '9', '2026-06-25 19:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19287', '9', '-3', 'sale', '9', '2026-06-26 14:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19288', '24', '-4', 'sale', '9', '2026-06-26 16:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19289', '7', '-1', 'sale', '9', '2026-06-26 18:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19290', '17', '-2', 'sale', '9', '2026-06-26 20:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19291', '17', '-4', 'sale', '9', '2026-06-27 15:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19292', '7', '-2', 'sale', '9', '2026-06-27 15:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19293', '4', '-1', 'sale', '9', '2026-06-27 17:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19294', '17', '-3', 'sale', '9', '2026-06-27 17:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19295', '11', '-2', 'sale', '9', '2026-06-27 19:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19296', '4', '-4', 'sale', '9', '2026-06-27 19:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19297', '1', '-3', 'sale', '9', '2026-06-27 08:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19298', '11', '-1', 'sale', '9', '2026-06-27 08:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19299', '8', '-4', 'sale', '9', '2026-06-27 10:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19300', '1', '-2', 'sale', '9', '2026-06-27 10:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19301', '20', '-1', 'sale', '9', '2026-06-27 12:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19302', '8', '-3', 'sale', '9', '2026-06-27 12:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19303', '1', '-1', 'sale', '9', '2026-06-28 16:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19304', '11', '-3', 'sale', '9', '2026-06-28 16:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19305', '4', '-1', 'sale', '9', '2026-06-28 16:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19306', '8', '-2', 'sale', '9', '2026-06-28 18:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19307', '1', '-4', 'sale', '9', '2026-06-28 18:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19308', '11', '-2', 'sale', '9', '2026-06-28 18:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19309', '20', '-3', 'sale', '9', '2026-06-28 20:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19310', '8', '-1', 'sale', '9', '2026-06-28 20:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19311', '1', '-3', 'sale', '9', '2026-06-28 20:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19312', '6', '-4', 'sale', '9', '2026-06-28 09:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19313', '20', '-2', 'sale', '9', '2026-06-28 09:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19314', '8', '-4', 'sale', '9', '2026-06-28 09:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19315', '12', '-1', 'sale', '9', '2026-06-28 11:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19316', '6', '-3', 'sale', '9', '2026-06-28 11:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19317', '20', '-1', 'sale', '9', '2026-06-28 11:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19318', '2', '-2', 'sale', '9', '2026-06-28 13:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19319', '12', '-4', 'sale', '9', '2026-06-28 13:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19320', '6', '-2', 'sale', '9', '2026-06-28 13:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19321', '9', '-3', 'sale', '9', '2026-06-30 18:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19322', '2', '-1', 'sale', '9', '2026-06-30 18:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19323', '17', '-4', 'sale', '9', '2026-07-01 19:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19324', '7', '-2', 'sale', '9', '2026-07-01 19:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19325', '24', '-4', 'sale', '9', '2026-07-01 19:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19326', '1', '-1', 'sale', '9', '2026-07-02 20:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19327', '6', '-2', 'sale', '9', '2026-07-03 08:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19328', '20', '-4', 'sale', '9', '2026-07-03 08:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19329', '9', '-3', 'sale', '9', '2026-07-04 09:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19330', '2', '-1', 'sale', '9', '2026-07-04 09:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19331', '12', '-3', 'sale', '9', '2026-07-04 09:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19332', '24', '-4', 'sale', '9', '2026-07-04 11:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19333', '9', '-2', 'sale', '9', '2026-07-04 11:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19334', '2', '-4', 'sale', '9', '2026-07-04 11:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19335', '7', '-1', 'sale', '9', '2026-07-04 13:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19336', '24', '-3', 'sale', '9', '2026-07-04 13:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19337', '9', '-1', 'sale', '9', '2026-07-04 13:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19338', '17', '-4', 'sale', '9', '2026-07-05 10:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19339', '4', '-1', 'sale', '9', '2026-07-05 12:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19340', '11', '-2', 'sale', '9', '2026-07-05 14:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19341', '1', '-1', 'sale', '9', '2026-07-06 11:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19342', '11', '-3', 'sale', '9', '2026-07-06 11:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19343', '8', '-2', 'sale', '9', '2026-07-06 13:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19344', '1', '-4', 'sale', '9', '2026-07-06 13:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19345', '9', '-3', 'sale', '9', '2026-07-08 13:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19346', '24', '-4', 'sale', '9', '2026-07-08 15:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19347', '17', '-4', 'sale', '9', '2026-07-09 14:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19348', '7', '-2', 'sale', '9', '2026-07-09 14:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19349', '4', '-1', 'sale', '9', '2026-07-09 16:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19350', '17', '-3', 'sale', '9', '2026-07-09 16:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19351', '1', '-1', 'sale', '9', '2026-07-10 15:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19352', '11', '-3', 'sale', '9', '2026-07-10 15:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19353', '4', '-1', 'sale', '9', '2026-07-10 15:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19354', '8', '-2', 'sale', '9', '2026-07-10 17:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19355', '1', '-4', 'sale', '9', '2026-07-10 17:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19356', '11', '-2', 'sale', '9', '2026-07-10 17:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19357', '6', '-2', 'sale', '9', '2026-07-11 16:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19358', '12', '-3', 'sale', '9', '2026-07-11 18:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19359', '2', '-4', 'sale', '9', '2026-07-11 20:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19360', '9', '-1', 'sale', '9', '2026-07-11 09:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19361', '9', '-3', 'sale', '9', '2026-07-12 17:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19362', '2', '-1', 'sale', '9', '2026-07-12 17:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19363', '24', '-4', 'sale', '9', '2026-07-12 19:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19364', '9', '-2', 'sale', '9', '2026-07-12 19:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19365', '7', '-1', 'sale', '9', '2026-07-12 08:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19366', '24', '-3', 'sale', '9', '2026-07-12 08:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19367', '17', '-2', 'sale', '9', '2026-07-12 10:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19368', '7', '-4', 'sale', '9', '2026-07-12 10:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19369', '17', '-4', 'sale', '9', '2026-07-13 18:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19370', '7', '-2', 'sale', '9', '2026-07-13 18:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19371', '24', '-4', 'sale', '9', '2026-07-13 18:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19372', '4', '-1', 'sale', '9', '2026-07-13 20:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19373', '17', '-3', 'sale', '9', '2026-07-13 20:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19374', '7', '-1', 'sale', '9', '2026-07-13 20:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19375', '11', '-2', 'sale', '9', '2026-07-13 09:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19376', '4', '-4', 'sale', '9', '2026-07-13 09:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19377', '17', '-2', 'sale', '9', '2026-07-13 09:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19378', '1', '-1', 'sale', '9', '2026-07-14 19:24:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19379', '8', '-2', 'sale', '9', '2026-07-14 08:41:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19380', '20', '-3', 'sale', '9', '2026-07-14 10:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19381', '6', '-2', 'sale', '9', '2026-07-15 20:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19382', '20', '-4', 'sale', '9', '2026-07-15 20:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19383', '12', '-3', 'sale', '9', '2026-07-15 09:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19384', '6', '-1', 'sale', '9', '2026-07-15 09:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19385', '2', '-4', 'sale', '9', '2026-07-15 11:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19386', '12', '-2', 'sale', '9', '2026-07-15 11:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19387', '17', '-4', 'sale', '9', '2026-07-17 09:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19388', '4', '-1', 'sale', '9', '2026-07-17 11:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19389', '11', '-2', 'sale', '9', '2026-07-17 13:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19390', '1', '-1', 'sale', '9', '2026-07-18 10:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19391', '11', '-3', 'sale', '9', '2026-07-18 10:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19392', '8', '-2', 'sale', '9', '2026-07-18 12:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19393', '1', '-4', 'sale', '9', '2026-07-18 12:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19394', '20', '-3', 'sale', '9', '2026-07-18 14:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19395', '8', '-1', 'sale', '9', '2026-07-18 14:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19396', '6', '-4', 'sale', '9', '2026-07-18 16:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19397', '20', '-2', 'sale', '9', '2026-07-18 16:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19398', '12', '-1', 'sale', '9', '2026-07-18 18:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19399', '6', '-3', 'sale', '9', '2026-07-18 18:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19400', '6', '-2', 'sale', '9', '2026-07-19 11:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19401', '20', '-4', 'sale', '9', '2026-07-19 11:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19402', '8', '-2', 'sale', '9', '2026-07-19 11:19:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19403', '12', '-3', 'sale', '9', '2026-07-19 13:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19404', '6', '-1', 'sale', '9', '2026-07-19 13:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19405', '20', '-3', 'sale', '9', '2026-07-19 13:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19406', '2', '-4', 'sale', '9', '2026-07-19 15:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19407', '12', '-2', 'sale', '9', '2026-07-19 15:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19408', '6', '-4', 'sale', '9', '2026-07-19 15:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19409', '9', '-1', 'sale', '9', '2026-07-19 17:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19410', '2', '-3', 'sale', '9', '2026-07-19 17:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19411', '12', '-1', 'sale', '9', '2026-07-19 17:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19412', '24', '-2', 'sale', '9', '2026-07-19 19:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19413', '9', '-4', 'sale', '9', '2026-07-19 19:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19414', '2', '-2', 'sale', '9', '2026-07-19 19:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19415', '17', '-4', 'sale', '9', '2026-07-21 13:41:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19416', '7', '-2', 'sale', '9', '2026-07-21 13:41:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19417', '4', '-1', 'sale', '9', '2026-07-21 15:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19418', '17', '-3', 'sale', '9', '2026-07-21 15:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19419', '11', '-2', 'sale', '9', '2026-07-21 17:15:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19420', '4', '-4', 'sale', '9', '2026-07-21 17:15:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19421', '1', '-3', 'sale', '9', '2026-07-21 19:32:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19422', '11', '-1', 'sale', '9', '2026-07-21 19:32:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19423', '1', '-1', 'sale', '9', '2026-07-22 14:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19424', '11', '-3', 'sale', '9', '2026-07-22 14:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19425', '4', '-1', 'sale', '9', '2026-07-22 14:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19426', '8', '-2', 'sale', '9', '2026-07-22 16:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19427', '1', '-4', 'sale', '9', '2026-07-22 16:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19428', '11', '-2', 'sale', '9', '2026-07-22 16:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19429', '20', '-3', 'sale', '9', '2026-07-22 18:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19430', '8', '-1', 'sale', '9', '2026-07-22 18:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19431', '1', '-3', 'sale', '9', '2026-07-22 18:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19432', '6', '-4', 'sale', '9', '2026-07-22 20:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19433', '20', '-2', 'sale', '9', '2026-07-22 20:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19434', '8', '-4', 'sale', '9', '2026-07-22 20:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19435', '6', '-2', 'sale', '9', '2026-07-23 15:03:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19436', '12', '-3', 'sale', '9', '2026-07-23 17:20:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19437', '2', '-4', 'sale', '9', '2026-07-23 19:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19438', '9', '-1', 'sale', '9', '2026-07-23 08:54:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19439', '9', '-3', 'sale', '9', '2026-07-24 16:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19440', '2', '-1', 'sale', '9', '2026-07-24 16:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19441', '24', '-4', 'sale', '9', '2026-07-24 18:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19442', '9', '-2', 'sale', '9', '2026-07-24 18:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19443', '7', '-1', 'sale', '9', '2026-07-24 20:48:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19444', '24', '-3', 'sale', '9', '2026-07-24 20:48:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19445', '17', '-2', 'sale', '9', '2026-07-24 09:05:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19446', '7', '-4', 'sale', '9', '2026-07-24 09:05:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19447', '17', '-4', 'sale', '9', '2026-07-25 17:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19448', '7', '-2', 'sale', '9', '2026-07-25 17:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19449', '24', '-4', 'sale', '9', '2026-07-25 17:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19450', '4', '-1', 'sale', '9', '2026-07-25 19:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19451', '17', '-3', 'sale', '9', '2026-07-25 19:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19452', '7', '-1', 'sale', '9', '2026-07-25 19:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19453', '11', '-2', 'sale', '9', '2026-07-25 08:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19454', '4', '-4', 'sale', '9', '2026-07-25 08:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19455', '17', '-2', 'sale', '9', '2026-07-25 08:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19456', '1', '-3', 'sale', '9', '2026-07-25 10:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19457', '11', '-1', 'sale', '9', '2026-07-25 10:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19458', '4', '-3', 'sale', '9', '2026-07-25 10:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19459', '8', '-4', 'sale', '9', '2026-07-25 12:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19460', '1', '-2', 'sale', '9', '2026-07-25 12:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19461', '11', '-4', 'sale', '9', '2026-07-25 12:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19462', '20', '-1', 'sale', '9', '2026-07-25 14:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19463', '8', '-3', 'sale', '9', '2026-07-25 14:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19464', '1', '-1', 'sale', '9', '2026-07-25 14:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19465', '1', '-1', 'sale', '9', '2026-07-26 18:36:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19466', '8', '-2', 'sale', '9', '2026-07-26 20:53:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19467', '20', '-3', 'sale', '9', '2026-07-26 09:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19468', '6', '-4', 'sale', '9', '2026-07-26 11:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19469', '12', '-1', 'sale', '9', '2026-07-26 13:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19470', '2', '-2', 'sale', '9', '2026-07-26 15:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19471', '6', '-2', 'sale', '9', '2026-07-27 19:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19472', '20', '-4', 'sale', '9', '2026-07-27 19:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19473', '9', '-3', 'sale', '9', '2026-07-28 20:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19474', '2', '-1', 'sale', '9', '2026-07-28 20:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19475', '12', '-3', 'sale', '9', '2026-07-28 20:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19476', '17', '-4', 'sale', '9', '2026-07-29 08:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19477', '1', '-1', 'sale', '9', '2026-07-30 09:20:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19478', '11', '-3', 'sale', '9', '2026-07-30 09:20:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19479', '6', '-2', 'sale', '9', '2026-07-31 10:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19480', '20', '-4', 'sale', '9', '2026-07-31 10:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19481', '8', '-2', 'sale', '9', '2026-07-31 10:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19482', '9', '-3', 'sale', '9', '2026-08-01 11:42:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19483', '24', '-4', 'sale', '9', '2026-08-01 13:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19484', '7', '-1', 'sale', '9', '2026-08-01 15:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19485', '1', '-1', 'sale', '9', '2026-08-03 13:04:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19486', '11', '-3', 'sale', '9', '2026-08-03 13:04:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19487', '4', '-1', 'sale', '9', '2026-08-03 13:04:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19488', '8', '-2', 'sale', '9', '2026-08-03 15:21:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19489', '1', '-4', 'sale', '9', '2026-08-03 15:21:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19490', '11', '-2', 'sale', '9', '2026-08-03 15:21:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19491', '6', '-2', 'sale', '9', '2026-08-04 14:15:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19492', '12', '-3', 'sale', '9', '2026-08-04 16:32:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19493', '9', '-3', 'sale', '9', '2026-08-05 15:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19494', '2', '-1', 'sale', '9', '2026-08-05 15:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19495', '24', '-4', 'sale', '9', '2026-08-05 17:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19496', '9', '-2', 'sale', '9', '2026-08-05 17:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19497', '17', '-4', 'sale', '9', '2026-08-06 16:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19498', '7', '-2', 'sale', '9', '2026-08-06 16:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19499', '24', '-4', 'sale', '9', '2026-08-06 16:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19500', '4', '-1', 'sale', '9', '2026-08-06 18:54:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19501', '17', '-3', 'sale', '9', '2026-08-06 18:54:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19502', '7', '-1', 'sale', '9', '2026-08-06 18:54:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19503', '1', '-1', 'sale', '9', '2026-08-07 17:48:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19504', '8', '-2', 'sale', '9', '2026-08-07 19:05:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19505', '6', '-2', 'sale', '9', '2026-08-08 18:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19506', '20', '-4', 'sale', '9', '2026-08-08 18:59:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19507', '12', '-3', 'sale', '9', '2026-08-08 20:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19508', '6', '-1', 'sale', '9', '2026-08-08 20:16:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19509', '2', '-4', 'sale', '9', '2026-08-08 09:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19510', '12', '-2', 'sale', '9', '2026-08-08 09:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19511', '9', '-1', 'sale', '9', '2026-08-08 11:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19512', '2', '-3', 'sale', '9', '2026-08-08 11:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19513', '9', '-3', 'sale', '9', '2026-08-09 19:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19514', '2', '-1', 'sale', '9', '2026-08-09 19:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19515', '12', '-3', 'sale', '9', '2026-08-09 19:10:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19516', '24', '-4', 'sale', '9', '2026-08-09 08:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19517', '9', '-2', 'sale', '9', '2026-08-09 08:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19518', '2', '-4', 'sale', '9', '2026-08-09 08:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19519', '7', '-1', 'sale', '9', '2026-08-09 10:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19520', '24', '-3', 'sale', '9', '2026-08-09 10:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19521', '9', '-1', 'sale', '9', '2026-08-09 10:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19522', '17', '-2', 'sale', '9', '2026-08-09 12:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19523', '7', '-4', 'sale', '9', '2026-08-09 12:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19524', '24', '-2', 'sale', '9', '2026-08-09 12:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19525', '17', '-4', 'sale', '9', '2026-08-10 20:21:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19526', '4', '-1', 'sale', '9', '2026-08-10 09:38:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19527', '11', '-2', 'sale', '9', '2026-08-10 11:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19528', '1', '-1', 'sale', '9', '2026-08-11 08:32:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19529', '11', '-3', 'sale', '9', '2026-08-11 08:32:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19530', '8', '-2', 'sale', '9', '2026-08-11 10:49:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19531', '1', '-4', 'sale', '9', '2026-08-11 10:49:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19532', '20', '-3', 'sale', '9', '2026-08-11 12:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19533', '8', '-1', 'sale', '9', '2026-08-11 12:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19534', '6', '-2', 'sale', '9', '2026-08-12 09:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19535', '20', '-4', 'sale', '9', '2026-08-12 09:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19536', '8', '-2', 'sale', '9', '2026-08-12 09:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19537', '12', '-3', 'sale', '9', '2026-08-12 11:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19538', '6', '-1', 'sale', '9', '2026-08-12 11:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19539', '20', '-3', 'sale', '9', '2026-08-12 11:00:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19540', '2', '-4', 'sale', '9', '2026-08-12 13:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19541', '12', '-2', 'sale', '9', '2026-08-12 13:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19542', '6', '-4', 'sale', '9', '2026-08-12 13:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19543', '9', '-3', 'sale', '9', '2026-08-13 10:54:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19544', '24', '-4', 'sale', '9', '2026-08-13 12:11:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19545', '7', '-1', 'sale', '9', '2026-08-13 14:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19546', '17', '-4', 'sale', '9', '2026-08-14 11:05:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19547', '7', '-2', 'sale', '9', '2026-08-14 11:05:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19548', '4', '-1', 'sale', '9', '2026-08-14 13:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19549', '17', '-3', 'sale', '9', '2026-08-14 13:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19550', '11', '-2', 'sale', '9', '2026-08-14 15:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19551', '4', '-4', 'sale', '9', '2026-08-14 15:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19552', '6', '-2', 'sale', '9', '2026-08-16 13:27:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19553', '12', '-3', 'sale', '9', '2026-08-16 15:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19554', '2', '-4', 'sale', '9', '2026-08-16 17:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19555', '9', '-1', 'sale', '9', '2026-08-16 19:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19556', '24', '-2', 'sale', '9', '2026-08-16 08:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19557', '9', '-3', 'sale', '9', '2026-08-17 14:38:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19558', '2', '-1', 'sale', '9', '2026-08-17 14:38:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19559', '24', '-4', 'sale', '9', '2026-08-17 16:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19560', '9', '-2', 'sale', '9', '2026-08-17 16:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19561', '7', '-1', 'sale', '9', '2026-08-17 18:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19562', '24', '-3', 'sale', '9', '2026-08-17 18:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19563', '17', '-2', 'sale', '9', '2026-08-17 20:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19564', '7', '-4', 'sale', '9', '2026-08-17 20:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19565', '17', '-4', 'sale', '9', '2026-08-18 15:49:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19566', '7', '-2', 'sale', '9', '2026-08-18 15:49:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19567', '24', '-4', 'sale', '9', '2026-08-18 15:49:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19568', '4', '-1', 'sale', '9', '2026-08-18 17:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19569', '17', '-3', 'sale', '9', '2026-08-18 17:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19570', '7', '-1', 'sale', '9', '2026-08-18 17:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19571', '11', '-2', 'sale', '9', '2026-08-18 19:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19572', '4', '-4', 'sale', '9', '2026-08-18 19:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19573', '17', '-2', 'sale', '9', '2026-08-18 19:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19574', '1', '-3', 'sale', '9', '2026-08-18 08:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19575', '11', '-1', 'sale', '9', '2026-08-18 08:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19576', '4', '-3', 'sale', '9', '2026-08-18 08:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19577', '6', '-2', 'sale', '9', '2026-08-20 17:11:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19578', '20', '-4', 'sale', '9', '2026-08-20 17:11:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19579', '12', '-3', 'sale', '9', '2026-08-20 19:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19580', '6', '-1', 'sale', '9', '2026-08-20 19:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19581', '2', '-4', 'sale', '9', '2026-08-20 08:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19582', '12', '-2', 'sale', '9', '2026-08-20 08:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19583', '9', '-1', 'sale', '9', '2026-08-20 10:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19584', '2', '-3', 'sale', '9', '2026-08-20 10:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19585', '9', '-3', 'sale', '9', '2026-08-21 18:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19586', '2', '-1', 'sale', '9', '2026-08-21 18:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19587', '12', '-3', 'sale', '9', '2026-08-21 18:22:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19588', '24', '-4', 'sale', '9', '2026-08-21 20:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19589', '9', '-2', 'sale', '9', '2026-08-21 20:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19590', '2', '-4', 'sale', '9', '2026-08-21 20:39:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19591', '7', '-1', 'sale', '9', '2026-08-21 09:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19592', '24', '-3', 'sale', '9', '2026-08-21 09:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19593', '9', '-1', 'sale', '9', '2026-08-21 09:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19594', '17', '-2', 'sale', '9', '2026-08-21 11:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19595', '7', '-4', 'sale', '9', '2026-08-21 11:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19596', '24', '-2', 'sale', '9', '2026-08-21 11:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19597', '17', '-4', 'sale', '9', '2026-08-22 19:33:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19598', '4', '-1', 'sale', '9', '2026-08-22 08:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19599', '11', '-2', 'sale', '9', '2026-08-22 10:07:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19600', '1', '-3', 'sale', '9', '2026-08-22 12:24:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19601', '8', '-4', 'sale', '9', '2026-08-22 14:41:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19602', '20', '-1', 'sale', '9', '2026-08-22 16:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19603', '1', '-1', 'sale', '9', '2026-08-23 20:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19604', '11', '-3', 'sale', '9', '2026-08-23 20:44:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19605', '8', '-2', 'sale', '9', '2026-08-23 09:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19606', '1', '-4', 'sale', '9', '2026-08-23 09:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19607', '20', '-3', 'sale', '9', '2026-08-23 11:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19608', '8', '-1', 'sale', '9', '2026-08-23 11:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19609', '6', '-4', 'sale', '9', '2026-08-23 13:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19610', '20', '-2', 'sale', '9', '2026-08-23 13:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19611', '12', '-1', 'sale', '9', '2026-08-23 15:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19612', '6', '-3', 'sale', '9', '2026-08-23 15:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19613', '2', '-2', 'sale', '9', '2026-08-23 17:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19614', '12', '-4', 'sale', '9', '2026-08-23 17:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19615', '6', '-2', 'sale', '9', '2026-08-24 08:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19616', '20', '-4', 'sale', '9', '2026-08-24 08:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19617', '8', '-2', 'sale', '9', '2026-08-24 08:55:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19618', '9', '-3', 'sale', '9', '2026-08-25 09:06:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19619', '17', '-4', 'sale', '9', '2026-08-26 10:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19620', '7', '-2', 'sale', '9', '2026-08-26 10:17:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19621', '1', '-1', 'sale', '9', '2026-08-27 11:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19622', '11', '-3', 'sale', '9', '2026-08-27 11:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19623', '4', '-1', 'sale', '9', '2026-08-27 11:28:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19624', '9', '-3', 'sale', '9', '2026-08-29 13:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19625', '2', '-1', 'sale', '9', '2026-08-29 13:50:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19626', '24', '-4', 'sale', '9', '2026-08-29 15:07:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19627', '9', '-2', 'sale', '9', '2026-08-29 15:07:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19628', '7', '-1', 'sale', '9', '2026-08-29 17:24:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19629', '24', '-3', 'sale', '9', '2026-08-29 17:24:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19630', '17', '-4', 'sale', '9', '2026-08-30 14:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19631', '7', '-2', 'sale', '9', '2026-08-30 14:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19632', '24', '-4', 'sale', '9', '2026-08-30 14:01:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19633', '4', '-1', 'sale', '9', '2026-08-30 16:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19634', '17', '-3', 'sale', '9', '2026-08-30 16:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19635', '7', '-1', 'sale', '9', '2026-08-30 16:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19636', '11', '-2', 'sale', '9', '2026-08-30 18:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19637', '4', '-4', 'sale', '9', '2026-08-30 18:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19638', '17', '-2', 'sale', '9', '2026-08-30 18:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19639', '1', '-1', 'sale', '9', '2026-08-31 15:12:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19640', '8', '-2', 'sale', '9', '2026-08-31 17:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19641', '6', '-2', 'sale', '9', '2026-09-01 16:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19642', '20', '-4', 'sale', '9', '2026-09-01 16:23:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19643', '12', '-3', 'sale', '9', '2026-09-01 18:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19644', '6', '-1', 'sale', '9', '2026-09-01 18:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19645', '9', '-3', 'sale', '9', '2026-09-02 17:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19646', '2', '-1', 'sale', '9', '2026-09-02 17:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19647', '12', '-3', 'sale', '9', '2026-09-02 17:34:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19648', '24', '-4', 'sale', '9', '2026-09-02 19:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19649', '9', '-2', 'sale', '9', '2026-09-02 19:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19650', '2', '-4', 'sale', '9', '2026-09-02 19:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19651', '17', '-4', 'sale', '9', '2026-09-03 18:45:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19652', '4', '-1', 'sale', '9', '2026-09-03 20:02:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19653', '1', '-1', 'sale', '9', '2026-09-04 19:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19654', '11', '-3', 'sale', '9', '2026-09-04 19:56:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19655', '8', '-2', 'sale', '9', '2026-09-04 08:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19656', '1', '-4', 'sale', '9', '2026-09-04 08:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19657', '9', '-3', 'sale', '9', '2026-09-06 08:18:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19658', '24', '-4', 'sale', '9', '2026-09-06 10:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19659', '7', '-1', 'sale', '9', '2026-09-06 12:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19660', '17', '-2', 'sale', '9', '2026-09-06 14:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19661', '17', '-4', 'sale', '9', '2026-09-07 09:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19662', '7', '-2', 'sale', '9', '2026-09-07 09:29:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19663', '4', '-1', 'sale', '9', '2026-09-07 11:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19664', '17', '-3', 'sale', '9', '2026-09-07 11:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19665', '11', '-2', 'sale', '9', '2026-09-07 13:03:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19666', '4', '-4', 'sale', '9', '2026-09-07 13:03:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19667', '1', '-1', 'sale', '9', '2026-09-08 10:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19668', '11', '-3', 'sale', '9', '2026-09-08 10:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19669', '4', '-1', 'sale', '9', '2026-09-08 10:40:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19670', '8', '-2', 'sale', '9', '2026-09-08 12:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19671', '1', '-4', 'sale', '9', '2026-09-08 12:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19672', '11', '-2', 'sale', '9', '2026-09-08 12:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19673', '20', '-3', 'sale', '9', '2026-09-08 14:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19674', '8', '-1', 'sale', '9', '2026-09-08 14:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19675', '1', '-3', 'sale', '9', '2026-09-08 14:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19676', '6', '-2', 'sale', '9', '2026-09-09 11:51:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19677', '12', '-3', 'sale', '9', '2026-09-09 13:08:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19678', '2', '-4', 'sale', '9', '2026-09-09 15:25:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19679', '17', '-4', 'sale', '9', '2026-09-11 13:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19680', '7', '-2', 'sale', '9', '2026-09-11 13:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19681', '24', '-4', 'sale', '9', '2026-09-11 13:13:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19682', '4', '-1', 'sale', '9', '2026-09-11 15:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19683', '17', '-3', 'sale', '9', '2026-09-11 15:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19684', '7', '-1', 'sale', '9', '2026-09-11 15:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19685', '11', '-2', 'sale', '9', '2026-09-11 17:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19686', '4', '-4', 'sale', '9', '2026-09-11 17:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19687', '17', '-2', 'sale', '9', '2026-09-11 17:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19688', '1', '-1', 'sale', '9', '2026-09-12 14:24:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19689', '8', '-2', 'sale', '9', '2026-09-12 16:41:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19690', '20', '-3', 'sale', '9', '2026-09-12 18:58:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19691', '6', '-4', 'sale', '9', '2026-09-12 20:15:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19692', '12', '-1', 'sale', '9', '2026-09-12 09:32:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19693', '6', '-2', 'sale', '9', '2026-09-13 15:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19694', '20', '-4', 'sale', '9', '2026-09-13 15:35:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19695', '12', '-3', 'sale', '9', '2026-09-13 17:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19696', '6', '-1', 'sale', '9', '2026-09-13 17:52:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19697', '2', '-4', 'sale', '9', '2026-09-13 19:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19698', '12', '-2', 'sale', '9', '2026-09-13 19:09:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19699', '9', '-1', 'sale', '9', '2026-09-13 08:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19700', '2', '-3', 'sale', '9', '2026-09-13 08:26:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19701', '24', '-2', 'sale', '9', '2026-09-13 10:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19702', '9', '-4', 'sale', '9', '2026-09-13 10:43:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19703', '9', '-3', 'sale', '9', '2026-09-14 16:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19704', '2', '-1', 'sale', '9', '2026-09-14 16:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19705', '12', '-3', 'sale', '9', '2026-09-14 16:46:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19706', '24', '-4', 'sale', '9', '2026-09-14 18:03:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19707', '9', '-2', 'sale', '9', '2026-09-14 18:03:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19708', '2', '-4', 'sale', '9', '2026-09-14 18:03:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19709', '7', '-1', 'sale', '9', '2026-09-14 20:20:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19710', '24', '-3', 'sale', '9', '2026-09-14 20:20:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19711', '9', '-1', 'sale', '9', '2026-09-14 20:20:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19712', '17', '-2', 'sale', '9', '2026-09-14 09:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19713', '7', '-4', 'sale', '9', '2026-09-14 09:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19714', '24', '-2', 'sale', '9', '2026-09-14 09:37:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19715', '17', '-4', 'sale', '9', '2026-09-15 17:57:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19716', '4', '-1', 'sale', '9', '2026-09-15 19:14:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19717', '11', '-2', 'sale', '9', '2026-09-15 08:31:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19718', '1', '-3', 'sale', '9', '2026-09-15 10:48:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19719', '9', '-3', 'sale', '9', '2026-09-18 20:30:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19720', '24', '-4', 'sale', '9', '2026-09-18 09:47:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19721', '7', '-1', 'sale', '9', '2026-09-18 11:04:00');
INSERT INTO `stock_movements` (`movement_id`, `product_id`, `change_qty`, `reason`, `moved_by`, `moved_at`) VALUES ('19722', '17', '-2', 'sale', '9', '2026-09-18 13:21:00');

-- Table stock_predictions
DROP TABLE IF EXISTS `stock_predictions`;
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

-- Table stock_receiving
DROP TABLE IF EXISTS `stock_receiving`;
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
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('457', NULL, NULL, NULL, '1', '194', '0', '1', '194', '0', '0.32', '62.08', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-1', 'RM_SEED_SALES_TREND_V1-20260918-1', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('458', NULL, NULL, NULL, '2', '214', '0', '1', '214', '0', '0.62', '132.68', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-2', 'RM_SEED_SALES_TREND_V1-20260918-2', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('459', NULL, NULL, NULL, '4', '172', '0', '1', '172', '0', '0.58', '99.76', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-4', 'RM_SEED_SALES_TREND_V1-20260918-4', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('460', NULL, NULL, NULL, '6', '205', '0', '1', '205', '0', '1.45', '297.25', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-6', 'RM_SEED_SALES_TREND_V1-20260918-6', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('461', NULL, NULL, NULL, '7', '186', '0', '1', '186', '0', '0.82', '152.52', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-7', 'RM_SEED_SALES_TREND_V1-20260918-7', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('462', NULL, NULL, NULL, '8', '189', '0', '1', '189', '0', '1.20', '226.80', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-8', 'RM_SEED_SALES_TREND_V1-20260918-8', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('463', NULL, NULL, NULL, '9', '226', '0', '1', '226', '0', '1.05', '237.30', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-9', 'RM_SEED_SALES_TREND_V1-20260918-9', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('464', NULL, NULL, NULL, '11', '205', '0', '1', '205', '0', '1.70', '348.50', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-11', 'RM_SEED_SALES_TREND_V1-20260918-11', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('465', NULL, NULL, NULL, '12', '219', '0', '1', '219', '0', '2.45', '536.55', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-12', 'RM_SEED_SALES_TREND_V1-20260918-12', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('466', NULL, NULL, NULL, '17', '274', '0', '1', '274', '0', '1.68', '460.32', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-17', 'RM_SEED_SALES_TREND_V1-20260918-17', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('467', NULL, NULL, NULL, '20', '233', '0', '1', '233', '0', '0.82', '191.06', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-20', 'RM_SEED_SALES_TREND_V1-20260918-20', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');
INSERT INTO `stock_receiving` (`receiving_id`, `replenishment_request_id`, `purchase_order_id`, `purchase_order_item_id`, `product_id`, `received_qty`, `received_packages`, `units_per_package_used`, `accepted_qty`, `damaged_qty`, `cost_price`, `total_cost`, `received_by`, `received_at`, `supplier`, `po_number`, `invoice_number`, `batch_number`, `expiration_date`, `discrepancy_type`, `discrepancy_qty`, `discrepancy_notes`, `notes`) VALUES ('468', NULL, NULL, NULL, '24', '272', '0', '1', '272', '0', '2.45', '666.40', '11', '2026-06-19 07:00:00', 'RetailMind Seed Supplier', 'RM_SEED_SALES_TREND_V1', 'RM_SEED_SALES_TREND_V1-20260918-24', 'RM_SEED_SALES_TREND_V1-20260918-24', NULL, 'none', '0', NULL, 'Deterministic dashboard sales-trend simulation stock');

-- Table store_settings
DROP TABLE IF EXISTS `store_settings`;
CREATE TABLE `store_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `store_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('business_identifier', '', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('currency_symbol', '₱', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('receipt_footer', 'Thank you for shopping with us.', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('store_address', 'Tangub City', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('store_email', '', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('store_name', 'Shalom Store', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('store_phone', '', NULL, '2026-09-14 15:29:33');
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`) VALUES ('timezone', 'Asia/Manila', NULL, '2026-09-14 15:29:33');

-- Table supplier_products
DROP TABLE IF EXISTS `supplier_products`;
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

-- Table suppliers
DROP TABLE IF EXISTS `suppliers`;
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

-- Table user_privileges
DROP TABLE IF EXISTS `user_privileges`;
CREATE TABLE `user_privileges` (
  `user_id` int(11) NOT NULL,
  `privilege_id` int(11) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`,`privilege_id`),
  KEY `privilege_id` (`privilege_id`),
  CONSTRAINT `user_privileges_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_privileges_ibfk_2` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`privilege_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table users
DROP TABLE IF EXISTS `users`;
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_branch_id` (`branch_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  CONSTRAINT `users_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `profile_image`, `password_hash`, `role_id`, `status`, `failed_login_attempts`, `locked_until`, `last_login_at`, `session_version`, `password_changed_at`, `must_change_password`, `branch_id`, `created_at`) VALUES ('1', 'Super Admin', 'superadmin', 'superadmin@inventory.local', 'b8603d0f10c1be3fc3c819919e417a58429f910bcb825a291689da684886960d.png', '$2y$10$jyoib.jrVfRdSWBvxaKT9eBEqBXT7a9YIuxfSAmpzp9hemBttdO4y', '1', 'active', '0', NULL, '2026-09-17 21:41:27', '2', '2026-09-14 15:30:05', '0', NULL, '2026-09-14 15:29:33');
INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `profile_image`, `password_hash`, `role_id`, `status`, `failed_login_attempts`, `locked_until`, `last_login_at`, `session_version`, `password_changed_at`, `must_change_password`, `branch_id`, `created_at`) VALUES ('2', 'MIKE SORDILLA', 'Mike', 'sordillamike1@gmail.com', NULL, '$2y$10$SeRPHt6JdMTWd7EnxPfCBe/Z8HapmxoUHOEOwI93YNne042BIPeiy', '2', 'active', '0', NULL, '2026-09-17 21:33:41', '5', '2026-09-17 21:37:42', '0', '1', '2026-09-15 06:27:46');
INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `profile_image`, `password_hash`, `role_id`, `status`, `failed_login_attempts`, `locked_until`, `last_login_at`, `session_version`, `password_changed_at`, `must_change_password`, `branch_id`, `created_at`) VALUES ('9', 'RetailMind Sales Trend Seed', 'rm_seed_sales_trend', 'rm-seed-sales-trend@example.test', NULL, '!RM_SEED_LOGIN_DISABLED!', '4', 'disabled', '0', NULL, NULL, '1', NULL, '1', '2', '2026-09-17 08:39:26');
INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `profile_image`, `password_hash`, `role_id`, `status`, `failed_login_attempts`, `locked_until`, `last_login_at`, `session_version`, `password_changed_at`, `must_change_password`, `branch_id`, `created_at`) VALUES ('11', 'RetailMind Sales Seed Receiver', 'rm_seed_sales_receiver', 'rm-seed-sales-receiver@example.test', 'b1e1ee08e88abf5c8f0eda5f2e4352ac6f64c3db035d22e3ac8b6751dfa882f7.png', '!RM_SEED_LOGIN_DISABLED!', '2', 'disabled', '0', NULL, NULL, '1', NULL, '1', '2', '2026-09-17 08:43:42');

SET FOREIGN_KEY_CHECKS=1;
