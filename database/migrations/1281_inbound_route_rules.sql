-- Migration 1281: "Move to..." teaches who owns a sender's mail
-- Date: 2026-10-08
-- Purpose: when Tim moves an inbound message to another head (Penny / Sam / Otto / Mia / Yui),
--   InboundRouteService remembers sender + topic -> head here, so the next similar email from
--   that sender goes straight to the right head. sender_key is the lowercase address
--   (alena@vml.ca) or '@' + the domain (@vml.ca) for business domains; the address rule wins.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS inbound_route_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_key VARCHAR(190) NOT NULL       COMMENT 'lowercase address, or @domain',
  topic VARCHAR(12) NOT NULL             COMMENT 'billing | sales | schedule | marketing | general',
  head VARCHAR(10) NOT NULL              COMMENT 'penny | sam | otto | mia | yui',
  hits INT NOT NULL DEFAULT 1            COMMENT 'How many moves taught it',
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_irr_sender_topic (sender_key, topic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Learned inbound routing: sender + topic -> department head';
