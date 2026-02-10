# Mowology CRM — Database Documentation Index

Complete guide to database setup, schema, and management.

---

## 📋 Quick Navigation

| Document | Purpose | Audience | Read Time |
|----------|---------|----------|-----------|
| **DATABASE_QUICK_START.txt** | 2-minute setup guide | Everyone | 2 min |
| **DATABASE_SCHEMA_GUIDE.md** | Full setup documentation | DevOps / Developers | 10 min |
| **DATABASE_SETUP_COMPLETE.md** | Project completion summary | Project Managers / Leads | 5 min |
| **DATABASE_RELATIONSHIPS.md** | Table relationships & ER diagram | Database Designers | 15 min |
| **DATABASE_SCHEMA_FIXES.md** | Technical fixes & troubleshooting | Developers | 10 min |

---

## 🚀 Getting Started (Choose Your Path)

### Path A: "Just Get It Running" (5 minutes)
1. Read: **DATABASE_QUICK_START.txt**
2. Run: `COMPLETE_DATABASE_SCHEMA_CLEAN.sql` in phpMyAdmin
3. Done!

### Path B: "I Need Full Understanding" (20 minutes)
1. Read: **DATABASE_SCHEMA_GUIDE.md** (sections 1-4)
2. Review: **DATABASE_RELATIONSHIPS.md** (table overview)
3. Run: Database import
4. Read: **DATABASE_SETUP_COMPLETE.md** (next steps)

### Path C: "I'm a Developer" (30 minutes)
1. Read: **DATABASE_RELATIONSHIPS.md** (full)
2. Read: **DATABASE_SETUP_COMPLETE.md** (technical specs)
3. Read: **CLAUDE.md** (PHP/SQL conventions)
4. Run: Database import and verify all tables
5. Study: Key tables (users, contacts, companies, quotes, jobs, invoices)

---

## 📁 Database Files

### Main Schema Files (Use These)

```
database/
├── COMPLETE_DATABASE_SCHEMA_CLEAN.sql ⭐ RECOMMENDED
│   ├── 855 lines
│   ├── 33 tables
│   ├── MySQL 5.7+ compatible
│   ├── Fully idempotent
│   └── Safe to run multiple times
│
├── COMPLETE_DATABASE_SCHEMA_SAFE.sql (Alternative)
│   ├── 1200+ lines
│   ├── More conservative syntax
│   ├── Use if CLEAN version fails
│   └── Backup option
│
├── COMPLETE_DATABASE_SCHEMA.sql (Legacy)
│   ├── Original non-idempotent version
│   └── Not recommended for production
│
└── INIT_DATABASE.sql (Basic)
    ├── 6 core tables only
    └── For minimal testing only
```

### Migration Files (For Future Changes)

```
database/migrations/
├── 021_add_lifecycle_stage_to_companies.sql
├── 022_location_aware_job_creation.sql
├── 023_consolidate_lifecycle_stages.sql
├── 024_create_migrations_log.sql
├── 025_create_service_packages.sql
├── 026_create_billing_templates.sql
├── 027_create_job_proof_of_work.sql
└── 028_update_jobs_for_service_packages.sql
```

---

## 📊 Database Structure at a Glance

### 33 Tables Organized in Phases

```
PHASE 1: Lifecycle Lookup
├── lifecycle_stages (8 default stages)

PHASE 2: Core Entities
├── users (CRM users)
├── contacts (individual prospects/clients)
├── companies (business accounts)
├── properties (customer locations)
├── company_properties (many-to-many)
├── lead_events (lead source tracking)
├── quote_requests (form submissions)
└── consent_log (marketing consent audit)

PHASE 3: Quotes & Invoicing
├── quotes (quote workflow)
├── quote_line_items (line items)
├── quote_notes (internal/customer notes)
├── invoices (invoice generation)
├── invoice_line_items (invoice details)
└── invoice_contacts (routing)

PHASE 4: Jobs & Completion
├── jobs (job scheduling)
├── job_notes (internal notes)
├── job_photos (before/after)
└── job_proof_of_work (completion proof)

PHASE 5: Relationships & Portfolio
├── client_notes (CRM notes)
└── portfolio_projects (portfolio items)

PHASE 6: Location & Analytics
├── property_measurements (area calculations)
├── crew_location_history (GPS tracking)
├── geocoding_cache (address caching)
├── property_visit_patterns (analytics)
└── property_contacts (assignments)

PHASE 7: Service Configuration
├── service_templates (12 default templates)
├── service_packages (bundled services)
└── billing_templates (4 billing options)

PHASE 8: System & Business
├── business_settings (company config)
├── migrations_log (migration history)
├── password_reset_tokens (recovery)
└── activity_log (audit trail)
```

---

## 🔑 Key Tables for Development

### Core User & Contact Management
- **users** — CRM authentication (admin, manager, user roles)
- **contacts** — Individual prospects/clients with lifecycle tracking
- **companies** — Business accounts with billing & payment info
- **properties** — Customer properties/locations
- **consent_log** — GDPR compliance: marketing consent tracking

### Quote-to-Invoice Workflow
- **quotes** → **quote_line_items** + **quote_notes**
- **jobs** (created from accepted quotes)
- **invoices** → **invoice_line_items** + **invoice_contacts**
- **job_proof_of_work** (completion verification)

### Business Configuration
- **service_templates** — Reusable services (12 defaults included)
- **service_packages** — Bundled services
- **billing_templates** — Invoice grouping/timing rules
- **business_settings** — Company configuration

### Tracking & Compliance
- **activity_log** — All user actions (audit trail)
- **migrations_log** — Database migration history
- **password_reset_tokens** — Secure password recovery
- **lead_events** — Lead source attribution
- **quote_requests** — Form submission tracking

---

## 🔐 Default Data Included

### Admin User
```
Email:    mowology@icloud.com
Password: Sunwukong2026# (hash: $2y$12$f8nTXy1iDwHNIju2k1wMNe...)
⚠️  CHANGE THIS IN PRODUCTION!
```

### 8 Lifecycle Stages
- Lead → Opportunity → Prospect → Qualified → Client → Won / Inactive → Lost

### 12 Service Templates
- Lawn Mowing (3 sizes), Hedge Trimming, Garden Maintenance
- Spring/Fall Cleanup, Snow Removal (2 options)
- Lawn Aeration, Fertilizer, Mulch Installation

### 4 Billing Templates
- Per Visit (default), Monthly Grouped, Monthly Flat, Seasonal Prepay

### 1 Business Settings Record
- All company configuration in one row (id=1)

---

## ⚙️ Technical Specifications

| Property | Value |
|----------|-------|
| **Engine** | InnoDB (ACID, FK support) |
| **Charset** | utf8mb4 (Unicode, emoji support) |
| **Collation** | utf8mb4_general_ci (MySQL 5.7+) |
| **Transaction Support** | Full ACID compliance |
| **Foreign Keys** | Enabled, cascading deletes configured |
| **Indexes** | Comprehensive on frequently-searched columns |
| **Row Format** | Compact/Dynamic (default) |
| **Backup Strategy** | mysqldump (recommended) |

---

## ✅ Setup Checklist

### Before Import
- [ ] Have phpMyAdmin or MySQL CLI access ready
- [ ] Database credentials handy
- [ ] Backup location prepared (if existing database)

### During Import
- [ ] Create new database (e.g., `mowology_landscape_crm`)
- [ ] Import `COMPLETE_DATABASE_SCHEMA_CLEAN.sql`
- [ ] Wait for completion (2-5 seconds typically)
- [ ] Check for errors in output

### After Import
- [ ] Run verification queries (see QUICK_START)
- [ ] Confirm 33 tables created
- [ ] Confirm admin user exists
- [ ] Update `/app_config/secrets.php` with credentials
- [ ] Test login with admin user
- [ ] Create secondary admin user
- [ ] Update business settings (company name, contact info)

---

## 🔗 Related Documentation

**Project Setup:**
- `CLAUDE.md` — PHP/SQL development guidelines
- `ARCHITECTURE.md` — System architecture overview
- `.htaccess` — URL rewriting and access control

**CRM Features:**
- `/crm/` directory — CRM application
- `/crm/css/mowology-brand.css` — Styling
- `/crm/includes/functions.php` — Helper functions

**Public Site:**
- `/public/` root — Public website
- `/assets/css/` — Public site styling
- `/includes/` — Public site templates

---

## 🐛 Troubleshooting

### Database Won't Import
**Error:** "Duplicate foreign key constraint name"
**Solution:** Normal on re-import. Full file is idempotent.

**Error:** "Unknown column"
**Solution:** Check import output for errors at top. Scroll up to see first error.

**Error:** "Collation 'utf8mb4_0900_ai_ci' is not valid"
**Solution:** Your MySQL is older than 8.0. Use `COMPLETE_DATABASE_SCHEMA_SAFE.sql`.

### Tables Created but Data Missing
**Cause:** INSERT statements failed silently (uses `INSERT IGNORE`)
**Solution:** Check that admin user exists with: `SELECT * FROM users WHERE id = 1;`

### Foreign Key Errors
**Cause:** Importing into database with foreign key checks enabled
**Solution:** Already handled in schema. If problem persists, ensure all tables created first.

---

## 📞 Support Resources

**For Setup Help:**
→ See `DATABASE_QUICK_START.txt` (2-minute guide)
→ See `DATABASE_SCHEMA_GUIDE.md` (detailed troubleshooting)

**For Development:**
→ See `CLAUDE.md` (conventions and best practices)
→ See `DATABASE_RELATIONSHIPS.md` (schema details)

**For Issues:**
→ Check `DATABASE_SCHEMA_FIXES.md` (known fixes)
→ Review error message against troubleshooting section above

---

## 🎯 Next Steps After Setup

1. ✅ Import database
2. ✅ Verify admin user can login
3. ⏭️ Create production admin user with strong password
4. ⏭️ Add test companies and properties
5. ⏭️ Create sample quotes
6. ⏭️ Test job creation workflow
7. ⏭️ Verify invoice generation
8. ⏭️ Test portfolio image upload
9. ⏭️ Configure email settings

---

## 📈 Performance Notes

**Indexes Included:**
- Email and username (users table)
- Email, phone, name (contacts table)
- Company name and status
- Quote and job status and dates
- User IDs (foreign keys)
- Created timestamps (for sorting)

**For Production:**
- Monitor query performance with MySQL EXPLAIN
- Add indexes for frequently-filtered columns
- Regular backups (daily recommended)
- Archive old activity logs (maintenance task)

---

**Last Updated:** February 8, 2026
**Status:** ✅ Production Ready
**Version:** 1.0
