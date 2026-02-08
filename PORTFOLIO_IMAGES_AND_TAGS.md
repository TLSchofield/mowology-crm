# Portfolio CMS - Images & Tags Guide

## Image Upload Feature

### How It Works

The portfolio CMS now includes automatic image upload functionality:

1. **Select Image** - Click on the "Before Image" or "After Image" file input
2. **Auto-Upload** - Image is immediately uploaded to `/uploads/portfolio/`
3. **Preview** - You'll see a preview with upload status
4. **Save Project** - Click "Save" or "Update" to save the project with the image paths

### Image Upload Details

- **Supported Formats**: JPEG, PNG, GIF, WebP
- **Maximum Size**: 5MB per image
- **Storage Location**: `/public/uploads/portfolio/`
- **URL Format**: `/uploads/portfolio/project_TIMESTAMP_HASH.ext`
- **Security**: File type and size validation on server

### Upload Handler

**File**: `/public/crm/portfolio/upload-image.php`

This API endpoint handles:
- File validation (type, size, name)
- Directory creation
- Unique filename generation
- AJAX response with image path

### Troubleshooting Image Uploads

**Images don't appear after upload:**
1. Check that `/uploads/portfolio/` directory exists and is writable
2. Verify file size is under 5MB
3. Check browser console for JavaScript errors
4. Verify image format is supported (JPEG, PNG, GIF, WebP)

**Upload folder doesn't exist:**
1. Create `/public/uploads/portfolio/` directory manually:
   ```bash
   mkdir -p /public/uploads/portfolio/
   chmod 755 /public/uploads/portfolio/
   ```
2. Or upload-image.php will create it automatically

**Permission errors:**
1. Ensure web server has write permissions:
   ```bash
   chmod 755 /public/uploads/
   chmod 755 /public/uploads/portfolio/
   ```
2. Check file ownership: `ls -la /public/uploads/`

---

## Tags Feature

### What Are Tags?

Tags are flexible labels for portfolio projects. Unlike fixed categories, tags can be anything you want:

**Examples of Tags:**
- "Weekly Maintenance"
- "Before & After"
- "Strata"
- "High-End"
- "Budget-Friendly"
- "Award Winner"
- "Client Favorite"
- "Summer Project"

### How to Use Tags

#### Adding Tags

1. Go to **Create Project** or **Edit Project**
2. Find the **"Project Tags"** field
3. Enter tags separated by commas:
   ```
   Weekly Maintenance, Strata, Before & After
   ```
4. Save the project

#### Editing Tags

1. Click **Edit** on a project
2. Update the tags field
3. Click **Update Project**

#### Viewing Tags

**In CRM:**
- Tags appear on project detail page in a gray badge section

**On Public Site:**
- Tags appear alongside categories in portfolio item cards
- Both categories and tags are combined in the display

### Tag Format

- **Separator**: Comma (`,`)
- **Example Input**: `"Weekly Maintenance, High-End Service, Award Winner"`
- **Stored As**: JSON array `["Weekly Maintenance", "High-End Service", "Award Winner"]`
- **Display**: Each tag in a gray badge

### Database Schema

Tags are stored in `portfolio_projects` table:

```sql
`tags` json COMMENT 'Array of project tags (e.g., ["Weekly Maintenance", "Strata"])'
```

**Migration Update**: `database/migrations/013_portfolio_projects_table.sql`
- Added `tags` column (JSON type)
- No separate table needed (flexible schema)

### Tags vs Categories

| Aspect | Categories | Tags |
|--------|-----------|------|
| Purpose | Primary service grouping | Secondary descriptive labels |
| Values | Fixed list (dropdown) | Custom, free-form |
| Selection | Multi-select | Comma-separated text |
| Display | Primary badges | Secondary badges |
| Examples | Strata, Residential | Weekly Maintenance, Award Winner |

---

## Updated Database Schema

### Migration File
`database/migrations/013_portfolio_projects_table.sql`

**New Column Added:**
```sql
`tags` json COMMENT 'Array of project tags (e.g., ["Weekly Maintenance", "Strata"])'
```

### Running the Migration (Again)

If you've already run the migration without the tags column:

**Option 1: Add column manually in phpMyAdmin**
```sql
ALTER TABLE portfolio_projects ADD COLUMN `tags` json COMMENT 'Array of project tags';
```

**Option 2: Drop and recreate table**
1. Export/backup data first
2. Drop table
3. Run full migration again
4. Re-import data

---

## Helper Functions (Updated)

### createPortfolioProject()
Now accepts `tags` in data array:
```php
$project = createPortfolioProject([
    'project_name' => 'Backyard Renovation',
    'tags' => json_encode(['Weekly Maintenance', 'Strata']),
    // ... other fields
]);
```

### updatePortfolioProject()
Now supports updating `tags`:
```php
updatePortfolioProject($projectId, [
    'tags' => json_encode(['New Tag 1', 'New Tag 2']),
    // ... other fields
]);
```

---

## Public Site Display

### How Tags Appear

On `/portfolio.php`, each project shows:
1. Project title
2. Location
3. Description
4. **Combined Tags & Categories** (all in same badge area)

**Example:**
```
Project Name: Backyard Renovation
Location: Vancouver, BC
Description: Complete garden redesign...

[Residential] [Design & Installation] [Weekly Maintenance] [Award Winner]
```

### Filtering

Currently, tags are **informational only**. The JavaScript filter works on:
- Categories only (for now)
- Future: Add JavaScript filter for tags

**To Add Tag Filtering:**
Edit `/public/assets/css/pages/portfolio.js`:
- Modify filter logic to check tag badges
- Add "Tags" filter buttons

---

## File Changes Summary

### Created Files
- `/public/crm/portfolio/upload-image.php` - Image upload API

### Modified Files
- `/database/migrations/013_portfolio_projects_table.sql` - Added tags column
- `/public/crm/portfolio/create.php` - Added tags field + image upload JS
- `/public/crm/portfolio/view.php` - Display tags on detail page
- `/public/crm/includes/functions.php` - Updated helpers for tags
- `/public/portfolio.php` - Display tags on public site

---

## Complete Workflow Example

### Create a Project with Images and Tags

1. **Go to CRM Dashboard**
   - Click "Portfolio" → "Add Project"

2. **Fill Basic Info**
   - Name: "Richmond Townhouse Complex"
   - Location: "Richmond, BC"
   - Description: "Complete maintenance program for 45-unit complex..."

3. **Add Images**
   - Select "Before Image" → project automatically uploads
   - Select "After Image" → project automatically uploads
   - Wait for "✓ Uploaded" status

4. **Set Categories**
   - Select: "Strata & Property Management"
   - Select: "Maintenance"

5. **Add Tags**
   - Type: "Weekly Maintenance, Strata, Hedges"
   - (Comma-separated, automatically parsed)

6. **Set Visibility**
   - Status: "Published" (to show on public site)
   - Featured: Check if it's a showcase project
   - Display Order: "1" (appears first)

7. **Save**
   - Click "Create Project"
   - View project detail page
   - Verify images and tags appear

8. **Check Public Site**
   - Go to portfolio.php
   - Project appears with all info and tags
   - Filters work for categories

---

## Technical Details

### Image Upload Flow

```
User selects file
     ↓
JavaScript: previewImage() triggered
     ↓
Local preview shown to user
     ↓
JavaScript: uploadImage() called
     ↓
AJAX POST to upload-image.php
     ↓
Server validates file
     ↓
File moved to /uploads/portfolio/
     ↓
JSON response with filepath
     ↓
Hidden form input populated with path
     ↓
User submits project form
     ↓
Image path saved to database
```

### Tags Processing Flow

```
User enters: "Tag1, Tag2, Tag3"
     ↓
PHP: Split on comma
     ↓
PHP: Trim whitespace each tag
     ↓
PHP: Filter empty strings
     ↓
PHP: json_encode() to array format
     ↓
Stored in database as JSON
     ↓
Retrieved and displayed as badges
```

---

## API Reference

### Image Upload Endpoint

**URL**: `/crm/portfolio/upload-image.php`
**Method**: `POST`
**Auth**: CRM login required

**Request:**
```
Content-Type: multipart/form-data

{
  "image": <binary file data>
}
```

**Response Success:**
```json
{
  "success": true,
  "message": "Image uploaded successfully",
  "filepath": "/uploads/portfolio/project_1707243600_a1b2c3d4.jpg",
  "filename": "project_1707243600_a1b2c3d4.jpg"
}
```

**Response Error:**
```json
{
  "success": false,
  "message": "File too large. Maximum size is 5MB"
}
```

---

## Best Practices

### For Images

1. **Optimize Before Upload**
   - Keep images under 5MB
   - Use JPEG for photos (smaller file size)
   - Use PNG for graphics/logos

2. **File Naming**
   - System auto-generates unique names
   - No special characters needed

3. **Storage**
   - Images stored in `/uploads/portfolio/`
   - All users on CRM can upload
   - Consider disk space usage (multiply files by number of projects)

### For Tags

1. **Be Consistent**
   - Use same tag names across projects
   - Create "standard" tags for your business

2. **Keep Short**
   - Tags should be 1-3 words
   - Examples: "Weekly", "Award Winner", "High-End"

3. **Use Meaningful Names**
   - Avoid generic tags like "project" or "work"
   - Use specific descriptors

4. **Standard Tag Examples**
   - "Weekly Maintenance"
   - "One-Time Service"
   - "Award Winner"
   - "Client Favorite"
   - "Before & After"
   - "Featured"
   - "New Service"

---

## Future Enhancements

**Possible improvements:**

1. **Image Gallery**
   - Add multiple gallery images per project
   - Slider/carousel on public site

2. **Tag Filtering**
   - Add JavaScript filter buttons for tags
   - Filter projects by specific tags

3. **Tag Management**
   - Admin page to manage standard tags
   - Auto-suggest tags while typing

4. **Image Optimization**
   - Auto-resize images on upload
   - Generate thumbnails
   - Lazy loading on public site

5. **Image Cropping**
   - Allow users to crop images before upload
   - Aspect ratio enforcement

---

## Troubleshooting

**Q: Images upload but don't display on portfolio page**
A: Check the image path in database. Use browser console to see if image URL is correct.

**Q: Tags don't save when creating project**
A: Check form has `name="tags"` field. Verify browser sends data in POST request.

**Q: Upload folder permission errors**
A: Run `chmod 755 /public/uploads/portfolio/` and verify web server ownership.

**Q: Only some file types work**
A: Allowed types: JPEG, PNG, GIF, WebP. Check server MIME type configuration.

**Q: Can't upload file over 5MB**
A: This is intentional limit. Compress image or split into multiple files.

---

## Summary

✅ **Image Upload** - Automatic AJAX upload with validation
✅ **Tags Field** - Flexible JSON-based tag system
✅ **Database** - Tags column added via migration
✅ **Public Display** - Tags appear on portfolio cards
✅ **CRM Management** - Create, edit, view tags easily
✅ **Error Handling** - Comprehensive validation and feedback

Both features are production-ready and fully integrated!
