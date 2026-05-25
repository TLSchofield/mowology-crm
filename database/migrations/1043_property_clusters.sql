-- Migration 1043: Property cluster system
-- Adds three tables: property_clusters, property_cluster_members, cluster_sessions
-- These support the route-cluster cost apportionment feature.

-- Named, semi-permanent cluster groups (admin-defined or auto-detected)
CREATE TABLE property_clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    notes TEXT NULL,
    default_travel_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Drive time TO this cluster from previous stop',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which properties belong to each cluster
CREATE TABLE property_cluster_members (
    cluster_id INT NOT NULL,
    property_id INT NOT NULL,
    apportionment_override DECIMAL(5,2) NULL COMMENT 'NULL = auto-calculate from job_plans.estimated_duration_minutes',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (cluster_id, property_id),
    INDEX idx_property (property_id),
    FOREIGN KEY (cluster_id) REFERENCES property_clusters(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One session per cluster per day per crew — the shared parking-stop clock window
CREATE TABLE cluster_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cluster_id INT NOT NULL,
    session_date DATE NOT NULL,
    crew_id INT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    total_minutes INT NULL COMMENT 'Actual on-property minutes (excludes travel)',
    travel_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Actual or default travel minutes used for apportionment',
    time_source ENUM('gps','manual','photo_inferred') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cluster_date_crew (cluster_id, session_date, crew_id),
    INDEX idx_cluster (cluster_id),
    INDEX idx_date (session_date),
    INDEX idx_crew_date (crew_id, session_date),
    FOREIGN KEY (cluster_id) REFERENCES property_clusters(id) ON DELETE CASCADE,
    FOREIGN KEY (crew_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
