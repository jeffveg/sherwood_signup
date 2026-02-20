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

-- Feature 5: League encounters (play each team X times)
ALTER TABLE tournaments ADD COLUMN league_encounters INT DEFAULT 1 AFTER two_stage_advance_count;

-- Feature 7: Team captain accounts (for account-based signup mode)
CREATE TABLE IF NOT EXISTS team_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    captain_name VARCHAR(255) NOT NULL,
    phone VARCHAR(30),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB;

-- Feature 8: Link teams to captain accounts
ALTER TABLE teams ADD COLUMN team_account_id INT NULL AFTER notes;
ALTER TABLE teams ADD FOREIGN KEY (team_account_id) REFERENCES team_accounts(id) ON DELETE SET NULL;

-- Feature 6: Round labels (custom labels/dates per round)
CREATE TABLE IF NOT EXISTS round_labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    round_number INT NOT NULL,
    label VARCHAR(100) NULL,
    round_date DATE NULL,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_round_label (tournament_id, round_number)
) ENGINE=InnoDB;
