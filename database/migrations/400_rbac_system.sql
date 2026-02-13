-- Migration 400: RBAC (Role-Based Access Control) System
-- Creates roles, permissions, role_permissions, user_roles tables
-- Seeds default roles and permissions
-- MySQL 5.7 compatible

-- 1. Roles table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` int NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Permissions table
CREATE TABLE IF NOT EXISTS `permissions` (
    `id` int NOT NULL AUTO_INCREMENT,
    `key` varchar(100) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_perm_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Role-Permission mapping
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` int NOT NULL,
    `permission_id` int NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `idx_rp_role` (`role_id`),
    KEY `idx_rp_perm` (`permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. User-Role mapping
CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` int NOT NULL,
    `role_id` int NOT NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    KEY `idx_ur_user` (`user_id`),
    KEY `idx_ur_role` (`role_id`),
    CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
