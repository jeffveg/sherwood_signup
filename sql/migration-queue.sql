-- Sherwood Adventure Tournament System
-- Migration: Queue Tournament Type
-- Run this against an existing database to add queue (walk-up) support.
-- Prerequisite: migration-sms.sql must be applied first.

USE dbs15308446;

-- Add 'queue' to the tournament_type ENUM.
-- Queue is a walk-up system: teams sign up, get a position, check in on arrival,
-- and are paired next-two-in-line by an operator. SMS notifications are the core feature.
ALTER TABLE tournaments MODIFY COLUMN tournament_type
  ENUM('single_elimination', 'double_elimination', 'round_robin', 'two_stage', 'league', 'queue') NOT NULL;

-- Queue position for teams. Auto-assigned at signup (MAX+1).
-- Operator can reorder via the queue operator page. Only used for queue-type tournaments.
ALTER TABLE teams ADD COLUMN queue_position INT NULL AFTER seed;

-- Add 'queue' to the matches bracket_type ENUM.
-- Queue matches are created on-the-fly by the operator (not pre-generated like brackets).
ALTER TABLE matches MODIFY COLUMN bracket_type
  ENUM('winners', 'losers', 'grand_final', 'round_robin', 'queue') DEFAULT 'winners';

-- Verify
SELECT 'Queue migration complete!' AS status;
