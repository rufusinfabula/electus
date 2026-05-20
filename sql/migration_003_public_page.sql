-- Migration 003: public page content fields for events and rounds

ALTER TABLE `events`
  ADD COLUMN `public_logo_url`    VARCHAR(500) NULL AFTER `description`,
  ADD COLUMN `public_privacy_url` VARCHAR(500) NULL AFTER `public_logo_url`,
  ADD COLUMN `public_info_box`    TEXT         NULL AFTER `public_privacy_url`;

ALTER TABLE `event_rounds`
  ADD COLUMN `public_description`  TEXT NULL AFTER `label`,
  ADD COLUMN `public_instructions` TEXT NULL AFTER `public_description`,
  ADD COLUMN `public_info_box`     TEXT NULL AFTER `public_instructions`;
