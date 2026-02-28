-- Migration 400: RBAC (Role-Based Access Control) System
-- Creates roles, permissions, role_permissions, user_roles tables
-- Seeds default roles, permissions, and role-permission mappings
-- Assigns existing admin users the admin RBAC role
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

-- 5. Seed roles
INSERT IGNORE INTO `roles` (`name`, `description`) VALUES
    ('admin',   'Full system access — all permissions'),
    ('manager', 'Manages jobs, quotes, invoices, schedule, team — no system config'),
    ('staff',   'Field worker — view schedule, clock in/out, upload photos'),
    ('viewer',  'Read-only access — view schedule and jobs');

-- 6. Seed permissions
INSERT IGNORE INTO `permissions` (`key`, `description`) VALUES
    ('users.manage',      'Create, edit, deactivate users and assign roles'),
    ('roles.manage',      'Create and modify roles and permission mappings'),
    ('jobs.view',         'View job list and job details'),
    ('jobs.edit',         'Create, update, and delete jobs'),
    ('jobs.assign',       'Assign crew members to jobs'),
    ('schedule.view',     'View the schedule calendar'),
    ('schedule.edit',     'Create, move, and cancel scheduled visits'),
    ('timer.start',       'Clock in / start job timer'),
    ('timer.stop',        'Clock out / stop job timer'),
    ('timer.override',    'Edit or override time entries for any user'),
    ('photos.upload',     'Upload job photos and media'),
    ('photos.delete',     'Delete photos and media files'),
    ('billing.view',      'View quotes and invoices'),
    ('billing.edit',      'Create, edit, send quotes and invoices'),
    ('marketing.view',    'View marketing recommendations and SEO data'),
    ('marketing.edit',    'Apply SEO changes, manage CMS pages and content'),
    ('clients.view',      'View contact and company records'),
    ('clients.edit',      'Create and edit contacts and companies'),
    ('products.view',     'View products and pricing'),
    ('products.edit',     'Edit products, pricing rules, cost factors'),
    ('portfolio.view',    'View portfolio projects'),
    ('portfolio.edit',    'Create and edit portfolio projects'),
    ('settings.view',     'View system settings'),
    ('settings.edit',     'Modify system and business settings'),
    ('database.manage',   'Run migrations, view schema, database tools'),
    ('diagnostics.view',  'Access diagnostics and debug tools'),
    ('team.view',         'View team member list'),
    ('team.edit',         'Edit team member details and assignments');

-- 7. Seed role-permission mappings
-- Admin gets ALL permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'admin';

-- Manager gets most permissions (except users.manage, roles.manage, database.manage, diagnostics.view)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.`key` IN (
        'jobs.view','jobs.edit','jobs.assign',
        'schedule.view','schedule.edit',
        'timer.start','timer.stop','timer.override',
        'photos.upload','photos.delete',
        'billing.view','billing.edit',
        'marketing.view','marketing.edit',
        'clients.view','clients.edit',
        'products.view','products.edit',
        'portfolio.view','portfolio.edit',
        'team.view','team.edit',
        'settings.view'
    )
    WHERE r.name = 'manager';

-- Staff gets field-worker permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.`key` IN (
        'jobs.view',
        'schedule.view',
        'timer.start','timer.stop',
        'photos.upload',
        'clients.view',
        'products.view',
        'portfolio.view',
        'team.view'
    )
    WHERE r.name = 'staff';

-- Viewer gets read-only permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.`key` IN (
        'jobs.view',
        'schedule.view',
        'clients.view',
        'billing.view',
        'products.view',
        'portfolio.view',
        'team.view'
    )
    WHERE r.name = 'viewer';

-- 8. Assign existing admin users the admin RBAC role
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
    SELECT u.id, r.id FROM users u CROSS JOIN roles r
    WHERE u.role = 'admin' AND u.is_active = 1 AND r.name = 'admin';

-- 9. Assign existing staff/technician users the staff RBAC role
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
    SELECT u.id, r.id FROM users u CROSS JOIN roles r
    WHERE u.role IN ('staff','technician') AND u.is_active = 1 AND r.name = 'staff';

-- 10. Assign existing 'user' role users the viewer RBAC role
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
    SELECT u.id, r.id FROM users u CROSS JOIN roles r
    WHERE u.role = 'user' AND u.is_active = 1 AND r.name = 'viewer';
