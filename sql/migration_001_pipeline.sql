-- Migration 001: Pipeline architecture
-- Run once on existing installations.
-- Fresh installs: schema.sql already includes this.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- Per-round category activation + advancement rules
CREATE TABLE IF NOT EXISTS `round_category_map` (
  `round_id`          INT UNSIGNED NOT NULL,
  `category_id`       INT UNSIGNED NOT NULL,
  `advancement_count` TINYINT UNSIGNED NULL COMMENT 'NULL=all, 0=none, N=top-N',
  `advancement_mode`  ENUM('auto','manual','all','none') NOT NULL DEFAULT 'manual',
  `next_category_id`  INT UNSIGNED NULL COMMENT 'NULL=same cat; set to merge into another cat in next round',
  PRIMARY KEY (`round_id`, `category_id`),
  FOREIGN KEY (`round_id`)          REFERENCES `event_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`)       REFERENCES `categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`next_category_id`)  REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate existing rounds: activate all event categories, inherit top_n_to_promote
INSERT IGNORE INTO round_category_map
  (round_id, category_id, advancement_count, advancement_mode)
SELECT
  er.id,
  c.id,
  er.top_n_to_promote,
  CASE WHEN er.top_n_to_promote IS NOT NULL THEN 'auto' ELSE 'manual' END
FROM event_rounds er
JOIN categories c ON c.event_id = er.event_id;

-- Theme columns on events
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS theme_preset VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS theme_colors  JSON        NULL;

-- Global application settings (key/value)
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key`        VARCHAR(100) PRIMARY KEY,
  `value`      TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES ('admin_theme', 'saas');

-- Registered voters and closed-list tokens
CREATE TABLE IF NOT EXISTS `voter_lists` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`          INT UNSIGNED NOT NULL,
  `email`             VARCHAR(254) NOT NULL,
  `name`              VARCHAR(120),
  `token`             VARCHAR(64) NOT NULL UNIQUE,
  `token_used`        TINYINT(1) NOT NULL DEFAULT 0,
  `token_used_at`     DATETIME NULL,
  `source`            ENUM('preloaded','self_registered') NOT NULL DEFAULT 'self_registered',
  `consent_marketing` TINYINT(1) NOT NULL DEFAULT 0,
  `approved`          TINYINT(1) NOT NULL DEFAULT 1,
  `weight`            DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  `invited_at`        DATETIME NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_event_email` (`event_id`, `email`),
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending registration requests (registration_with_approval mode)
CREATE TABLE IF NOT EXISTS `voter_registration_requests` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`     INT UNSIGNED NOT NULL,
  `name`         VARCHAR(120),
  `email`        VARCHAR(254) NOT NULL,
  `extra_fields` JSON,
  `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`  DATETIME NULL,
  `reviewed_by`  INT UNSIGNED NULL,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
