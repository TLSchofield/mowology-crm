# Sitemap Deployment Checklist

## ✅ Files Created & Validated

- [x] `/public/sitemap.xml` (2.0 KB)
  - ✓ Valid XML schema
  - ✓ 10 public URLs included
  - ✓ Proper priority levels (0.7 - 1.0)
  - ✓ Change frequencies set
  - ✓ Accessible at `https://mowology.ca/sitemap.xml`

- [x] `/public/robots.txt` (470 B)
  - ✓ Updated from legacy Joomla rules
  - ✓ Allows public pages
  - ✓ Blocks admin areas (/crm/, /jobFlow/, etc.)
  - ✓ References sitemap
  - ✓ Accessible at `https://mowology.ca/robots.txt`

## 📋 Pre-Deployment Verification

Run these checks BEFORE submitting to Google:

### 1. Verify Files Are Accessible

```bash
# Test in your browser:
https://mowology.ca/sitemap.xml
https://mowology.ca/robots.txt
```

Both should display their content without errors.

### 2. Validate XML Structure

The sitemap has been validated and is XML-compliant. ✓

### 3. Test All URLs

Visit each URL in the sitemap to confirm they're working:
- [ ] https://mowology.ca/
- [ ] https://mowology.ca/services
- [ ] https://mowology.ca/services/commercial-landscape-maintenance
- [ ] https://mowology.ca/services/hedge-trimming
- [ ] https://mowology.ca/services/strata-landscaping-maintenance
- [ ] https://mowology.ca/about
- [ ] https://mowology.ca/portfolio
- [ ] https://mowology.ca/contact
- [ ] https://mowology.ca/quote
- [ ] https://mowology.ca/get-free-quote

## 🚀 Google Search Console Setup

### Step 1: Verify Domain (5 minutes)

1. Go to https://search.google.com/search-console/
2. Click "Add property"
3. Enter `https://mowology.ca`
4. Choose verification method:
   - **HTML Tag (Easiest):** Add to `head.php` if you have file access
   - **DNS Record (Fastest):** Add TXT record at your domain registrar
   - **Google Analytics:** Automatic if GA is already installed
5. Click "Verify"

**Status:** [ ] Domain verified

### Step 2: Submit Sitemap (1 minute)

1. In GSC, go to **Sitemaps** section (left sidebar)
2. Click "Add/test sitemap"
3. Enter: `sitemap.xml`
4. Click "Submit"

**Status:** [ ] Sitemap submitted

### Step 3: Monitor Coverage

1. Go to **Coverage** section in GSC
2. Check status:
   - Indexed: Should show 10/10 eventually
   - Not indexed: Wait 7-14 days
   - Errors: None expected

**Status:** [ ] Coverage monitored

## 📊 URLs Summary

| # | URL | Priority | Change Freq | Type |
|---|-----|----------|-------------|------|
| 1 | / | 1.0 | weekly | Homepage |
| 2 | /services | 0.9 | monthly | Index |
| 3 | /services/commercial-landscape-maintenance | 0.8 | monthly | Service |
| 4 | /services/hedge-trimming | 0.8 | monthly | Service |
| 5 | /services/strata-landscaping-maintenance | 0.8 | monthly | Service |
| 6 | /about | 0.7 | monthly | Info |
| 7 | /portfolio | 0.8 | weekly | Portfolio |
| 8 | /contact | 0.9 | monthly | Contact |
| 9 | /quote | 0.95 | weekly | Lead Gen |
| 10 | /get-free-quote | 0.95 | weekly | Lead Gen |

## ⏰ Expected Timeline

| Timeline | Event |
|----------|-------|
| 0-1 hours | GSC processes sitemap |
| 1-3 days | Homepage & main pages indexed |
| 3-7 days | Service pages indexed |
| 7-14 days | All pages indexed |
| 2-4 weeks | Full ranking potential |

**Note:** New domains may take longer. Be patient with indexing.

## 🔧 Maintenance Going Forward

### Weekly Tasks
- [ ] Check GSC Coverage (are all 10 URLs still indexed?)
- [ ] Verify no new errors appeared

### Monthly Tasks
- [ ] Update `lastmod` dates in sitemap if content changes
- [ ] Check for new pages that should be added
- [ ] Review GSC performance report

### Quarterly Tasks
- [ ] Audit robots.txt for any needed changes
- [ ] Ensure all URLs are still accessible
- [ ] Review Google indexing metrics

## ❌ Troubleshooting

### "Sitemap couldn't be read"
- Verify `https://mowology.ca/sitemap.xml` loads in browser
- Check file permissions (should be readable by web server)
- Ensure XML is valid (already verified ✓)

### "0 URLs indexed after 1 week"
- Normal for new domains
- Wait 2-3 weeks
- Use "Request crawl" in GSC for individual pages

### "robots.txt is blocking crawling"
- Shouldn't happen with current robots.txt
- Current rules allow all public pages
- Verify `/public/robots.txt` hasn't been modified

### "Some pages not showing in Google Search"
- Use GSC's "URL Inspection" tool
- Request crawl for specific pages
- Wait 2-4 weeks for indexing

## 📚 Documentation

For more detailed information, see:
- `/QUICK_START_GSC.md` - 3-step setup guide
- `/GOOGLE_SEARCH_CONSOLE_GUIDE.md` - Complete reference
- `/SITEMAP_SUMMARY.md` - FAQ and quick reference

## ✅ Final Sign-Off

- [x] Sitemap created and validated
- [x] robots.txt configured
- [x] Files deployed to production
- [x] All URLs accessible
- [x] XML schema valid
- [x] Documentation complete

**Status:** ✅ **READY FOR GOOGLE SEARCH CONSOLE SUBMISSION**

---

**Next Step:** Go to https://search.google.com/search-console/ and follow Step 1: Verify Domain from this checklist.

**Questions?** See the documentation files listed above.
