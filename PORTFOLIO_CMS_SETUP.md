# Portfolio CMS Setup & Deployment Guide

## Overview

The Portfolio CMS is a fully integrated content management system within the Mowology CRM dashboard. It allows staff to create, edit, delete, and manage portfolio projects that are displayed on the public website.

## Quick Start

### 1. Create the Database Table

Run the migration to create the `portfolio_projects` table:

```bash
# Via cPanel phpMyAdmin:
# 1. Go to Databases > phpMyAdmin
# 2. Select your Mowology database (e.g., mowology_landscape_crm)
# 3. Go to SQL tab
# 4. Copy & paste contents of: database/migrations/013_portfolio_projects_table.sql
# 5. Click "Go"

# OR via command line:
mysql -u [username] -p [database_name] < database/migrations/013_portfolio_projects_table.sql
```

**Expected output:** Table created successfully (no errors)

### 2. Verify CRM Access

- Navigate to **https://mowology.ca/crm/dashboard_appstack.php** (with login)
- Check sidebar — you should see **"Portfolio"** nav item (with image icon)
- Click "Portfolio" to go to list view

### 3. Add Your First Project

1. Click **"+ Add Project"** button
2. Fill in required fields:
   - **Project Name** (required)
   - **Location** (optional, e.g., "Vancouver, BC")
   - **Description** (optional, detailed project info)
   - **Service Categories** (multi-select: Strata, Residential, Maintenance, Design & Installation)
   - **Status** (Draft or Published)
   - **Featured** (checkbox to prioritize on public site)
   - **Display Order** (lower numbers appear first)
3. Click **"Create Project"**
4. Project detail page opens

### 4. Test Public Portfolio

- Navigate to **https://mowology.ca/portfolio.php**
- Projects with "Published" status should appear
- Filter buttons should work
- Featured projects appear at top

## File Locations

### CRM Module
```
/public/crm/portfolio/
├── index.php          # List view (with filters, stats)
├── create.php         # Create/Edit form (handles both operations)
├── view.php           # Project detail view
├── edit.php           # Redirect to create.php (keeps URLs clean)
└── delete.php         # Delete handler with safety checks
```

### Database & Configuration
```
/database/migrations/013_portfolio_projects_table.sql    # Schema
/public/crm/includes/functions.php                       # Portfolio helper functions
/public/crm/css/mowology-brand.css                      # Portfolio styles (added)
/public/crm/includes/appstack_sidebar.php                # Navigation (updated)
```

### Public Site
```
/public/portfolio.php    # Updated to pull from database
/public/assets/css/pages/portfolio.css    # Existing styles (unchanged)
```

## Database Schema

### Table: `portfolio_projects`

| Column | Type | Notes |
|--------|------|-------|
| `id` | int | Primary key, auto-increment |
| `project_number` | varchar(20) | Unique, format: PORT-YYYY-NNNN (auto-generated) |
| `project_name` | varchar(255) | Project title (required) |
| `description` | text | Full project details |
| `location` | varchar(255) | Project location/address |
| `status` | enum('draft', 'published') | Publication status (default: 'draft') |
| `featured` | boolean | Prioritize on public site (default: false) |
| `display_order` | int | Sort order, lower first (default: 999) |
| `categories` | json | Array of category strings (e.g., ["Residential", "Design & Installation"]) |
| `before_image_path` | varchar(512) | Path to before image |
| `after_image_path` | varchar(512) | Path to after image |
| `gallery_images` | json | Array of gallery image paths (future use) |
| `created_by` | int | User ID of creator |
| `created_at` | timestamp | Auto-set to NOW() |
| `updated_at` | timestamp | Auto-updated |

## Helper Functions

All located in `/public/crm/includes/functions.php`:

### `generateProjectNumber()`
Generates unique project numbers in format `PORT-YYYY-NNNN`.

### `getPortfolioProjects($status = '', $featuredOnly = false, $limit = 100)`
Query projects with optional filters.
- `$status`: 'draft', 'published', or empty for all
- `$featuredOnly`: true to return only featured projects
- Returns: array of project records

### `getPortfolioProject($projectId)`
Get a single project by ID.

### `createPortfolioProject($data)`
Create new project. Auto-generates `project_number` and `created_at`.

### `updatePortfolioProject($projectId, $data)`
Update specific fields. Auto-updates `updated_at`.

### `deletePortfolioProject($projectId)`
Safely delete a project.

### `getPortfolioProjectsByCategory()`
Get all published projects grouped by category.

### `parseGalleryImages($galleryJson)`
Parse JSON gallery array into PHP array.

## Features

### CRM Dashboard
- ✅ **List View** with status filters, search, and action buttons
- ✅ **Create/Edit Form** with multi-step validation
- ✅ **Project Detail Page** with metadata and quick actions
- ✅ **Delete Handler** with safety checks
- ✅ **Activity Logging** (integrated with existing system)
- ✅ **Stats Dashboard** showing total, published, and draft counts

### Public Site
- ✅ **Dynamic Display** pulling from database
- ✅ **Category Filtering** (All Projects, Strata, Residential, etc.)
- ✅ **Featured Projects** appear at top
- ✅ **Before/After Images** displayed with fallback placeholders
- ✅ **Responsive Grid** (existing CSS preserved)
- ✅ **Graceful Fallback** if database unavailable

### Data Management
- ✅ **Project Numbers** auto-generated (PORT-YYYY-NNNN)
- ✅ **Categories** stored as JSON array (flexible, no separate table)
- ✅ **Images** support before/after and gallery (paths stored, files uploaded separately)
- ✅ **Status Tracking** (draft until published)
- ✅ **Display Order** control
- ✅ **Timestamps** for audit trail

## Styling

Portfolio module uses Mowology brand tokens (`--mw-*` variables):

```css
--mw-green:  #2D8659   (primary buttons, links)
--mw-dark:   #1A5F4A   (hover states)
--mw-lime:   #7FD858   (active nav, accents)
--mw-light:  #E8F3F0   (light backgrounds)
--mw-forest: #0D3B2E   (sidebar, deepest dark)
--mw-orange: #e85d04   (secondary CTA)
```

Key CSS classes added to `mowology-brand.css`:
- `.mw-page-header` — Page title area
- `.mw-stats-row`, `.mw-stat-card` — Dashboard statistics
- `.mw-detail-grid`, `.mw-detail-row` — Metadata display
- `.mw-form-*` — Form styling
- `.mw-image-upload-area` — Image upload zones

All styles follow existing AppStack patterns.

## Image Uploads

Current implementation shows `image_path` fields for storing image URLs/paths. To implement actual file uploads:

1. Create `/public/uploads/portfolio/` directory
2. Update `create.php` form to handle file uploads
3. Create API endpoint for image processing
4. Store paths in database

**Note:** Image upload handlers not included in this version — add as needed for your workflow.

## Category Management

Categories are stored as JSON arrays in the `categories` column:

```json
["Residential", "Design & Installation"]
```

To add new categories, edit the option list in `create.php` (no database schema change needed).

Current categories:
- Strata & Property Management
- Residential
- Maintenance
- Design & Installation

## Activity Logging

All portfolio actions are logged:
- Project created
- Project updated
- Project deleted

View logs in CRM activity dashboard (integrated with existing logging system).

## Troubleshooting

### Portfolio page shows "No portfolio projects available yet"

**Cause:** Database table doesn't exist OR no published projects created yet.

**Solution:**
1. Verify migration was run: check phpMyAdmin for `portfolio_projects` table
2. Create a test project and set status to "Published"
3. Check browser console for JavaScript errors

### CRM Portfolio nav item doesn't appear

**Cause:** Sidebar not updated or cache issue.

**Solution:**
1. Verify `/public/crm/includes/appstack_sidebar.php` has Portfolio item
2. Clear browser cache (Ctrl+F5 or Cmd+Shift+R)
3. Check for PHP syntax errors: `php -l /public/crm/portfolio/index.php`

### Form submission fails

**Cause:** Validation error or database connection issue.

**Solution:**
1. Check error message displayed on form
2. Verify database connection working
3. Check file permissions on `/public/crm/portfolio/`
4. Review error logs

### Categories not saving

**Cause:** Multi-select not properly formatted in form submission.

**Solution:**
1. Ensure form has `name="categories[]"` (array notation)
2. Select categories and inspect POST data
3. Verify JSON encoding in PHP

## API Endpoints (Future)

Prepared for future AJAX functionality:
- `POST /crm/portfolio/api.php?action=upload-image` — Image upload handler
- `DELETE /crm/portfolio/api.php?action=delete-project&id=X` — AJAX delete
- `GET /crm/portfolio/api.php?action=get-projects` — JSON project list

## Security

### Authentication
- All CRM pages require login via `requireLogin()`
- Projects only visible to authenticated staff

### Authorization
- Currently all logged-in users can create/edit/delete projects
- Can be extended with role-based access control

### Data Safety
- Delete operations check referrer (CSRF protection)
- All output escaped with `htmlspecialchars()`
- All queries use prepared statements
- Activity logged for audit trail

## Next Steps

1. ✅ Run database migration
2. ✅ Test CRM module access
3. ✅ Create sample projects
4. ✅ Verify public site display
5. 📋 Add image upload handlers (if needed)
6. 📋 Implement role-based access (if needed)
7. 📋 Add bulk import/export (if needed)

## Support

For issues or enhancements:
1. Check this guide's Troubleshooting section
2. Review code comments in portfolio module files
3. Examine existing CRM modules (Jobs, Quotes) for patterns
4. Check CLAUDE.md for project architecture guidelines
