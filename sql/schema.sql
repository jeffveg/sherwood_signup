-- Sherwood Adventure Tournament System
-- Database Schema
-- Run this SQL to set up the database

CREATE DATABASE IF NOT EXISTS dbs15308446 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dbs15308446;

-- ============================================================
-- ADMINS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB;

-- Default admin account: admin / SherwoodAdmin2024!
-- CHANGE THIS PASSWORD IMMEDIATELY after first login
INSERT INTO admins (username, password_hash, display_name, email) VALUES
('admin', '$2y$10$placeholder', 'Administrator', 'admin@sherwoodadventure.com');

-- ============================================================
-- TOURNAMENTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS tournaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_number VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    tournament_type ENUM('single_elimination', 'double_elimination', 'round_robin', 'two_stage', 'league') NOT NULL,
    -- For two_stage: which elimination type for stage 2
    two_stage_elimination_type ENUM('single_elimination', 'double_elimination') NULL,
    -- How many teams advance from round robin to elimination (for two_stage)
    two_stage_advance_count INT DEFAULT 4,
    status ENUM('draft', 'registration_open', 'registration_closed', 'in_progress', 'completed', 'cancelled') DEFAULT 'draft',
    signup_mode ENUM('simple_form', 'account_based') DEFAULT 'simple_form',
    bracket_display ENUM('full', 'compact') DEFAULT 'full',
    max_teams INT DEFAULT 16,
    min_teams INT DEFAULT 2,
    start_date DATE NULL,
    end_date DATE NULL,
    registration_deadline DATETIME NULL,
    location VARCHAR(255),
    rules TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TIME SLOTS TABLE (for Round Robin and Two-Stage tournaments)
-- ============================================================
CREATE TABLE IF NOT EXISTS time_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    slot_date DATE NOT NULL,
    slot_time TIME NOT NULL,
    slot_label VARCHAR(100),  -- e.g., "12:00 PM Saturday"
    max_teams INT DEFAULT 3,
    location VARCHAR(255),
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TEAMS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    team_name VARCHAR(255) NOT NULL,
    captain_name VARCHAR(255) NOT NULL,
    captain_email VARCHAR(255) NOT NULL,
    captain_phone VARCHAR(30),
    time_slot_id INT NULL,
    seed INT NULL,
    status ENUM('registered', 'confirmed', 'checked_in', 'eliminated', 'withdrawn') DEFAULT 'registered',
    is_forfeit TINYINT(1) DEFAULT 0,
    registration_code VARCHAR(20) NOT NULL,  -- For team to reference later
    notes TEXT,
    logo_path VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL,
    UNIQUE KEY unique_team_tournament (tournament_id, team_name)
) ENGINE=InnoDB;

-- ============================================================
-- TEAM ACCOUNTS TABLE (for account-based signup mode)
-- ============================================================
CREATE TABLE IF NOT EXISTS team_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    captain_name VARCHAR(255) NOT NULL,
    phone VARCHAR(30),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB;

-- Link team registrations to accounts when in account mode
ALTER TABLE teams ADD COLUMN team_account_id INT NULL AFTER notes;
ALTER TABLE teams ADD FOREIGN KEY (team_account_id) REFERENCES team_accounts(id) ON DELETE SET NULL;

-- ============================================================
-- MATCHES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    round INT NOT NULL,               -- Round number (1, 2, 3... or for double elim, negative = losers bracket)
    match_number INT NOT NULL,         -- Position within the round
    bracket_type ENUM('winners', 'losers', 'grand_final', 'round_robin') DEFAULT 'winners',
    team1_id INT NULL,
    team2_id INT NULL,
    team1_score INT NULL,
    team2_score INT NULL,
    winner_id INT NULL,
    loser_id INT NULL,
    -- For bracket progression: which match does winner/loser go to
    winner_next_match_id INT NULL,
    loser_next_match_id INT NULL,
    time_slot_id INT NULL,
    scheduled_time DATETIME NULL,
    status ENUM('pending', 'in_progress', 'completed', 'bye') DEFAULT 'pending',
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team1_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (team2_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (winner_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (loser_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- ROUND ROBIN STANDINGS (materialized for performance)
-- ============================================================
CREATE TABLE IF NOT EXISTS round_robin_standings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    team_id INT NOT NULL,
    time_slot_id INT NULL,  -- Group ID for two-stage tournaments (NULL for standalone RR)
    wins INT DEFAULT 0,
    losses INT DEFAULT 0,
    draws INT DEFAULT 0,
    points_for INT DEFAULT 0,
    points_against INT DEFAULT 0,
    point_differential INT DEFAULT 0,
    ranking INT NULL,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL,
    UNIQUE KEY unique_standing (tournament_id, team_id)
) ENGINE=InnoDB;

-- Migration for existing databases:
-- ALTER TABLE round_robin_standings ADD COLUMN time_slot_id INT NULL AFTER team_id;
-- ALTER TABLE round_robin_standings ADD FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL;

-- ============================================================
-- INDEXES for performance
-- ============================================================
CREATE INDEX idx_tournaments_status ON tournaments(status);
CREATE INDEX idx_tournaments_number ON tournaments(tournament_number);
CREATE INDEX idx_teams_tournament ON teams(tournament_id);
CREATE INDEX idx_teams_timeslot ON teams(time_slot_id);
CREATE INDEX idx_matches_tournament ON matches(tournament_id);
CREATE INDEX idx_matches_round ON matches(tournament_id, round, match_number);
CREATE INDEX idx_timeslots_tournament ON time_slots(tournament_id);
CREATE INDEX idx_rr_standings ON round_robin_standings(tournament_id, ranking);
