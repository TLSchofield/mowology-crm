# CMS Editor Quick Reference

## For Staff: Using the New Block Editor

### Adding Repeatable Items (Features, FAQs, Testimonials, etc.)

1. **Open block editor** (Pages → Edit Page → Edit Block)
2. **Scroll to the repeatable field** (e.g., "Features", "FAQs", "Gallery Images")
3. **Click "+ Add [Item Type]"** button
4. **Fill in fields** for the new item
5. **To reorder**: Click ↑ or ↓ buttons
6. **To delete**: Click "Remove"
7. **Click "Save Block"** (all items serialize to JSON automatically)

### Adding Media (Images, Videos)

1. **Open media field** (e.g., "Hero Image", "Gallery Image")
2. **Click "Browse"** button
3. **Search** by filename or alt text (optional)
4. **Click media thumbnail** to select
5. **Filename appears** in the field
6. **Media ID is stored** in hidden input
7. **Click "Save Block"**

### Notes

- ✅ Repeatable items auto-serialize to JSON on save — no manual JSON entry needed
- ✅ Media picker searches live (no page reload)
- ✅ Pagination shows if >12 media items exist
- ✅ All data is safe and validated server-side

---

## For Developers: Architecture

### File Structure

```
/crm/
├── cms-block-editor.php              ← Rewrite Form UI (repeatable, media picker)
├── api/
│   ├── cms_media_list.php            ← NEW API: Media search/paginate
│   ├── save-block.php                ← Updated: JSON validation
│   └── ...
├── includes/
│   ├── cms-functions.php             ← Added: cms_getAllPages()
│   └── cms-renderer.php              ← (unchanged, clean)
└── ...

/cms/
├── cms-pages_appstack.php            ← Updated: uses cms_getAllPages()
└── ...
```

### Data Flow

```
Edit Block
    ↓
cms-block-editor.php (renders form + repeatable UI + media picker)
    ↓
User adds items, searches media, clicks save
    ↓
JavaScript: serialize repeatable items to JSON
    ↓
POST to /crm/api/save-block.php
    ↓
save-block.php: validate JSON, store in DB
    ↓
cms_blocks.config = '{"feature": [...], "faqs": [...]}'
    ↓
cms-renderer.php: Load blocks + render via layouts
```

### Key Functions

#### New
```php
cms_getAllPages(): array
  // Returns ALL pages (published, draft, archived)
  // Used by page list for accurate filtering and stats
```

#### API Endpoints

**GET /crm/api/cms_media_list.php**
```
Query params:
  search=     Search string (optional)
  page=1      Page number (1-indexed)
  per_page=12 Items per page (1-50)
  type=image  Filter by media_type (optional)

Returns:
{
  "success": true,
  "data": [...],           // Array of media items with id, filename, thumb_path, etc.
  "pagination": {
    "page": 1,
    "per_page": 12,
    "total_pages": 5,
    "total_items": 52
  }
}
```

### Repeatable Item Structure

When saved, repeatable items are JSON arrays:

```json
{
  "features": [
    { "title": "Feature 1", "description": "..." },
    { "title": "Feature 2", "description": "..." }
  ],
  "faqs": [
    { "question": "Q1?", "answer": "A1" },
    { "question": "Q2?", "answer": "A2" }
  ],
  "testimonials": [
    { "name": "John", "quote": "...", "role": "CEO" }
  ],
  "images": [
    { "media_id": 5, "caption": "Before & After", "alt_override": "" }
  ]
}
```

**Rendering**: Block renderers iterate over these arrays and output HTML.

### Security Checklist

- ✅ CSRF token required (`verifyCSRFToken()`)
- ✅ Login required (`requireLogin()`)
- ✅ Role check (`['admin', 'staff']`)
- ✅ HTML escaping on output (`h()`)
- ✅ Prepared statements for DB queries
- ✅ JSON validation: `json_last_error() === JSON_ERROR_NONE`
- ✅ No direct eval() or unsafe templating

### Testing

#### Unit Test: JSON Validation
```php
// save-block.php should reject invalid JSON
POST /crm/api/save-block.php
  config[faqs]='[{"question": "Invalid JSON"'  ← Missing closing bracket

Expected:
  HTTP 400
  {"success": false, "error": "Invalid JSON in field 'faqs': ..."}
```

#### Integration Test: Media Picker
```
1. Upload 20+ images to media library
2. Open block editor
3. Click "Browse" on media field
4. Search for "test" (should find subset)
5. Page through pagination
6. Click image to select
7. Verify media ID is set in hidden input
8. Save block
9. Verify media_id is stored in DB
```

---

## Common Tasks

### How to add a new repeatable field to a block type?

1. **Edit `/cms/cms-block-editor.php`**:
   ```php
   // In $blockFieldTemplates array, add to your block:
   'my_new_repeatable' => [
       'type' => 'repeatable',
       'label' => 'My Items',
       'itemType' => 'item',
       'fields' => [
           'title' => ['type' => 'text', 'label' => 'Title'],
           'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 3],
       ]
   ]
   ```

2. **That's it!** The UI auto-generates add/remove/reorder buttons.

3. **In your block renderer**, iterate the JSON:
   ```php
   // In /crm/includes/blocks/my_block_type.php
   $items = $config['my_new_repeatable'] ?? [];
   foreach ($items as $item) {
       echo '<div>' . h($item['title']) . '</div>';
   }
   ```

### How to debug JSON serialization?

1. **Check browser console** (F12):
   - Look for fetch errors or JSON errors
   - Log output shows what's being sent

2. **Check save-block.php response**:
   ```php
   // In your browser dev tools, Network tab, POST to save-block.php
   // Response should be: {"success": true, "block_id": 123, ...}
   ```

3. **Check database**:
   ```sql
   SELECT config FROM cms_blocks WHERE id = 123;
   -- Should show valid JSON:
   -- {"features": [...], "faqs": [...]}
   ```

4. **If validation error**:
   ```
   HTTP 400: {"success": false, "error": "Invalid JSON in field 'faqs': ..."}
   ```

---

## Performance Notes

- **Media picker API** caches up to 50 items per request
- **Repeatable items** are JavaScript-only until form submit (no AJAX save)
- **JSON storage** is efficient; querying individual items not recommended (load whole config)
- **Consider**: If a block has >100 repeatable items, consider pagination

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Add [Item]" button does nothing | Check browser console for JS errors; verify jQuery is loaded |
| Media picker shows "Error loading media" | Check `/crm/api/cms_media_list.php` is accessible; verify media table has data |
| JSON validation error on save | Paste block config into JSONLint.com to find syntax error |
| Old block doesn't load repeatable items | The config is probably still raw JSON; edit and re-save to load into new UI |
| Pagination not working in media picker | If only 1 page, pagination hidden (expected). Upload more media to test. |

---

## Resources

- **Main CMS documentation**: [CMS_PHASE_1_2_COMPLETE.md](./CMS_PHASE_1_2_COMPLETE.md)
- **Phase 3+ Roadmap**: [CMS_PHASE_3_ROADMAP.md](./CMS_PHASE_3_ROADMAP.md)
- **Database schema**: Check `/database/migrations/500_cms_core.sql`

---

**Last Updated**: February 10, 2026
**Status**: ✅ Phase 1 & 2 Complete
