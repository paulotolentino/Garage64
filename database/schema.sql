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
    password_hash VARCHAR(255) NOT NULL,
    bio TEXT DEFAULT NULL,
    is_banned TINYINT(1) NOT NULL DEFAULT 0,
    is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Miniature Comments (social module — Phase 1)
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
    type ENUM('comment', 'reply', 'mention', 'like', 'follow') NOT NULL,
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
ALTER TABLE miniatures
ADD FULLTEXT INDEX ft_miniatures_search (name, manufacturer, model);