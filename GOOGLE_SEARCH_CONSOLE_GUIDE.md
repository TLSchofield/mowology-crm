# Google Search Console Setup Guide - Mowology

This guide walks you through setting up Google Search Console for mowology.ca and submitting the sitemap.

---

## What's Been Created

### 1. Sitemap.xml
**Location:** `https://mowology.ca/sitemap.xml`

Contains 10 public-facing URLs:
- Homepage (priority: 1.0)
- Services overview page (priority: 0.9)
- 3 Dynamic service landing pages (priority: 0.8 each):
  - Commercial Landscape Maintenance
  - Hedge Trimming & Shaping
  - Strata Landscaping & Maintenance
- About page (priority: 0.7)
- Portfolio page (priority: 0.8)
- Contact page (priority: 0.9)
- Quote page (priority: 0.95)
- Free Quote page (priority: 0.95)

**Technical Details:**
- Valid XML format (tested against XML schema)
- Includes image and mobile namespaces for future media
- Last modified dates match actual page update dates
- Change frequencies set appropriately for each page type

### 2. Updated robots.txt
**Location:** `https://mowology.ca/robots.txt`

**Key Changes:**
- Removed legacy Joomla directives (no longer relevant)
- Added specific Disallow rules for CRM-protected areas:
  - `/crm/` - Protected admin interface
  - `/jobFlow/` - Internal lead tracking
  - `/customer/` - Token-based portal
  - `/loginAuth/` - Authentication files
  - `/sessions/` - Session data
  - `/uploads/` - User uploads
  - `/app_config/` - Configuration files
- Added test utilities to Disallow list:
  - `DEBUG_UTILITY.php`
  - `POPULATE_TEST_DATA.php`
- Added Crawl-delay: 1 to respect server resources
- **Added sitemap reference** pointing to `https://mowology.ca/sitemap.xml`

---

## Setup Steps

### Step 1: Verify Domain Ownership

1. Go to **Google Search Console**: https://search.google.com/search-console/
2. Sign in with your Google account (must have access to office@mowology.ca or domain registrar)
3. Click **"Add property"** → Enter: `https://mowology.ca`
4. Choose a verification method:

**Option A: HTML File (Recommended)**
- Download the HTML verification file from GSC
- Upload it to `/public/` directory on the server
- File will be at: `https://mowology.ca/[verification-file].html`
- Click "Verify" in GSC

**Option B: HTML Tag**
- Copy the `<meta>` tag from GSC
- Add it to `/public/includes/head.php` (contact Tim if you need this done)
- Save and verify in GSC

**Option C: Domain Provider**
- Access your domain registrar (GoDaddy, Namecheap, etc.)
- Add the TXT DNS record provided by GSC
- Wait 24-48 hours for DNS propagation
- Click "Verify" in GSC

**Option D: Google Analytics**
- If mowology.ca already has Google Analytics, GSC can verify automatically
- Look for the UA-XXXXX tracking code in `/public/includes/head.php`

---

### Step 2: Submit the Sitemap

1. In **Google Search Console**, go to **Sitemaps** (left sidebar)
2. Click **"Add/test sitemap"**
3. Enter the URL: `sitemap.xml` (GSC will auto-prepend `https://mowology.ca/`)
4. Click **"Submit"**

**GSC will:**
- Validate the XML format
- Count the URLs (should be 10)
- Check for any errors or warnings
- Begin crawling the listed pages within hours

**Expected Result:**
```
Sitemap received: 10 URLs
Status: Success (or check back in a few hours for the first processing)
```

---

### Step 3: Monitor Coverage

1. In **Google Search Console**, go to **Coverage** (left sidebar)
2. Review the status of your URLs:
   - **Indexed:** Pages that Google has crawled and added to its index (goal: all 10)
   - **Not indexed:** Pages Google found but didn't index (less common for public pages)
   - **Excluded:** Pages you or GSC told Google to skip
   - **Errors:** Pages that have problems (4xx/5xx errors, redirect issues, etc.)

**What to expect:**
- Homepage and main pages indexed within 1-3 days
- Service landing pages indexed within 1-2 weeks
- All indexing depends on crawl budget and page quality

---

### Step 4: Set Preferred Domain

1. Go to **Settings** (left sidebar) → **Site settings**
2. Under **Preferred domain**, choose:
   - `https://www.mowology.ca/` (with www), OR
   - `https://mowology.ca/` (without www)
3. Save

**Current Setup:**
- The site is configured for `https://mowology.ca/` (no www)
- All URLs in sitemap.xml use this format
- Keep this preference consistent

---

### Step 5: Configure Core Web Vitals & Performance

1. Go to **Core Web Vitals** (left sidebar)
2. Review the report (may take a few days to show data)
3. Address any "Poor" metrics:
   - **Largest Contentful Paint (LCP):** < 2.5 seconds
   - **First Input Delay (FID):** < 100 milliseconds
   - **Cumulative Layout Shift (CLS):** < 0.1

**Quick fixes:**
- Optimize hero images (use WebP format where possible)
- Minify CSS/JS in `mowology-brand.css` and `script.js`
- Enable GZIP compression in `.htaccess`

---

### Step 6: Enable Mobile Usability Monitoring

1. Go to **Mobile usability** (left sidebar)
2. GSC will automatically test pages on mobile
3. Fix any reported issues (e.g., small font, cramped spacing)

**Mowology's Status:**
- Bootstrap 4 grid is mobile-responsive
- Touch targets are properly sized
- Viewport meta tag is in place
- Expected: Fully mobile-friendly

---

### Step 7: Link Google Analytics (Optional but Recommended)

1. Go to **Settings** → **Connect Google Analytics**
2. Select your Google Analytics property (if exists)
3. GSC will cross-populate data
4. You'll see click-through rates and query positions in GSC

---

## Monitoring Checklist

### Weekly
- [ ] Check **Coverage** for new errors
- [ ] Review **Core Web Vitals** trend
- [ ] Monitor **Performance** → CTR & position changes

### Monthly
- [ ] Review **Search Performance** → top queries & pages
- [ ] Check **Mobile Usability** for new issues
- [ ] Review backlink report (once it has data)
- [ ] Verify **Security & Manual Actions** → none should appear

### Quarterly
- [ ] Update sitemap if new pages are added
- [ ] Resubmit sitemap to ensure Google knows about new content
- [ ] Review redirect chains and 404 errors
- [ ] Audit internal linking structure

---

## Adding New Pages to Sitemap

When you add a new public page to mowology.ca:

1. Edit `/public/sitemap.xml`
2. Add a `<url>` block for the new page:
   ```xml
   <url>
     <loc>https://mowology.ca/new-page</loc>
     <lastmod>2026-02-08</lastmod>
     <changefreq>monthly</changefreq>
     <priority>0.8</priority>
   </url>
   ```
3. Save the file
4. In **Google Search Console**, go to **Sitemaps** and click **Resubmit sitemap**
5. Google will crawl the new page within 24-48 hours

---

## Troubleshooting

### "Sitemap couldn't be read"
- **Cause:** XML is malformed or file is not accessible
- **Fix:** Verify the sitemap is at `https://mowology.ca/sitemap.xml` and contains valid XML
- **Test:** Visit `https://mowology.ca/sitemap.xml` in your browser—you should see XML code

### "Coverage shows 0 indexed pages"
- **Cause:** Domain is new or hasn't been crawled yet
- **Fix:** Wait 1-3 days, then check again. GSC crawls new sites slower
- **Help:** In GSC, request Google to crawl specific pages using **URL Inspection** tool

### "robots.txt is blocking crawling"
- **Cause:** A Disallow rule is too broad (should not happen with our current robots.txt)
- **Fix:** Review `/public/robots.txt` and ensure public pages are NOT disallowed
- **Current Status:** Public pages are allowed; only /crm/, /jobFlow/, etc. are blocked

### "Some pages not indexed"
- **Cause:** Page is too new, has poor content, or is a duplicate
- **Action:**
  - Wait 2-4 weeks for crawl
  - Use **URL Inspection** in GSC to request immediate crawl
  - Review page quality (title, description, unique content)

---

## FAQ

**Q: How often is the sitemap crawled?**
A: Google typically crawls submitted sitemaps every 7-30 days. Use "Resubmit sitemap" after major changes to speed this up.

**Q: Do I need to update lastmod dates?**
A: Yes. Update lastmod in sitemap.xml whenever a page is meaningfully changed. Google uses this to prioritize crawling updated content.

**Q: What if my priority is 0.8 for everything?**
A: Google largely ignores explicit priority values and uses other signals (page authority, freshness, user signals). We've set priorities to reflect the business value of each page.

**Q: Will the sitemap improve my rankings?**
A: Not directly. The sitemap helps Google **find and index** your pages faster. Rankings depend on content quality, backlinks, and user signals.

**Q: Can I have multiple sitemaps?**
A: Yes. If you exceed 50,000 URLs, you'd create a sitemap index. For Mowology's 10 URLs, one sitemap is sufficient.

**Q: How do I remove a page from search results?**
A: 1. Delete from sitemap.xml, 2. Use **URL Inspection** → **Remove URL**, or 3. Add `noindex` meta tag to page. Results typically disappear within days.

---

## Support & Resources

- **Google Search Console Help:** https://support.google.com/webmasters
- **Sitemap Protocol:** https://www.sitemaps.org/
- **robots.txt Standard:** https://www.robotstxt.org/
- **Mowology Tech Contact:** Tim Schofield

---

**Last Updated:** February 8, 2026
**Sitemap Status:** ✓ Created and ready for submission
**robots.txt Status:** ✓ Updated with sitemap reference
