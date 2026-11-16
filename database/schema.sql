-- Garage64 Database Schema
-- MySQL 8+
CREATE DATABASE IF NOT EXISTS garage64 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE garage64;
-- Categories
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Tags
CREATE TABLE IF NOT EXISTS tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Miniatures
CREATE TABLE IF NOT EXISTS miniatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    manufacturer VARCHAR(100) NOT NULL,
    model VARCHAR(255) DEFAULT NULL,
    scale VARCHAR(20) DEFAULT NULL,
    year SMALLINT UNSIGNED DEFAULT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    `condition` ENUM('sealed', 'open', 'no_box') NOT NULL DEFAULT 'sealed',
    location ENUM('display', 'storage') NOT NULL DEFAULT 'storage',
    public_description TEXT DEFAULT NULL,
    private_story TEXT DEFAULT NULL,
    private_notes TEXT DEFAULT NULL,
    purchase_price DECIMAL(10, 2) DEFAULT NULL,
    estimated_price DECIMAL(10, 2) DEFAULT NULL,
    purchase_date DATE DEFAULT NULL,
    purchase_location VARCHAR(255) DEFAULT NULL,
    emotional_rating TINYINT UNSIGNED DEFAULT NULL CHECK (
        emotional_rating BETWEEN 1 AND 5
    ),
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 9999,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    user_id INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE
    SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Miniature Photos
CREATE TABLE IF NOT EXISTS miniature_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    miniature_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Miniature Tags (pivot)
CREATE TABLE IF NOT EXISTS miniature_tags (
    miniature_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (miniature_id, tag_id),
    FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Wishlist
CREATE TABLE IF NOT EXISTS wishlist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    manufacturer VARCHAR(100) DEFAULT NULL,
    scale VARCHAR(20) DEFAULT NULL,
    target_price DECIMAL(10, 2) DEFAULT NULL,
    reference_url VARCHAR(1000) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('wanted', 'purchased', 'cancelled') NOT NULL DEFAULT 'wanted',
    user_id INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Users (collectors)
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    slug VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(255) NOT NULL DEFAULT '' UNIQUE,
    email_verified_at DATETIME DEFAULT NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 0,
    requires_email_verification TINYINT(1) NOT NULL DEFAULT 0,
    registration_country CHAR(2) DEFAULT NULL,
    declared_country CHAR(2) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    bio TEXT DEFAULT NULL,
    is_banned TINYINT(1) NOT NULL DEFAULT 0,
    is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Miniature Comments (social module — Phase 1)
CREATE TABLE IF NOT EXISTS email_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    messages TINYINT(1) NOT NULL DEFAULT 1,
    likes TINYINT(1) NOT NULL DEFAULT 1,
    comments TINYINT(1) NOT NULL DEFAULT 1,
    ratings TINYINT(1) NOT NULL DEFAULT 1,
    follows TINYINT(1) NOT NULL DEFAULT 1,
    registrations TINYINT(1) NOT NULL DEFAULT 1,
    announcements TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_delivery_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    automatic TINYINT(1) NOT NULL DEFAULT 1,
    message_delay INT UNSIGNED NOT NULL DEFAULT 600,
    interaction_delay INT UNSIGNED NOT NULL DEFAULT 300,
    category_interval INT UNSIGNED NOT NULL DEFAULT 3600
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_digest_state (
    user_id INT UNSIGNED PRIMARY KEY,
    next_digest_at DATETIME NOT NULL,
    last_sent_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS support_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    mode ENUM('preview','support') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    ended_at DATETIME DEFAULT NULL,
    KEY idx_support_actor (actor_id,started_at),
    KEY idx_support_target (target_id,started_at),
    FOREIGN KEY (actor_id) REFERENCES admin_users(id),
    FOREIGN KEY (target_id) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by INT UNSIGNED NOT NULL,
    subject VARCHAR(180) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    status ENUM('draft','queued','completed','cancelled') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    queued_at DATETIME DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_campaign_recipients (
    campaign_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','sent','skipped','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    PRIMARY KEY (campaign_id,user_id),
    KEY idx_campaign_due (status,available_at),
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS auth_email_tokens (
    token_hash CHAR(64) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    purpose ENUM('verify_email','reset_password') NOT NULL,
    email VARCHAR(255) NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_auth_email_user (user_id, purpose),
    KEY idx_auth_email_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS miniature_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    miniature_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL DEFAULT NULL,
    body TEXT NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_comments_mini_created (miniature_id, created_at),
    KEY idx_comments_user (user_id),
    KEY idx_comments_parent (parent_id),
    KEY idx_comments_pinned (miniature_id, is_pinned, created_at),
    FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Notifications (social module — Phase 5; Phase 7 adds 'follow' + target_user_id)
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    type ENUM('comment', 'reply', 'mention', 'like', 'follow', 'registration', 'message') NOT NULL,
    -- miniature_id is NULL for notifications not tied to a miniature (e.g. follow).
    miniature_id INT UNSIGNED NULL,
    -- target_user_id references the user a non-miniature notification is about
    -- (e.g. the followed collector). Generic anchor for future social events.
    target_user_id INT UNSIGNED NULL,
    comment_id INT UNSIGNED NULL,
    target_url VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user_unread (user_id, is_read, created_at),
    KEY idx_notif_miniature (miniature_id),
    KEY idx_notif_target_user (target_user_id),
    KEY idx_notif_comment (comment_id),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES miniature_comments(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Miniature Likes (social module — Phase 6)
CREATE TABLE IF NOT EXISTS miniature_likes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    miniature_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mini_user (miniature_id, user_id),
    KEY idx_likes_miniature (miniature_id),
    KEY idx_likes_user (user_id),
    FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- User Follows (social module — Phase 7, Instagram-style one-way follow)
CREATE TABLE IF NOT EXISTS user_follows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    follower_id INT UNSIGNED NOT NULL,
    following_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_follow (follower_id, following_id),
    KEY idx_follow_following (following_id),
    KEY idx_follow_follower (follower_id),
    FOREIGN KEY (follower_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Persistent authentication and private messages
CREATE TABLE IF NOT EXISTS persistent_logins (
    selector CHAR(24) PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY (user_id),
    KEY (expires_at),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY inbox (recipient_id, is_read, id),
    KEY conversation (sender_id, recipient_id, id),
    FOREIGN KEY (sender_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limiting (brute force / mass-signup protection).
-- One row per bucket (e.g. 'login:ip:1.2.3.4', 'login:user:joe', 'register:ip:1.2.3.4').
-- Counter resets when the window expires; old rows are purged opportunistically.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(100) NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rate_limits_updated (updated_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- View analytics: one row per counted view (owner, bots and reloads excluded).
-- The visitor is stored only as a salted hash; the raw IP is never persisted.
CREATE TABLE IF NOT EXISTS miniature_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    miniature_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'direct',
    source_detail VARCHAR(100) NULL,
    country_code CHAR(2) NULL,
    region VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_views_dedupe (miniature_id, visitor_hash, created_at),
    KEY idx_views_owner (owner_id, created_at),
    FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- IP geolocation cache, keyed by salted IP hash, to avoid repeat lookups.
CREATE TABLE IF NOT EXISTS geo_cache (
    ip_hash CHAR(64) NOT NULL PRIMARY KEY,
    country_code CHAR(2) NULL,
    region VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Default data
INSERT IGNORE INTO categories (name)
VALUES ('Muscle Cars'),
    ('JDM'),
    ('Sports Cars'),
    ('Trucks & SUVs'),
    ('Classic Cars'),
    ('F1 & Racing'),
    ('Motorcycles'),
    ('Vans & Wagons'),
    ('Exotics'),
    ('Others');
INSERT IGNORE INTO tags (name)
VALUES ('JDM'),
    ('Muscle'),
    ('Ferrari'),
    ('Rally'),
    ('Nacional'),
    ('F1'),
    ('Subaru'),
    ('Mitsubishi'),
    ('BMW'),
    ('Presente'),
    ('Relíquia'),
    ('Shell'),
    ('Limited'),
    ('Custom');
-- ─── Indexes ─────────────────────────────────────────────────────────────────
-- miniature_photos: compound index for the primary-photo JOIN pattern
CREATE INDEX idx_photos_miniature_primary ON miniature_photos (miniature_id, is_primary);
-- miniatures: columns used in filters and ORDER BY
CREATE INDEX idx_miniatures_created ON miniatures (created_at);
CREATE INDEX idx_miniatures_manufacturer ON miniatures (manufacturer);
CREATE INDEX idx_miniatures_scale ON miniatures (scale);
-- Per-collector scoping: every list/count filters by user_id (admin garage,
-- public collection, dashboard counts, export, stats).
CREATE INDEX idx_miniatures_user ON miniatures (user_id);
CREATE INDEX idx_categories_user ON categories (user_id);
CREATE INDEX idx_tags_user ON tags (user_id);
CREATE INDEX idx_wishlist_user ON wishlist (user_id);
ALTER TABLE miniatures
ADD FULLTEXT INDEX ft_miniatures_search (name, manufacturer, model);

CREATE TABLE IF NOT EXISTS email_notification_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(20) NOT NULL,
    source_key CHAR(64) NOT NULL,
    target_id INT UNSIGNED DEFAULT NULL,
    notification_id INT UNSIGNED DEFAULT NULL,
    status ENUM('pending','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_email_event (user_id,event_type,source_key),
    KEY idx_email_due (status,available_at),
    KEY idx_email_recipient (user_id,event_type,sent_at),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
