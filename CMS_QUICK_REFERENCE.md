# CMS Quick Reference

## Admin Access
- **URL:** `https://yourdomain.com/crm/dashboard_appstack.php`
- **Login:** Use your CRM credentials
- **Required Role:** Admin or Staff

---

## Main Navigation

### CMS Section (Left Sidebar)
```
Dashboard
├── Clients
├── Quotes
├── Jobs
├── Invoices
├── Schedule
├── Territory Map
├── Products
├── Portfolio
├── CMS ← Start here
│   ├── Pages
│   ├── Media Library
│   └── Templates
└── Marketing
```

---

## Common Tasks (Step by Step)

### Create a New Page

```
1. CMS → Pages
2. Click "New Page" (top right)
3. Enter page metadata:
   - Slug: "about" (URL-safe identifier)
   - Title: "About Us"
   - Meta Description: "About our company" (for Google)
   - Page Type: "Custom" (or specific type)
   - Status: "Draft" (for testing)
4. Click "Create Page"
5. Click "Add Block" to add content
6. Choose block type (Hero, Features, etc.)
7. Configure block (headline, images, etc.)
8. Click "Save Block"
9. Repeat steps 5-8 for more blocks
10. Change status to "Published"
11. Click "View Live" to see on website
```

### Upload a Media File

```
1. CMS → Media Library
2. Click "Upload Media" (top right)
3. Select file (JPG, PNG, GIF, PDF, MP4, etc.)
4. Enter Alt Text (accessibility + SEO)
5. Click "Upload"
6. Use in pages: Page Editor → Block → Select Media Field → Choose File
```

### Edit Page Content

```
1. CMS → Pages
2. Find page in list, click "Edit"
3. Click "Edit" on the block you want to change
4. Update content fields
5. Click "Save Block"
6. To add block: Click "Add Block" → choose type → configure
7. To delete block: Click "Delete" (careful!)
8. To reorder: Drag blocks (when implemented)
```

### Edit Media Metadata

```
1. CMS → Media Library
2. Find media in list, click "Edit"
3. Update Alt Text
4. Click "Save Changes"
5. Alt Text now appears in:
   - Image title attribute (hover tooltip)
   - Accessibility screen readers
   - Google Images search results
```

### Create Page from Template

```
1. CMS → Pages
2. Click "New Page"
3. See templates in right sidebar: "Or Create from Template"
4. Click template name (e.g., "Service Landing")
5. Template blocks are pre-added
6. Edit blocks to customize
7. Enter page metadata (slug, title, etc.)
8. Click "Create Page"
9. Customize blocks as needed
10. Publish
```

### View Page on Website

```
Option 1: From editor
1. Edit page
2. Click "View Live" button
3. Opens page in new tab

Option 2: Direct URL
- Slug "about" → https://yourdomain.com/about
- Slug "services/lawn-care" → https://yourdomain.com/services/lawn-care
```

---

## Block Types Quick Reference

| Block Type | Use For | Key Fields |
|-----------|---------|-----------|
| **Hero** | Top banner | Headline, subheadline, CTA, image |
| **Features** | Benefit list | Title, icon, description, columns |
| **CTA** | Call-to-action | Headline, buttons, style |
| **Testimonials** | Customer quotes | Text, name, photo |
| **FAQ** | Q&A section | Questions, answers |
| **Gallery** | Images | Photos, captions |
| **Service Cards** | Services | Name, icon, description, link |
| **Rich Text** | Custom HTML | Full HTML editor |

---

## Deployment Checklist

- [ ] Run database migrations (500, 501, 502, 503)
- [ ] Create `/uploads/cms/` directory
- [ ] Test page creation
- [ ] Test media upload
- [ ] Test block rendering
- [ ] Verify SEO meta tags
- [ ] Test on mobile device
- [ ] Back up database

---

## Tips

1. **Use descriptive slugs** - about, services, contact-us (not page1, page2)
2. **Keep slugs lowercase** - CMS converts automatically but be clear
3. **Fill in alt text** - Helps accessibility and SEO
4. **Use drafts first** - Test before publishing to public
5. **View Live often** - Check how it looks on the actual site

---

**Status:** ✅ Production Ready
**Version:** 1.0
**Last Updated:** February 2026
