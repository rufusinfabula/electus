-- Migration 002: Configurable category terminology per event
-- Run once on existing installations.
-- Fresh installs: schema.sql already includes this.

SET NAMES utf8mb4;

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS cat_term VARCHAR(50) NULL AFTER theme_colors;
