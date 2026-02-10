# Mowology CRM Implementation Checklist

## Phase 1: Client Kanban Management System ✅

### Database
- [x] Migration `021_add_lifecycle_stage_to_companies.sql` created
  - [x] Added `lifecycle_stage` column to companies table
  - [x] Created `lifecycle_stages` lookup table
  - [x] Inserted 4 default stages: prospect, qualified, client, inactive

### PHP Backend
- [x] `/crm/includes/functions.php` extended with:
  - [x] `getLifecycleStages()` - Fetch all active stages
  - [x] `getCompaniesByLifecycleStage()` - Group companies by lifecycle stage
  - [x] `updateCompanyLifecycleStage($companyId, $newStage, $userId)` - Move company between stages
  - [x] `addLifecycleStage($data)` - Create new custom stages
  - [x] `updateLifecycleStage($stageId, $data)` - Modify existing stages
  - [x] `deleteLifecycleStage($stageId)` - Delete stages with validation

### Frontend Updates
- [x] `/crm/clients_appstack.php` modified to:
  - [x] Add AJAX endpoint `?action=move_company` for drag-drop updates
  - [x] Add AJAX endpoint `?action=manage_stages` for stage CRUD
  - [x] Replace table view with 4-column kanban display
  - [x] Implement drag-drop functionality with visual feedback
  - [x] Add "Manage Stages" modal with add/edit/delete forms
  - [x] All operations CSRF-protected and activity-logged

### Styling
- [x] `/crm/css/mowology-brand.css` extended with:
  - [x] `.mw-kanban-*` classes for kanban layout
  - [x] Responsive design for mobile (stacked on <768px)
  - [x] Color-coded column headers using stage colors
  - [x] Smooth drag-drop animations

### Testing Required
- [ ] Apply migration 021 to database
- [ ] Verify kanban columns display all companies correctly
- [ ] Test drag-drop between columns
- [ ] Verify stage management modal functionality
- [ ] Confirm activity log entries are created for all stage changes
- [ ] Test on mobile/tablet for responsive layout

---

## Phase 2: Location-Aware Job Creation System ⚠️ PENDING

### Database
- [ ] Apply Migration `022_location_aware_job_creation.sql`
  - [ ] Add `latitude`, `longitude`, `location_verified_at`, `location_verified_by` to properties table
  - [ ] Create SPATIAL INDEX on properties (latitude, longitude)
  - [ ] Create `crew_location_history` table
  - [ ] Create `geocoding_cache` table
  - [ ] Create `property_visit_patterns` table

### PHP Backend
- [x] `/crm/includes/location-functions.php` created with:
  - [x] `findNearbyProperties($crewLat, $crewLng, $radiusKm)` - Haversine formula query
  - [x] `reverseGeocodeLocation($lat, $lng)` - Google Maps reverse geocoding with cache
  - [x] `checkPropertyNearby($lat, $lng, $toleranceMeters)` - Duplicate detection
  - [x] `createPropertyFromLocation($address, $lat, $lng, $clientId, $propertyType, $userId)` - Property creation
  - [x] `logCrewLocation($lat, $lng, $jobId)` - Location history tracking
  - [x] `updatePropertyVisitPattern($propertyId, $crewId, $durationMinutes)` - Visit pattern learning
  - [x] `getRecentJobsForProperty($propertyId, $limit)` - Quick-repeat jobs
  - [x] `createJobFromLocationData(...)` - Job creation with defaults

- [x] `/crm/jobs/jobs_create_location_appstack.php` created with:
  - [x] AJAX endpoint `?action=find_nearby_properties` (POST JSON)
  - [x] AJAX endpoint `?action=reverse_geocode` (POST JSON)
  - [x] AJAX endpoint `?action=create_property_from_location` (POST form)
  - [x] AJAX endpoint `?action=get_property_summary` (POST JSON)
  - [x] AJAX endpoint `?action=search_clients` (POST JSON)
  - [x] AJAX endpoint `?action=log_crew_location` (POST JSON)
  - [x] POST handler for job creation
  - [x] All endpoints CSRF-protected, error-handled, transaction-safe
  - [x] Fixed: Added `require_once` for location-functions.php
  - [x] Fixed: Added CSRF token meta tag in `$extraHead`
  - [x] Fixed: Added CSS link in `$extraHead`

### Frontend Components
- [x] `/crm/jobs/location-job-creation.js` created:
  - [x] Vue.js 2.6 single-component app with 5-screen workflow
  - [x] Screen 1: Location detection (GPS + manual fallback)
  - [x] Screen 2: Property selection (3 nearest, sorted by distance)
  - [x] Screen 3: New property creation (with client typeahead)
  - [x] Screen 4: Service selection (recent + browse all)
  - [x] Screen 5: Confirm job (review all details, schedule)
  - [x] Geolocation API integration with error handling
  - [x] Real-time typeahead for client search
  - [x] Reverse geocoding integration
  - [x] All fetch calls use CSRF tokens from meta tag
  - [x] Redirects to job detail view after creation

- [x] `/crm/jobs/location-job-creation.css` created:
  - [x] Mobile-first responsive design
  - [x] `.mw-screen` containers for each workflow step
  - [x] `.mw-property-item` flex layout for property cards
  - [x] `.mw-service-grid` responsive grid (2 col on mobile, 3+ on desktop)
  - [x] `.mw-quick-repeat-grid` auto-fit grid for recent jobs
  - [x] `.mw-typeahead` and `.mw-suggestions` for client search dropdown
  - [x] Touch-friendly sizing (48px minimum for buttons)
  - [x] Smooth transitions and hover effects

### Configuration & Environment
- [ ] **CRITICAL**: Set `GOOGLE_MAPS_API_KEY` in `/app_config/secrets.php`
  - [ ] Obtain Google Maps API key from Google Cloud Console
  - [ ] Enable: Geocoding API, Maps JavaScript API
  - [ ] Restrict key to HTTP referers (mowology.ca)

### Testing Required
- [ ] Apply migration 022 to database
- [ ] Verify spatial indexes created successfully
- [ ] Test GPS geolocation permission flow
  - [ ] Grant permission: Nearby properties should display
  - [ ] Deny permission: Manual address fallback should work
- [ ] Test nearby property detection (Haversine formula accuracy)
- [ ] Test reverse geocoding (Google Maps integration)
- [ ] Test new property creation at GPS coordinates
- [ ] Test client typeahead search
- [ ] Test service package selection
- [ ] Test job creation and redirect to job detail view
- [ ] Verify crew_location_history entries are logged
- [ ] Verify geocoding_cache entries are created and reused
- [ ] Performance test with large property dataset (1000+ properties)
- [ ] Mobile device testing for GPS accuracy and UX

### Known Limitations & Considerations
- GPS accuracy depends on device hardware (typical ±5-50 meters)
- Haversine formula assumes spherical Earth (accuracy ±0.5% at equator)
- Google Maps API calls are cached for 30 days to reduce costs
- Nearby property radius defaults to 1km but is adjustable
- System assumes properties table has populated latitude/longitude values
- Service packages must be seeded in database with defaults

---

## Required Next Steps

### Immediate (Before Going Live)
1. **Database Migrations**
   ```bash
   mysql mowology_landscape_crm < database/migrations/021_add_lifecycle_stage_to_companies.sql
   mysql mowology_landscape_crm < database/migrations/022_location_aware_job_creation.sql
   ```

2. **Environment Configuration**
   ```php
   // /app_config/secrets.php
   putenv('GOOGLE_MAPS_API_KEY=your_key_here');
   ```

3. **Verify Service Packages**
   - Ensure `service_packages` table has records with:
     - `package_name` (display name)
     - `base_price` (default pricing)
     - `default_duration_minutes` (time estimate)
     - `default_crew_size` (crew requirement)
     - `default_billing_template_id` (invoice template)
     - `icon` (Feather icon name for UI)
     - `category` (service grouping)

### Testing Phase
1. Unit test all location functions with mock data
2. Integration test full workflow on staging environment
3. Mobile device testing (iOS Safari, Android Chrome)
4. Performance testing with realistic dataset
5. Accessibility testing (WCAG 2.1 AA compliance)

### Optional Enhancements (Future)
- [ ] Implement route optimization (nearest multiple properties)
- [ ] Add property visit analytics dashboard
- [ ] Implement crew mobile app integration for proof-of-work
- [ ] Add crew real-time tracking/live map
- [ ] Implement machine-learning for property visit prediction
- [ ] Add offline capability for crew mobile app
- [ ] Implement recurring job scheduling from location-aware creation

---

## File Structure Summary

### Phase 1 Files
```
database/migrations/
  └─ 021_add_lifecycle_stage_to_companies.sql

public/crm/
  ├─ includes/
  │   └─ functions.php (8 new functions)
  ├─ clients_appstack.php (modified)
  └─ css/
      └─ mowology-brand.css (kanban styles added)
```

### Phase 2 Files
```
database/migrations/
  └─ 022_location_aware_job_creation.sql

public/crm/
  ├─ includes/
  │   └─ location-functions.php (8 new functions)
  ├─ jobs/
  │   ├─ jobs_create_location_appstack.php (new)
  │   ├─ location-job-creation.js (new)
  │   └─ location-job-creation.css (new)
  └─ css/
      └─ mowology-brand.css (no new styles - CSS is modular)
```

---

## Support & Debugging

### Common Issues

**Issue**: Jobs not appearing in nearby properties
- **Cause**: Properties lack latitude/longitude values
- **Solution**: Populate properties table with GPS coordinates or manually set

**Issue**: Google Maps API error 403 (Forbidden)
- **Cause**: API key not set or invalid
- **Solution**: Verify `GOOGLE_MAPS_API_KEY` in secrets.php

**Issue**: Geocoding cache not working
- **Cause**: Database connection issue
- **Solution**: Check database logs for INSERT errors

**Issue**: Drag-drop not working on clients page
- **Cause**: Missing JavaScript dependencies
- **Solution**: Verify Feather icons and Bootstrap 4 are loaded

---

## Deployment Checklist

- [ ] All database migrations applied
- [ ] Google Maps API key configured
- [ ] Service packages seeded with required defaults
- [ ] CSS files linked in page includes
- [ ] JavaScript files accessible via web server
- [ ] CSRF token generation enabled in auth.php
- [ ] Activity logging tables have write permissions
- [ ] Backups taken before applying migrations
- [ ] Staging environment tested end-to-end
- [ ] Production deployment scheduled for low-traffic window

---

**Last Updated**: 2026-02-08
**Status**: Phase 1 Complete ✅ | Phase 2 Ready for Deployment ⏳
