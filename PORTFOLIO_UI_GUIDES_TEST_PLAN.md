# Portfolio UI Guides - Test Plan & Verification

## Overview
Comprehensive UI guides have been added to all 7 tabs of the portfolio management system to help users understand each feature and workflow.

**Commit:** `20405cc`
**Deployed:** Awaiting cPanel auto-deployment

---

## Test Checklist

### ✅ Pre-Deployment (Local)
- [x] Guide box CSS styles defined in `mowology-brand.css`
- [x] All 7 tabs have appropriate guide content
- [x] Tooltips properly structured with visibility hidden by default
- [x] Responsive design for mobile (768px breakpoint)
- [x] Code committed to git

### ⏳ Post-Deployment (Live Server)

#### Tab 1: Upload
- [ ] **Guide Box Visible:** "Upload & Organize Marketing Assets" appears at top
- [ ] **Help Icon on Recent Uploads:** Green circle with "?" shows tooltip on hover
- [ ] **Tooltip Text:** "Shows your 20 most recent uploads and their approval status..."
- [ ] **Photo Quality Tip:** Lightbulb icon with tip about high-quality photos
- [ ] **Drag/Drop Works:** Can drag photos into upload zone
- [ ] **File Selection:** Clicking "click to select" opens file browser
- [ ] **Recent Uploads Table:** Shows thumbnail, uploader, status, date

**Expected Behavior:**
- Guide box appears below "Upload Photos" heading with green left border
- Help icon hovers over "Recent Uploads" section
- Tooltip appears above icon when mouse hovers
- All styling uses Mowology brand colors

---

#### Tab 2: Review (Admin Only)
- [ ] **Guide Box Visible:** "Quality Control Workflow" appears at top
- [ ] **Approve Button:** Green checkmark button with tooltip "Approve this photo for portfolio use"
- [ ] **Reject Button:** Red X button with tooltip "Reject this photo (too blurry...)"
- [ ] **Pending Count Alert:** Shows number of photos awaiting review
- [ ] **Help Text:** Clock icon with explanation about review frequency
- [ ] **Approve Functionality:** Clicking approve changes photo status
- [ ] **Reject Functionality:** Clicking reject removes photo from queue

**Expected Behavior:**
- Tooltips appear on button hover
- Buttons positioned over photo thumbnails (top-right corner)
- Approve/reject actions complete without page reload (AJAX)
- Status updates reflect immediately

---

#### Tab 3: Favorites
- [ ] **Guide Box Visible:** "Curated Best Work" appears at top
- [ ] **Empty State Message:** Shows when no favorites yet
- [ ] **Help Text on Empty:** Heart icon with "Click the heart icon on photos..."
- [ ] **Favorite Photos Grid:** Displays grid of favorite media items
- [ ] **Heart Icon on Items:** Favorite button visible on each photo
- [ ] **Toggle Favorite:** Clicking heart removes from favorites

**Expected Behavior:**
- Guide box has green left border and info icon
- Empty state shows helpful guidance
- Favorite photos display in responsive grid
- Heart icon toggles on/off

---

#### Tab 4: Portfolio Items
- [ ] **Guide Box Visible:** "Manage Published Projects" appears at top
- [ ] **"Featured" Column Header:** Has green help icon with tooltip
- [ ] **Featured Tooltip:** "Featured projects appear first on your public portfolio page"
- [ ] **"Order" Column Header:** Has green help icon with tooltip
- [ ] **Order Tooltip:** "Display order on the public portfolio. Lower numbers appear first."
- [ ] **Create New Button:** "+ New Project" button functional
- [ ] **Search Works:** Can search by project name
- [ ] **Filter by Status:** Can filter draft vs. published
- [ ] **View/Edit/Delete Actions:** Buttons visible and functional

**Expected Behavior:**
- Help icons appear inline with column headers
- Tooltips position above headers on hover
- All CRUD operations work
- Filters apply without page reload

---

#### Tab 5: GSC Insights (Admin Only)
- [ ] **Guide Box Visible:** "Search Performance Analytics" appears at top
- [ ] **Sync Now Button:** Has tooltip "Manually pull latest data from Google Search Console"
- [ ] **Data Timestamp:** Shows "Data as of [date]"
- [ ] **Sync Frequency Help:** Clock icon explaining daily sync
- [ ] **Top Queries Section:** Has help icon explaining impressions/CTR
- [ ] **Top Pages Section:** Has help icon explaining best-performing content
- [ ] **Low CTR Opportunities:** Yellow warning box with help icon
- [ ] **Optimization Help:** Tooltip explains how to improve CTR

**Expected Behavior:**
- All tooltips appear on hover with dark background
- Data displays if GSC connected
- Sync button triggers data pull
- Help icons don't interfere with data readability

---

#### Tab 6: Recommendations (Admin Only)
- [ ] **Guide Box Visible:** "SEO Content Strategy" appears at top
- [ ] **Total Stat Card:** Has help icon explaining "Total recommendations generated"
- [ ] **New Stat Card:** Has help icon explaining "Not yet reviewed"
- [ ] **Accepted Stat Card:** Has help icon explaining "You've approved these"
- [ ] **Applied Stat Card:** Has help icon explaining "Completed and published"
- [ ] **Filter Section:** Has help icon explaining filtering options
- [ ] **Generate Button:** Has tooltip "Analyze your site and create new SEO recommendations"
- [ ] **Targeting Settings:** Has tooltip "Manage geographic and seasonal targets"
- [ ] **Generate Recommendations:** Works (if recommendations data available)
- [ ] **Targeting Settings Collapse:** Expands/collapses properly

**Expected Behavior:**
- Help icons on stat cards are small and don't clutter layout
- All filter dropdowns function
- Generate button shows loading state
- Settings panel expands on click

---

#### Tab 7: ROI Dashboard (Admin Only)
- [ ] **Guide Box Visible:** "Track Lead-to-Customer Conversion" appears at top
- [ ] **Start Date Label:** Has help icon "Beginning date for this analysis"
- [ ] **End Date Label:** Has help icon "End date for this analysis"
- [ ] **Filter Source Label:** Has help icon "Show leads from a specific source..."
- [ ] **Conversion Funnel Header:** Has help icon explaining funnel visualization
- [ ] **Date Filter Works:** Can change start/end dates
- [ ] **Source Filter Works:** Can filter by traffic source
- [ ] **Funnel Chart Updates:** Reflects filtered data
- [ ] **All Conversions Display:** Leads, quotes, deals visible

**Expected Behavior:**
- Help icons positioned next to labels
- Tooltips don't interfere with form inputs
- Date changes update funnel
- All funnel stages display correctly

---

## Visual Verification

### Help Icon Styling
- **Color:** Mowology green (#2D8659)
- **Size:** 18px diameter circle
- **Font:** Bold white "?" character
- **Hover:** Darker green (#1A5F4A)
- **Cursor:** Changes to help cursor on hover

### Tooltip Styling
- **Background:** Dark forest color (#0D3B2E)
- **Text:** White, size 13px
- **Position:** Above element with arrow pointer
- **Max Width:** 250px (mobile: 200px)
- **Padding:** 8px 12px
- **Border Radius:** 6px
- **Box Shadow:** Subtle drop shadow

### Guide Boxes
- **Background:** Light green gradient (rgba #2D8659 with opacity)
- **Left Border:** 4px solid green (#2D8659)
- **Border Radius:** 4px
- **Icon:** Blue info icon (feather)
- **Text:** Strong title + description
- **Font Size:** 13px body text

### Responsive Behavior (Mobile)
- [ ] Tooltips reposition for 768px and below
- [ ] Help icons don't overlap content
- [ ] Guide boxes stack properly
- [ ] Forms remain usable on small screens
- [ ] All interactive elements are touch-friendly

---

## Functionality Testing

### CSS Loading
```bash
# Verify CSS file is linked in appstack_head.php
curl https://mowology.ca/crm/css/mowology-brand.css | grep -i "mw-help-icon"
```

### Tooltip Interactivity
- [ ] Hover activates tooltip
- [ ] Tooltip disappears on mouse out
- [ ] Multiple tooltips don't show simultaneously
- [ ] Tooltips don't cause layout shift

### Guide Box Visibility
- [ ] Guide boxes render without PHP errors
- [ ] Text is escaped properly (no HTML injection)
- [ ] Icons render correctly (feather library)
- [ ] Colors apply correctly

---

## Browser Compatibility
- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

---

## Performance Checks
- [ ] No console errors related to guides
- [ ] No duplicate tooltips in DOM
- [ ] CSS doesn't increase page load time significantly
- [ ] Tooltips appear instantly on hover
- [ ] No memory leaks from hover events

---

## Accessibility Verification
- [ ] Help icons have proper title attributes
- [ ] Tooltip text is readable by screen readers
- [ ] No keyboard focus issues
- [ ] Color contrast meets WCAG standards
- [ ] Tooltips don't hide critical content

---

## Known Limitations

1. **Feather Icons Error:** Pre-existing "toSvg is undefined" error in app.js
   - Not caused by UI guide changes
   - Doesn't affect guide display
   - Related to older Feather Icons version

2. **GSC Data:** Requires Google Search Console connection
   - If not connected, shows "Connect GSC" button
   - Guides still visible when data unavailable

3. **Recommendations:** Requires SEO recommendations to be generated
   - Table shows "No recommendations" if none exist
   - Guides visible regardless of data

4. **ROI Dashboard:** Requires quote/job data in system
   - Shows empty funnel if no data
   - Guides visible regardless

---

## Testing Credentials
Use your admin account at: https://mowology.ca/crm/portfolio/index.php

---

## Post-Deployment Sign-Off
Once all tests pass:
- [ ] User confirms guides are helpful
- [ ] No UI/UX regressions observed
- [ ] All 7 tabs functional
- [ ] Tooltips display correctly
- [ ] Mobile responsive working
- [ ] No console errors

**Tested By:** ____________________
**Date:** ____________________
**Status:** ✅ Ready for Production / ❌ Issues Found

---

## Rollback Instructions
If issues occur:
```bash
git revert 20405cc
# Then push to trigger deployment
```

This will remove UI guides but keep all portfolio functionality intact.

---

## Next Steps
1. Wait for cPanel auto-deployment to complete
2. Navigate to portfolio and verify each tab
3. Test hovering over help icons
4. Verify all tooltips and guide boxes appear
5. Test form functionality with new guides
6. Check mobile responsiveness
7. Verify no console errors
8. Sign off on test plan

