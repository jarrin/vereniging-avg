-- Database schema for AVG Vragenlijsten
-- Volgt de verstrekte ERD

CREATE DATABASE IF NOT EXISTS vereniging_avg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vereniging_avg;

-- UUIDs worden opgeslagen als CHAR(36) voor leesbaarheid in deze fase
-- In een productieomgeving met miljoenen records zou BINARY(16) sneller zijn

CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    use_phone BOOLEAN DEFAULT FALSE,
    logo_path VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE campaigns (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    reply_to_email VARCHAR(255) NULL,
    email_subject VARCHAR(255) NOT NULL,
    email_body TEXT NOT NULL,
    reminder_subject VARCHAR(255) NULL,
    reminder_body TEXT NULL,
    reminder_enabled BOOLEAN DEFAULT FALSE,
    reminder_days INTEGER DEFAULT 7,
    non_response_action ENUM('send_reminder', 'default_no', 'no_action') DEFAULT 'no_action',
    start_date DATE,
    end_date DATE,
    report_generated_at TIMESTAMP NULL,
    auto_delete_at TIMESTAMP NULL,
    status ENUM('draft', 'active', 'paused', 'completed', 'deleted') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Migratie voor bestaande databases:
-- ALTER TABLE users ADD COLUMN logo_path VARCHAR(255) NULL AFTER email;
-- ALTER TABLE campaigns ADD COLUMN reply_to_email VARCHAR(255) NULL AFTER name;
-- ALTER TABLE campaigns ADD COLUMN reminder_subject VARCHAR(255) NULL AFTER email_body;
-- ALTER TABLE campaigns ADD COLUMN reminder_body TEXT NULL AFTER reminder_subject;

CREATE TABLE questions (
    id CHAR(36) PRIMARY KEY,
    campaign_id CHAR(36) NOT NULL,
    question_text VARCHAR(255) NOT NULL,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE persons (
    id CHAR(36) PRIMARY KEY,
    campaign_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    email_status ENUM('pending', 'sent', 'delivered', 'failed', 'bounced') DEFAULT 'pending',
    token VARCHAR(64) UNIQUE,
    token_expired BOOLEAN DEFAULT FALSE,
    first_sent_at TIMESTAMP NULL,
    reminder_sent_at TIMESTAMP NULL,
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE answers (
    id CHAR(36) PRIMARY KEY,
    person_id CHAR(36) NOT NULL,
    question_id CHAR(36) NOT NULL,
    answer BOOLEAN,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE email_logs (
    id CHAR(36) PRIMARY KEY,
    person_id CHAR(36) NOT NULL,
    campaign_id CHAR(36) NOT NULL,
    type ENUM('initial', 'reminder', 'confirmation') NOT NULL,
    status ENUM('success', 'failed', 'bounced') NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reports (
    id CHAR(36) PRIMARY KEY,
    campaign_id CHAR(36) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    downloaded_at TIMESTAMP NULL,
    downloaded_by CHAR(36),
    sent_via_email BOOLEAN DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (downloaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE file_uploads (
    id CHAR(36) PRIMARY KEY,
    campaign_id CHAR(36) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type ENUM('csv', 'xlsx') NOT NULL,
    record_count INTEGER NOT NULL,
    uploaded_by CHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
