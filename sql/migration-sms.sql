-- Sherwood Adventure Tournament System
-- Migration: SMS Notifications via QUO API
-- Run this against an existing database to add SMS notification support.

USE dbs15308446;

-- Feature 1: Tournament-level SMS toggle
-- Allows per-tournament opt-in to SMS notifications.
ALTER TABLE tournaments ADD COLUMN sms_enabled TINYINT(1) DEFAULT 0 AFTER bracket_display;

-- Feature 2: Team SMS opt-in
-- Captains opt in during signup (default checked). Only teams with
-- sms_opt_in = 1 AND a valid captain_phone will receive texts.
ALTER TABLE teams ADD COLUMN sms_opt_in TINYINT(1) DEFAULT 0 AFTER captain_phone;

-- Feature 3: SMS log table for deduplication and audit trail
-- The UNIQUE KEY on (match_id, team_id, notification_type) prevents duplicate
-- texts. A team can receive both "upcoming" (~N games away) and "on_deck"
-- (next up) for the same match since those are different notification types.
CREATE TABLE IF NOT EXISTS sms_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    match_id INT NOT NULL,
    team_id INT NOT NULL,
    notification_type ENUM('upcoming', 'on_deck', 'score') NOT NULL,
    phone_to VARCHAR(30) NOT NULL,
    message_body TEXT NOT NULL,
    quo_message_id VARCHAR(100) NULL,
    status ENUM('sent', 'failed', 'skipped') DEFAULT 'sent',
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE KEY unique_notification (match_id, team_id, notification_type)
) ENGINE=InnoDB;

-- Verify
SELECT 'SMS migration complete!' AS status;
DESCRIBE sms_log;
