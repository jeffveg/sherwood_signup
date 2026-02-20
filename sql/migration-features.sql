-- Sherwood Adventure Tournament System
-- Migration: Forfeit, League Type, Team Logos
-- Run this against an existing database to add the new features.

-- Feature 1: Forfeit flag on teams
ALTER TABLE teams ADD COLUMN is_forfeit TINYINT(1) DEFAULT 0 AFTER status;

-- Feature 2: League tournament type
ALTER TABLE tournaments MODIFY tournament_type ENUM('single_elimination', 'double_elimination', 'round_robin', 'two_stage', 'league') NOT NULL;

-- Feature 3: Team logo uploads
ALTER TABLE teams ADD COLUMN logo_path VARCHAR(500) NULL AFTER notes;

-- Feature 4: Compact bracket display option
-- 'full' = show all rounds including byes, 'compact' = hide bye rounds, start at first real round
ALTER TABLE tournaments ADD COLUMN bracket_display ENUM('full', 'compact') DEFAULT 'full' AFTER signup_mode;
