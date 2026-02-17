-- ============================================================
-- Migration: Group Stage Support for Two-Stage Tournaments
-- Sherwood Adventure Tournament System
--
-- Run this against your existing database to add group stage support.
-- This adds a time_slot_id column to round_robin_standings so that
-- standings can be tracked per-group in two-stage tournaments.
-- ============================================================

USE dbs15308446;

-- Add time_slot_id column to round_robin_standings
-- This links each standings row to a group (time slot) for two-stage tournaments.
-- For standalone Round Robin tournaments, this will remain NULL.
ALTER TABLE round_robin_standings
    ADD COLUMN time_slot_id INT NULL AFTER team_id;

-- Add foreign key constraint
ALTER TABLE round_robin_standings
    ADD CONSTRAINT fk_rrs_time_slot
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL;

-- Verify the migration
SELECT 'Migration complete! round_robin_standings now has time_slot_id column.' AS status;
DESCRIBE round_robin_standings;
