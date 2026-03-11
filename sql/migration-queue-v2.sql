-- Sherwood Adventure Tournament System
-- Migration: Queue V2 — Dynamic Registration & Game Duration
-- Run this after migration-queue.sql.
--
-- Adds game_duration_minutes so the system can calculate how many games
-- remain before the event end time and dynamically close registration
-- when no more game slots are available.

USE dbs15308446;

-- Minutes per game cycle (play time + field reset). Used to calculate
-- remaining slots: (end_time - now) / game_duration_minutes.
-- Only relevant for queue-type tournaments; NULL for other types.
ALTER TABLE tournaments ADD COLUMN game_duration_minutes INT NULL AFTER end_date;

-- End time for the event day. Combined with end_date to form a full datetime.
-- Queue uses this to know when to stop accepting new teams.
-- Stored separately from end_date since end_date is DATE-only.
ALTER TABLE tournaments ADD COLUMN end_time TIME NULL AFTER end_date;

-- Verify
SELECT 'Queue V2 migration complete!' AS status;
