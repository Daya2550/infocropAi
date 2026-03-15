-- InfoCrop AI - Master Consolidated Database Setup
-- Generated from final live schema
-- Includes all updates for dynamic plans, smart checking, and AI features.

CREATE DATABASE IF NOT EXISTS infocrop;

USE infocrop;

CREATE TABLE IF NOT EXISTS `users` (
    `id` int NOT NULL AUTO_INCREMENT,
    `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `signup_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `latitude` decimal(10, 8) DEFAULT NULL,
    `longitude` decimal(11, 8) DEFAULT NULL,
    `signup_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `device_fingerprint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `user_agent` text COLLATE utf8mb4_unicode_ci,
    `usage_limit` decimal(10, 2) DEFAULT '1.00',
    `usage_count` decimal(10, 2) DEFAULT '0.00',
    `status` enum('active', 'suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `phone` (`phone`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
    `id` int NOT NULL AUTO_INCREMENT,
    `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `setting_value` text COLLATE utf8mb4_unicode_ci,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `amount` decimal(10, 2) DEFAULT NULL,
    `screenshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `transaction_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status` enum(
        'pending',
        'approved',
        'rejected'
    ) COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
    `new_limit` decimal(10, 2) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_reports` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `farmer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `crop` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `season` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `land_area` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `pdf_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `report_data` longtext COLLATE utf8mb4_unicode_ci,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `farm_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `smart_reports` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `base_report_id` int DEFAULT NULL,
    `detected_stage` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `crop` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `sowing_date` date DEFAULT NULL,
    `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `weather_info` text COLLATE utf8mb4_unicode_ci,
    `field_status` longtext COLLATE utf8mb4_unicode_ci,
    `problem_notes` text COLLATE utf8mb4_unicode_ci,
    `updated_report_data` longtext COLLATE utf8mb4_unicode_ci,
    `pdf_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `smart_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gemini_api_keys` (
    `id` int NOT NULL AUTO_INCREMENT,
    `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Key',
    `api_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `platform` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gemini',
    `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gemini-1.5-flash',
    `base_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `exhausted_date` date DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT '1',
    `calls_today` int NOT NULL DEFAULT '0',
    `last_call_date` date DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_links` (
    `id` int NOT NULL AUTO_INCREMENT,
    `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `user_usage_limit` decimal(10, 2) DEFAULT '1.00',
    `is_used` tinyint(1) DEFAULT '0',
    `created_by` int NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `token` (`token`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `referral_links_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crop_news` (
    `id` int NOT NULL AUTO_INCREMENT,
    `crop_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `content` text COLLATE utf8mb4_unicode_ci,
    `created_at` date NOT NULL,
    PRIMARY KEY (`id`),
    KEY `crop_lookup` (`crop_name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crop_tasks` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `smart_report_id` int NOT NULL,
    `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `description` text COLLATE utf8mb4_unicode_ci,
    `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `due_date` date NOT NULL,
    `priority` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_tasks` (`user_id`, `status`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_expenses` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `smart_report_id` int NOT NULL,
    `item` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `amount` decimal(10, 2) NOT NULL,
    `date` date NOT NULL,
    `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_expenses` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================
-- INITIAL DATA & DEFAULT SETTINGS
-- ============================================================

-- Default Admins
REPLACE INTO
    admins (id, username, password)
VALUES (
        1,
        'mohsin',
        '$2y$10$qPYb957DcWQgUyTikwQLjOcz6TbYJXqxLZnVTYjgLazcIBWHPIS0e' -- Password: 12345678 or admin123 setup
    ),
    (
        2,
        'dj',
        '$2y$10$qPYb957DcWQgUyTikwQLjOcz6TbYJXqxLZnVTYjgLazcIBWHPIS0e'
    );

-- System Settings
INSERT INTO
    settings (setting_key, setting_value)
VALUES ('site_name', 'InfoCrop AI'),
    (
        'gemini_api_key',
        'AIzaSyBsx-utqdSvwExJK0zFiXQccXu-xrxAsow'
    ),
    (
        'gemini_model',
        'gemini-1.5-flash'
    ),
    ('plan_starter_price', '50'),
    ('plan_starter_limit', '10'),
    ('plan_pro_price', '100'),
    ('plan_pro_limit', '25')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value);

    -- Add crop_type to farm_reports (default 'annual' = no change for existing rows)
ALTER TABLE farm_reports ADD COLUMN crop_type VARCHAR(20) DEFAULT 'annual' AFTER season;

-- New table: tracks crop health after every Smart Check
CREATE TABLE IF NOT EXISTS crop_health_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    farm_report_id INT DEFAULT NULL,
    smart_report_id INT DEFAULT NULL,
    crop VARCHAR(255),
    crop_type VARCHAR(20) DEFAULT 'annual',
    snapshot_date DATE NOT NULL,
    tree_age_days INT DEFAULT NULL,
    detected_stage VARCHAR(100),
    health_score TINYINT DEFAULT NULL,   -- 1–100 AI-assigned score
    key_findings TEXT,                   -- short AI summary
    model_predictions TEXT NULL,         -- JSON storage for the 5 predictive models
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY user_crop (user_id, crop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
