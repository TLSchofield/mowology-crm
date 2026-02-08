# Quick Start: Google Search Console Submission

**TL;DR:** 3 steps to get Mowology indexed in Google.

---

## Step 1: Verify Domain (5 min)

Go to: https://search.google.com/search-console/

1. Click "Add property"
2. Enter: `https://mowology.ca`
3. Choose verification method:
   - **Easiest:** HTML tag (copy to `/public/includes/head.php`)
   - **Fastest:** DNS record (add TXT record at registrar)
   - **If available:** Google Analytics (auto-verify if GA code exists)
4. Click "Verify"

---

## Step 2: Submit Sitemap (1 min)

1. In GSC, go to **Sitemaps** (left sidebar)
2. Click "Add/test sitemap"
3. Enter: `sitemap.xml`
4. Click "Submit"

**GSC will show:**
```
Sitemap received
10 URLs included
Status: Success
```

---

## Step 3: Monitor Coverage (ongoing, 1 min/day)

1. In GSC, go to **Coverage** (left sidebar)
2. Check status of your URLs
   - **Indexed:** Good (goal: 10/10)
   - **Not indexed:** Wait 7-14 days
   - **Errors:** Fix and resubmit

---

## Testing Before Submission

Verify the files are accessible:
- Sitemap: `https://mowology.ca/sitemap.xml` (should show XML)
- robots.txt: `https://mowology.ca/robots.txt` (should show text rules)

---

## URLs Being Indexed

```
1. https://mowology.ca/
2. https://mowology.ca/services
3. https://mowology.ca/services/commercial-landscape-maintenance
4. https://mowology.ca/services/hedge-trimming
5. https://mowology.ca/services/strata-landscaping-maintenance
6. https://mowology.ca/about
7. https://mowology.ca/portfolio
8. https://mowology.ca/contact
9. https://mowology.ca/quote
10. https://mowology.ca/get-free-quote
```

---

## Expected Timeline

| Timeline | Event |
|----------|-------|
| Immediately | Sitemap processed, URLs queued for crawling |
| 1-3 days | Homepage and main pages indexed |
| 3-14 days | Service pages and landings indexed |
| 2-4 weeks | All pages fully indexed |

---

## If Something Goes Wrong

**"Sitemap couldn't be read"**
→ Visit `https://mowology.ca/sitemap.xml` in browser. If you see XML, it's working.

**"0 indexed pages after 1 week"**
→ Normal for new domains. Wait 2-3 weeks, then use "Request crawl" in GSC.

**"robots.txt is blocking crawling"**
→ Shouldn't happen. Current robots.txt allows all public pages.

**"Some pages not indexed"**
→ Use URL Inspection tool in GSC to request crawl. Wait 2-4 weeks.

---

## More Help

- **Detailed setup:** See `/GOOGLE_SEARCH_CONSOLE_GUIDE.md`
- **Summary & FAQ:** See `/SITEMAP_SUMMARY.md`
- **Full report:** See `/SITEMAP_COMPLETION_REPORT.md`

---

**You're ready! Go to GSC and submit the sitemap.** 🚀
