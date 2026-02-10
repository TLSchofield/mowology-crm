# CMS Phase 1 & 2 Implementation Complete

## Overview

This document summarizes the Phase 1 and Phase 2 implementations for the Mowology CMS marketing automation system.

---

## PHASE 1: Correctness & Stability

### ✅ Fixed cms-pages_appstack.php Data Correctness

**Problem**: The page list was calling `cms_getPublishedPages()` which only returns published pages, then attempting to filter drafts/archived pages and calculate stats. This caused:
- Incorrect stat counts (no drafts/archived pages shown)
- Filters that don't work properly
- Misleading view counts

**Solution**:
1. Created new `cms_getAllPages()` function in `/crm/includes/cms-functions.php` (line 96–114)
   - Returns ALL pages regardless of status (published, draft, archived)
   - Includes all necessary fields for admin display
   - Sorted by creation date (newest first)

2. Updated `/cms/cms-pages_appstack.php` to use `cms_getAllPages()`
   - Changed line 37 from `cms_getPublishedPages()` to `cms_getAllPages()`
   - Updated stats to show Published, Drafts, and Archived counts separately
   - Filters now work accurately on all status types

**Files Modified**:
- `/public/crm/includes/cms-functions.php` — Added `cms_getAllPages()` function
- `/public/cms/cms-pages_appstack.php` — Updated data source and stats display

---

### ✅ Audited cms-render.php and cms-renderer.php

**Problem**: Potential duplicate function definitions between cms-render.php and cms-renderer.php could cause fatal errors.

**Finding**:
- ✅ **No duplication detected**
- `cms-render.php` (lines 78–113): Defines `cms_fallbackToLegacy()` — NOT in cms-renderer.php
- `cms-renderer.php`: Provides rendering functions (`cms_renderPage()`, `cms_renderBlock()`, etc.)
- The include structure is clean and safe:
  ```
  cms-render.php
    ↓ includes
  cms-renderer.php (rendering functions only)
  cms-functions.php (data functions)
  ```

**Verdict**: No changes needed. Both files are clean and serve distinct purposes.

---

## PHASE 2: Editor UX Upgrades (Automation Enablers)

### ✅ Created Media List API Endpoint

**File Created**: `/public/crm/api/cms_media_list.php`

**Purpose**: JSON API endpoint for media picker modal to search, filter, and paginate media library.

**Functionality**:
- **Search**: Search by filename or alt text
- **Pagination**: Configurable per_page (default 12, max 50), numbered pagination
- **Filtering**: Filter by media_type (image, video, document)
- **Authentication**: Requires login via `requireLogin()`
- **Output**: JSON with media items and pagination metadata

**Endpoint**: `GET /crm/api/cms_media_list.php?search=&page=1&per_page=12&type=image`

**Response Format**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "filename": "hero-image.jpg",
      "file_path": "/uploads/media/hero-image.jpg",
      "thumb_path": "/uploads/media/thumbs/hero-image.jpg",
      "type": "image",
      "alt_text": "Hero banner image",
      "size": 102400,
      "uploaded_at": "2026-02-10T10:30:00Z"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 12,
    "total_pages": 5,
    "total_items": 52
  }
}
```

---

### ✅ Implemented Media Picker Modal in Block Editor

**File Rewritten**: `/public/cms/cms-block-editor.php`

**Changes**:
1. **Updated block field templates** to support new field types:
   - Added `type: 'media'` for single media selection
   - Added `type: 'repeatable'` for repeatable list editors

2. **Block types now support repeatable fields**:
   - `feature_grid`: Features (title + description)
   - `faq`: FAQs (question + answer)
   - `testimonials`: Testimonials (name + quote + role)
   - `gallery`: Gallery images (media_id + caption + alt_override)
   - `service_cards`: Services (title + description)

3. **Media picker modal** (lines 383–405):
   - Searchable grid of media with pagination
   - Real-time search powered by `/crm/api/cms_media_list.php`
   - Click to select media sets media_id and displays filename

4. **Repeatable UI controls** (lines 415–507):
   - Add new items
   - Remove items
   - Move items up/down (reorder)
   - Each item displays sub-fields based on block type

5. **Safe JSON serialization** (lines 620–652):
   - Form submission handler collects all repeatable items
   - Serializes to JSON before saving
   - Automatically inserts hidden input with JSON value

**Features**:
- ✅ Full-text search on media filename and alt text
- ✅ Pagination support (12 items per page)
- ✅ Searchable media library grid
- ✅ Click-to-select media with instant preview
- ✅ Media picker for both top-level and repeatable sub-fields
- ✅ Reorderable items (move up/down buttons)
- ✅ Add/remove items with instant UI updates
- ✅ Backward compatible: loads existing JSON into UI
- ✅ Safe JSON serialization with error handling

---

### ✅ Updated save-block.php with JSON Validation

**File Updated**: `/public/crm/api/save-block.php`

**Changes**:
- Enhanced JSON parsing (lines 38–53):
  - Detects JSON strings (starting with `[` or `{`)
  - Validates JSON syntax using `json_decode()`
  - Checks for JSON errors: `json_last_error() === JSON_ERROR_NONE`
  - Returns descriptive error if JSON is invalid
  - Logs errors for debugging

**Error Handling**:
- HTTP 400 Bad Request if JSON is malformed
- JSON response with specific error message
- Admin sees which field has the problem

---

## Technical Details

### Database Requirements

Current implementation uses ONLY existing database fields:
- `cms_media`: id, filename, file_path, thumb_path, media_type, alt_text, file_size, uploaded_at
- `cms_pages`: id, slug, title, page_type, status, meta_title, etc. (unchanged)
- `cms_blocks`: id, page_id, block_type, config (JSON), label, is_visible, position, created_at, updated_at

**No migrations required for Phase 1 & 2.**

### JSON Storage Strategy

Repeatable items are stored as JSON arrays in the `cms_blocks.config` JSON field:

Example: Feature Grid block with 3 features
```json
{
  "title": "Our Services",
  "description": "We offer...",
  "layout": "3",
  "features": [
    {"title": "Feature 1", "description": "Description..."},
    {"title": "Feature 2", "description": "Description..."},
    {"title": "Feature 3", "description": "Description..."}
  ]
}
```

### Security

- ✅ CSRF token verification in save-block.php
- ✅ Login required for media picker API
- ✅ Role-based access control (admin/staff only)
- ✅ HTML escaping on all user-facing output (`h()` function)
- ✅ Prepared statements for all database queries
- ✅ JSON validation before storage
- ✅ No inline `<script>` execution or eval()

---

## Testing Checklist

### Manual Testing Required

- [ ] Create a new feature_grid block and add 2–3 features via repeatable UI
  - [ ] Verify "Add Feature" button creates new item
  - [ ] Verify "Remove" deletes item
  - [ ] Verify move up/down reorders items
  - [ ] Verify save serializes to JSON correctly

- [ ] Create a gallery block and add images via media picker
  - [ ] Search media library (test search by filename)
  - [ ] Pagination works (if >12 media items exist)
  - [ ] Click media item sets media_id and displays filename
  - [ ] Multiple images can be added
  - [ ] Captions and alt overrides are editable

- [ ] Create an FAQ block
  - [ ] Add multiple Q&A pairs
  - [ ] Verify JSON is stored correctly
  - [ ] Edit existing page with FAQ block — verify items load

- [ ] Verify page list stats
  - [ ] Published count is correct
  - [ ] Draft count is correct
  - [ ] Archived count is correct
  - [ ] Filters work on all status types

---

## Files Modified

### Core CMS Functions
- `/public/crm/includes/cms-functions.php`
  - Added `cms_getAllPages()` function (line 96–114)

### Admin Pages
- `/public/cms/cms-pages_appstack.php`
  - Updated to use `cms_getAllPages()` (line 37)
  - Fixed stats display (lines 88–128)

- `/public/cms/cms-block-editor.php`
  - Complete rewrite with repeatable UI (full file)
  - Added media picker modal (lines 383–405)
  - Added JavaScript for repeatable items and media picker (lines 408–655)

### API Endpoints
- `/public/crm/api/save-block.php`
  - Enhanced JSON validation (lines 38–53)

- `/public/crm/api/cms_media_list.php` **[NEW]**
  - Media list endpoint for picker modal

---

## What's Next: Phase 3 & Beyond

### Phase 3: Automation Layer (Media Optimization & SEO)

Proposed schema changes:
- Add `cms_media` fields: `webp_path`, `source_width`, `source_height`, `sizes_json`
- Add `cms_pages` fields: `auto_seo_enabled`, `canonical_override`, `robots_override`
- Add `cms_blocks` field: `schema_type` (for JSON-LD)

### Phase 4: Template-Driven Page Generation

Service + Neighbourhood → Auto-generate landing page with hero, proof, testimonials, CTA

### Phase 5: Portfolio → Marketing Integration

Tag job photos by service/neighbourhood; favorites populate proof sections; auto-generate case studies

---

## Notes for Developer

1. **Repeatable Items**: The JavaScript serializes repeatable items on form submission. Check browser console if items don't save.

2. **Media Picker**: Requires `cms_media` table populated. If empty, picker shows no results.

3. **Backward Compatibility**: Old pages with JSON textareas will still work. When edited, JSON is loaded into the new UI.

4. **JSON Errors**: If admin sees "Invalid JSON in field..." error, the JSON must be fixed or re-entered via UI.

5. **Cache Invalidation**: Pages use cache with 900s TTL. Update or clear if needed after bulk changes.

---

**Implementation Date**: February 10, 2026
**Status**: ✅ Complete & Ready for Testing
