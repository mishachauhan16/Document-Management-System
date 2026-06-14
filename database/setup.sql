-- =======================================================
-- DMS — Database Setup
-- Run: mysql -u root -p < setup.sql
-- =======================================================

CREATE DATABASE IF NOT EXISTS dms_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE dms_db;

-- -------------------------------------------------------
-- USERS
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(100)  NOT NULL,
    email                VARCHAR(150)  NOT NULL UNIQUE,
    password_hash        VARCHAR(255)  NOT NULL,
    role                 ENUM('admin','user') NOT NULL DEFAULT 'user',
    must_change_password TINYINT(1)   NOT NULL DEFAULT 1,  -- 1 = force change on first login
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    created_by           INT          NULL,                 -- admin who created this user
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -------------------------------------------------------
-- DOCUMENTS
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    owner_id          INT          NOT NULL,
    title             VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename   VARCHAR(255) NOT NULL,
    file_path         VARCHAR(500) NOT NULL,
    compressed_path   VARCHAR(500) NULL,
    file_type         VARCHAR(100) NOT NULL,
    file_size_bytes   BIGINT       NOT NULL DEFAULT 0,
    is_compressed     TINYINT(1)   NOT NULL DEFAULT 0,
    deleted_at        TIMESTAMP    NULL DEFAULT NULL,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- SHARES
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS shares (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    doc_id         INT NOT NULL,
    shared_by_id   INT NOT NULL,
    shared_with_id INT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_share (doc_id, shared_with_id),
    FOREIGN KEY (doc_id)         REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by_id)   REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (shared_with_id) REFERENCES users(id)     ON DELETE CASCADE
);

-- -------------------------------------------------------
-- AUDIT LOGS
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    doc_id     INT NULL,
    action     ENUM(
                 'login','logout',
                 'upload','view','download','edit','delete','restore','share',
                 'create_user','update_user','delete_user',
                 'change_password'
               ) NOT NULL,
    ip_address VARCHAR(45),
    details    TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (doc_id)  REFERENCES documents(id) ON DELETE SET NULL
);

-- -------------------------------------------------------
-- SEED: Default Admin
-- Email:    admin@dms.com
-- Password: Admin@123   ← CHANGE IMMEDIATELY AFTER SETUP
-- must_change_password = 0 (admin doesn't need to change)
-- -------------------------------------------------------
INSERT INTO users (name, email, password_hash, role, must_change_password) VALUES (
    'Super Admin',
    'admin@dms.com',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    0
);
