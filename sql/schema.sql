-- Electus — Database schema
-- MySQL 8 / UTF8MB4

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- Admin panel users
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(120)  NOT NULL,
  `email`         VARCHAR(254)  NOT NULL UNIQUE,
  `password_hash` VARCHAR(255)  NOT NULL,
  `role`          ENUM('superadmin','event_manager','results_reader') NOT NULL DEFAULT 'event_manager',
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-event role assignments for non-superadmin users
CREATE TABLE IF NOT EXISTS `user_event_permissions` (
  `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`  INT UNSIGNED NOT NULL,
  `event_id` INT UNSIGNED NOT NULL,
  `role`     ENUM('event_manager','results_reader') NOT NULL,
  UNIQUE KEY `uq_user_event` (`user_id`, `event_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voting events
CREATE TABLE IF NOT EXISTS `events` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`               VARCHAR(200) NOT NULL,
  `slug`               VARCHAR(200) NOT NULL UNIQUE,
  `description`        TEXT,
  `type`               ENUM('public','private') NOT NULL DEFAULT 'public',
  `access_mode`        ENUM('anonymous','voluntary_registration','mandatory_registration','closed_list','registration_with_approval') NOT NULL DEFAULT 'anonymous',
  `email_verification` TINYINT(1) NOT NULL DEFAULT 0,
  `results_public`     TINYINT(1) NOT NULL DEFAULT 1,
  `theme_preset`       VARCHAR(50) NULL,
  `theme_colors`       JSON NULL,
  `status`             ENUM('draft','active','closed','archived') NOT NULL DEFAULT 'draft',
  `created_by`         INT UNSIGNED NOT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rounds within an event (each with its own voting model)
CREATE TABLE IF NOT EXISTS `event_rounds` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`            INT UNSIGNED NOT NULL,
  `round_number`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `label`               VARCHAR(100),
  `model`               ENUM('open','single','multiple','borda','proportional','weighted') NOT NULL,
  `status`              ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  `opens_at`            DATETIME NULL,
  `closes_at`           DATETIME NULL,
  `config`              JSON,
  `parent_round_id`     INT UNSIGNED NULL,
  `top_n_to_promote`    TINYINT UNSIGNED NULL,
  `promotion_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
  `votes_validated`     TINYINT(1) NOT NULL DEFAULT 0,
  `validated_by`        INT UNSIGNED NULL,
  `validated_at`        DATETIME NULL,
  `results_released`    TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (`event_id`)        REFERENCES `events`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_round_id`) REFERENCES `event_rounds`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-round category activation + advancement rules
CREATE TABLE IF NOT EXISTS `round_category_map` (
  `round_id`          INT UNSIGNED NOT NULL,
  `category_id`       INT UNSIGNED NOT NULL,
  `advancement_count` TINYINT UNSIGNED NULL COMMENT 'NULL=all, 0=none, N=top-N',
  `advancement_mode`  ENUM('auto','manual','all','none') NOT NULL DEFAULT 'manual',
  `next_category_id`  INT UNSIGNED NULL COMMENT 'NULL=same cat; set to merge into another cat in next round',
  PRIMARY KEY (`round_id`, `category_id`),
  FOREIGN KEY (`round_id`)         REFERENCES `event_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`)      REFERENCES `categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`next_category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories within an event
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`   INT UNSIGNED NOT NULL,
  `name`       VARCHAR(200) NOT NULL,
  `slug`       VARCHAR(200) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_event_cat_slug` (`event_id`, `slug`),
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Candidates (both pre-loaded for closed rounds and user-submitted for open rounds)
CREATE TABLE IF NOT EXISTS `candidates` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `round_id`       INT UNSIGNED NOT NULL,
  `category_id`    INT UNSIGNED NOT NULL,
  `name`           VARCHAR(300) NOT NULL,
  `canonical_name` VARCHAR(300) NOT NULL,
  `source`         ENUM('manual','promoted','user_input') NOT NULL DEFAULT 'manual',
  `status`         ENUM('active','merged','excluded') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`round_id`)    REFERENCES `event_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent alias dictionary for candidate deduplication across editions
CREATE TABLE IF NOT EXISTS `candidate_aliases` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id`       INT UNSIGNED NOT NULL,
  `category_id`    INT UNSIGNED NOT NULL,
  `alias`          VARCHAR(300) NOT NULL,
  `canonical_name` VARCHAR(300) NOT NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`)    REFERENCES `events`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin review queue for open-round candidate deduplication
CREATE TABLE IF NOT EXISTS `dedup_queue` (
  `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `round_id`               INT UNSIGNED NOT NULL,
  `category_id`            INT UNSIGNED NOT NULL,
  `raw_input`              VARCHAR(300) NOT NULL,
  `normalized_input`       VARCHAR(300) NOT NULL,
  `suggested_candidate_id` INT UNSIGNED NULL,
  `similarity_score`       TINYINT UNSIGNED NULL,
  `status`                 ENUM('pending','merged','kept','excluded') NOT NULL DEFAULT 'pending',
  `reviewed_by`            INT UNSIGNED NULL,
  `reviewed_at`            DATETIME NULL,
  FOREIGN KEY (`round_id`)    REFERENCES `event_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Votes — intentionally decoupled from voter identity
-- anonymous_id is generated fresh at vote time, never derivable from email/token
CREATE TABLE IF NOT EXISTS `votes` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `round_id`     INT UNSIGNED NOT NULL,
  `candidate_id` INT UNSIGNED NOT NULL,
  `category_id`  INT UNSIGNED NOT NULL,
  `value`        DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  `anonymous_id` VARCHAR(64) NOT NULL,
  `voted_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`round_id`)     REFERENCES `event_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`)  REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Results snapshots per round (computed and stored for public display)
CREATE TABLE IF NOT EXISTS `results` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `round_id`     INT UNSIGNED NOT NULL,
  `category_id`  INT UNSIGNED NOT NULL,
  `candidate_id` INT UNSIGNED NOT NULL,
  `total_votes`  INT UNSIGNED NOT NULL DEFAULT 0,
  `total_points` INT UNSIGNED NOT NULL DEFAULT 0,
  `rank`         SMALLINT UNSIGNED NULL,
  `computed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`round_id`)     REFERENCES `event_rounds`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`)  REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
