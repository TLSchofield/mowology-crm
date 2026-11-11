-- ============================================================================
-- Migration 1119: Contract Terms & Conditions
-- ============================================================================
-- Until now a contract had no terms at all. The client signing page told them
-- "By signing below you agree to the terms of this service contract" and then
-- showed them nothing but the free-text `notes` field. For grounds maintenance
-- that is untidy; for snow and ice it is the whole risk position — the
-- slip-and-fall disclaimers ARE the protection, and a disclaimer the client
-- was never shown is the one you cannot lean on afterwards.
--
-- Three pieces:
--   1. contract_terms_templates — the reusable bodies, scoped globally or to a
--      service type, versioned so editing a template never rewrites history.
--   2. contracts.terms_template_id — which template this contract uses.
--   3. A SNAPSHOT of the body onto contract_versions + contract_signatures.
--      This is the load-bearing part. You must be able to prove what the client
--      agreed to on the day, not what the template happens to say now.
--
-- MySQL 5.7-safe: no JSON columns, no generated columns, no window functions,
-- utf8mb4_general_ci throughout to match existing FK collations.
-- ============================================================================

-- ── 1. Template library ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contract_terms_templates (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(160) NOT NULL,
    slug           VARCHAR(160) NOT NULL,
    -- 'global'       → the fallback when nothing more specific matches
    -- 'service_type' → matched against job_plans.service_type on the contract
    scope          ENUM('global','service_type') NOT NULL DEFAULT 'service_type',
    service_type   VARCHAR(60) DEFAULT NULL,
    -- Shown above the signature pad. Plain text, rendered with line breaks
    -- preserved — deliberately NOT html, so nothing user-supplied can inject.
    body           MEDIUMTEXT NOT NULL,
    -- Bumped by the service whenever `body` changes, so a snapshot can name
    -- exactly which revision was signed.
    version        INT UNSIGNED NOT NULL DEFAULT 1,
    -- Seasonal contracts must not roll into the spring. When set, creating a
    -- contract from this template forces auto_renew = 0 and pins the end date.
    season_start   VARCHAR(5) DEFAULT NULL,      -- 'MM-DD', e.g. '11-01'
    season_end     VARCHAR(5) DEFAULT NULL,      -- 'MM-DD', e.g. '03-31'
    forces_no_auto_renew TINYINT(1) NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    is_default     TINYINT(1) NOT NULL DEFAULT 0,
    sort_order     INT NOT NULL DEFAULT 0,
    created_by     INT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_terms_slug (slug),
    KEY idx_terms_scope (scope, service_type, is_active),
    KEY idx_terms_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 2. Which template a contract uses ───────────────────────────────────────
ALTER TABLE contracts
    ADD COLUMN terms_template_id INT DEFAULT NULL AFTER notes;

CREATE INDEX idx_contracts_terms_template ON contracts (terms_template_id);

-- ── 3. The snapshot ─────────────────────────────────────────────────────────
-- contract_versions already snapshots title/billing/dates at each amendment.
-- Terms belong in exactly the same place and for exactly the same reason.
ALTER TABLE contract_versions
    ADD COLUMN terms_template_id      INT DEFAULT NULL AFTER notes,
    ADD COLUMN terms_template_version INT UNSIGNED DEFAULT NULL AFTER terms_template_id,
    ADD COLUMN terms_body             MEDIUMTEXT DEFAULT NULL AFTER terms_template_version;

-- Proof of acknowledgement lives with the signature, not with the contract:
-- one contract can be signed more than once (re-send, amendment, decline then
-- re-sign) and each of those is a separate act of agreement.
ALTER TABLE contract_signatures
    ADD COLUMN terms_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER signature_data,
    ADD COLUMN terms_body_hash    VARCHAR(64) DEFAULT NULL AFTER terms_acknowledged;

-- ── 4. Seed ─────────────────────────────────────────────────────────────────
-- Snow & ice. The clauses below are the client's own contract wording; the
-- season window and the no-auto-renew flag are enforced in data because
-- "Nov 1 to Mar 31" is not a sentence, it is a billing boundary.
INSERT IGNORE INTO contract_terms_templates
    (name, slug, scope, service_type, version, season_start, season_end,
     forces_no_auto_renew, is_active, is_default, sort_order, body)
VALUES
(
    'Snow & Ice Management',
    'snow-ice',
    'service_type',
    'snow_removal',
    1,
    '11-01',
    '03-31',
    1, 1, 0, 10,
    'TERM

This contractual agreement is for the period of November 1st to March 31st. It may be terminated by either party with immediate effect should the Company no longer be able to service the agreement to the client, or should the client no longer wish to continue the service, and will be done so without costs, liabilities or penalties to either party.

SCOPE OF SERVICE

City and Property sidewalks only, and Car Park.

CONDITIONS AND LIMITATIONS

The Client is aware that shovelling or plowing may not clear the Location to bare pavement and that slippery conditions may prevail even after the provision of the Services by the Company.

The Client acknowledges that the Company cannot be reasonably expected to completely prevent snow or ice build-up on the Location.

The Company assumes no responsibility for slip and fall accidents or vehicular accidents as a result of icy or slippery conditions at the Location.

The Client acknowledges that icy and slippery conditions may persist even after the Services have been provided by the Company.

WEATHER MONITORING AND RESPONSE

The Company will monitor prevailing weather conditions and during heavy snowfall in areas serviced by the Company during any single snowfall, the Company shall use commercially reasonable efforts to perform the Services as quickly and safely as possible, however, no time deadlines or performance guarantees can be given.'
),
(
    'Grounds Maintenance (default)',
    'grounds-maintenance-default',
    'global',
    NULL,
    1,
    NULL,
    NULL,
    0, 1, 1, 100,
    'TERM

This agreement continues for the period stated above and renews automatically unless either party gives notice.

PAYMENT

Payment is due within 30 days of service completion. All prices include GST.

SERVICE DELIVERY

Work is carried out weather permitting. Where conditions prevent safe or effective work, the visit will be rescheduled to the next available date.'
);
