# Location-Aware Job Creation — Deployment Guide

## Overview

This guide covers deploying the location-aware job creation system to production. Both **Phase 1 (Kanban Clients)** and **Phase 2 (Location-Aware Jobs)** are implemented and ready.

---

## Prerequisites

- MySQL 5.7+ with spatial extensions enabled
- PHP 7.4+
- Google Maps API credentials (for reverse geocoding)
- SSH/cPanel access to mowology.ca hosting
- Database backups taken

---

## Deployment Steps

### Step 1: Backup Production Database

```bash
# SSH into server
ssh user@mowology.ca

# Create backup
mysqldump -u mowology_admin -p mowology_landscape_crm > /home/mowology/backups/pre-location-aware-$(date +%Y%m%d_%H%M%S).sql

# Verify backup
ls -lh /home/mowology/backups/ | tail -1
```

### Step 2: Apply Database Migrations

```bash
cd /home/mowology/public_html

# Apply Phase 1 migration (Lifecycle Stages)
mysql -u mowology_admin -p mowology_landscape_crm < database/migrations/021_add_lifecycle_stage_to_companies.sql

# Apply Phase 2 migration (Location Tracking)
mysql -u mowology_admin -p mowology_landscape_crm < database/migrations/022_location_aware_job_creation.sql

# Verify tables were created
mysql -u mowology_admin -p mowology_landscape_crm -e "
  SHOW TABLES LIKE '%lifecycle%';
  SHOW TABLES LIKE '%crew_location%';
  SHOW TABLES LIKE '%geocoding%';
  SHOW TABLES LIKE '%visit_pattern%';
"
```

Expected output:
```
Tables_in_mowology_landscape_crm (lifecycle%)
lifecycle_stages

Tables_in_mowology_landscape_crm (crew_location%)
crew_location_history

Tables_in_mowology_landscape_crm (geocoding%)
geocoding_cache

Tables_in_mowology_landscape_crm (visit_pattern%)
property_visit_patterns
```

### Step 3: Configure Google Maps API Key

```bash
# Edit secrets.php
nano /home/mowology/public_html/app_config/secrets.php
```

Add or update:
```php
<?php
// ... existing secrets ...

// Google Maps API Key for reverse geocoding
putenv('GOOGLE_MAPS_API_KEY=YOUR_API_KEY_HERE');
```

**To obtain API key**:
1. Visit [Google Cloud Console](https://console.cloud.google.com/)
2. Create new project: "Mowology Landscaping"
3. Enable APIs:
   - Geocoding API
   - Maps JavaScript API
4. Create API key (Restrict to HTTP referers)
5. Add domain: `mowology.ca`

### Step 4: Verify File Permissions

```bash
# Check directory permissions
ls -ld /home/mowology/public_html/crm/jobs/
ls -ld /home/mowology/public_html/crm/includes/

# Ensure writable by web server
chmod 755 /home/mowology/public_html/crm/jobs/
chmod 755 /home/mowology/public_html/crm/includes/

# Verify new files are accessible
curl https://mowology.ca/crm/jobs/location-job-creation.js -I
curl https://mowology.ca/crm/jobs/location-job-creation.css -I
```

### Step 5: Seed Service Packages (Optional but Recommended)

Ensure your `service_packages` table has records. Example:

```sql
INSERT INTO service_packages (
  package_name,
  package_key,
  description,
  base_price,
  default_duration_minutes,
  default_crew_size,
  icon,
  category,
  is_active
) VALUES
  ('Standard Mowing', 'mowing', 'Weekly/bi-weekly lawn mowing', 85.00, 60, 1, 'scissors', 'mowing', 1),
  ('Hedge Trimming', 'hedging', 'Hedge and shrub maintenance', 120.00, 90, 2, 'trim', 'trimming', 1),
  ('Garden Cleanup', 'cleanup', 'General garden cleanup and debris removal', 150.00, 120, 2, 'trash-2', 'cleanup', 1),
  ('Mulching', 'mulch', 'Mulch installation and bed maintenance', 200.00, 180, 3, 'layers', 'mulching', 1);
```

### Step 6: Test Endpoints

From local development machine:

```bash
# Test reverse geocoding
curl -X POST https://mowology.ca/crm/jobs/jobs_create_location_appstack.php?action=reverse_geocode \
  -H "Content-Type: application/json" \
  -d '{
    "latitude": 49.2827,
    "longitude": -123.1207
  }'

# Expected response should contain address
```

### Step 7: Update Application Links

Ensure the "Create Job" link in the CRM navigation points to the new location-aware page:

**Check**: `/crm/includes/appstack_sidebar.php`
```php
// Jobs link should navigate to jobs index/dashboard
['key' => 'jobs', 'label' => 'Jobs', 'icon' => 'briefcase', 'href' => '/crm/jobs/index.php'],
```

Add button in jobs dashboard pointing to new page:
```html
<a href="/crm/jobs/jobs_create_location_appstack.php" class="btn btn-primary">
  <i data-feather="map-pin"></i> Create Job (Location Aware)
</a>
```

### Step 8: Test Complete Workflow

1. **Access the page**:
   - Navigate to: `https://mowology.ca/crm/jobs/jobs_create_location_appstack.php`
   - Should load kanban UI with 5 workflow screens

2. **Test GPS flow**:
   - Allow geolocation permission when prompted
   - Should display location coordinates
   - Should reverse geocode to address
   - Should show nearby properties (if any exist with lat/lng)

3. **Test manual property creation**:
   - Select "Manual Address" option
   - Enter client name (test typeahead search)
   - Enter address
   - Create new property
   - Should link to client and proceed to job creation

4. **Test service selection**:
   - Select service package
   - Verify price and duration populate correctly
   - Review all details in confirm screen

5. **Test job creation**:
   - Submit form
   - Should redirect to job detail view
   - Verify job_number generated correctly
   - Verify activity log entry created

### Step 9: Monitor Initial Operations

```bash
# Watch error logs for issues
tail -f /home/mowology/logs/php-errors.log | grep -i "location\|geocod"

# Monitor database for location history entries
mysql -u mowology_admin -p mowology_landscape_crm -e "
  SELECT COUNT(*) as 'Total Location Records' FROM crew_location_history;
  SELECT COUNT(*) as 'Cached Geocodes' FROM geocoding_cache;
  SELECT COUNT(*) as 'Visit Patterns' FROM property_visit_patterns;
"

# Check for any database errors
mysql -u mowology_admin -p mowology_landscape_crm -e "
  SHOW ENGINE INNODB STATUS\G | grep -i error | head -5
"
```

---

## Rollback Plan

If issues occur, rollback is straightforward:

### Option 1: Disable Feature (Quickest)

Simply point jobs creation link back to old page:
```php
['href' => '/crm/jobs/create.php'], // or wherever old page is
```

### Option 2: Restore Database

```bash
# Restore from backup
mysql -u mowology_admin -p mowology_landscape_crm < /home/mowology/backups/pre-location-aware-*.sql

# Remove new PHP files
rm /home/mowology/public_html/crm/jobs/jobs_create_location_appstack.php
rm /home/mowology/public_html/crm/includes/location-functions.php
rm /home/mowology/public_html/crm/jobs/location-job-creation.js
rm /home/mowology/public_html/crm/jobs/location-job-creation.css
```

---

## Performance Tuning

For deployments with large property datasets (1000+ properties):

```sql
-- Add spatial index (if not already present from migration)
ALTER TABLE properties ADD SPATIAL INDEX idx_location (latitude, longitude);

-- Add indexes for common queries
CREATE INDEX idx_crew_location_timestamp ON crew_location_history(crew_id, timestamp);
CREATE INDEX idx_geocoding_expires ON geocoding_cache(expires_at);
CREATE INDEX idx_property_visit_property ON property_visit_patterns(property_id);

-- Monitor index usage
ANALYZE TABLE properties;
ANALYZE TABLE crew_location_history;
ANALYZE TABLE geocoding_cache;
```

---

## Monitoring & Maintenance

### Daily Checks
```bash
# Verify no error spike
tail -100 /home/mowology/logs/php-errors.log | grep -i location

# Check database size growth
mysql -u mowology_admin -p mowology_landscape_crm -e "
  SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
  FROM information_schema.TABLES
  WHERE table_schema = 'mowology_landscape_crm'
  AND table_name IN ('crew_location_history', 'geocoding_cache')
  ORDER BY size_mb DESC;
"
```

### Weekly Maintenance
```bash
# Clean old geocoding cache (older than 30 days)
mysql -u mowology_admin -p mowology_landscape_crm -e "
  DELETE FROM geocoding_cache WHERE expires_at < NOW();
  OPTIMIZE TABLE geocoding_cache;
"

# Archive old crew location history (optional - adjust retention policy as needed)
# DELETE FROM crew_location_history WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

---

## Troubleshooting Deployment Issues

### Issue: "Property type not found" error

**Cause**: The property_type value doesn't match expected enum values

**Solution**:
```sql
-- Check allowed values
SHOW FULL COLUMNS FROM properties WHERE Field = 'property_type';

-- Ensure values match: residential, commercial, industrial, other
```

### Issue: Geocoding always returns coordinates instead of address

**Cause 1**: Google Maps API key not set or invalid
```bash
# Verify in secrets.php
grep GOOGLE_MAPS_API_KEY /home/mowology/public_html/app_config/secrets.php
```

**Cause 2**: API quotas exceeded
- Check Google Cloud Console for quota usage
- May need to upgrade billing

### Issue: Nearby properties not appearing (always returns empty)

**Cause**: Properties lack latitude/longitude values
```sql
-- Check how many properties have coordinates
SELECT COUNT(*) as total FROM properties;
SELECT COUNT(*) as with_coords FROM properties WHERE latitude IS NOT NULL;

-- If count is low, may need to geocode existing properties first
```

### Issue: CSRF token errors

**Cause**: Meta tag not rendering or token not being read
```bash
# Test from browser console
console.log(document.querySelector('meta[name="csrf-token"]')?.content)
```

---

## Post-Deployment Verification

- [ ] Database migrations applied successfully
- [ ] Google Maps API key configured and working
- [ ] Kanban clients page displays all companies in lifecycle stages
- [ ] Drag-drop between kanban columns works
- [ ] Stage management modal works (add/edit/delete stages)
- [ ] Location-aware job creation page loads without errors
- [ ] GPS geolocation request works on mobile device
- [ ] Nearby properties display correctly
- [ ] New property creation works with client typeahead
- [ ] Service package selection displays icons and pricing
- [ ] Job creation redirects to job detail view
- [ ] Crew location history is logged
- [ ] Geocoding cache is being reused (not calling API every time)
- [ ] Activity log entries created for all actions
- [ ] No increase in error logs

---

## Support Contacts

- **Database Issues**: Database admin
- **API Issues**: Check Google Cloud Console
- **PHP/Code Issues**: Development team
- **Deployment Help**: DevOps / Server admin

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-02-08 | Initial release with Kanban + Location-Aware systems |

---

**Deployment Guide Version**: 1.0
**Status**: Ready for Production
**Last Updated**: 2026-02-08
