# Portfolio Action Buttons - Quick Test Guide

## Changes Made

**Files Updated:**
1. `/public/crm/portfolio/index.php` — Added text labels to action buttons
2. `/public/crm/css/mowology-brand.css` — Added styling for labeled buttons

## How to Test

### Step 1: Clear Browser Cache
```
macOS:  Cmd+Shift+R (hard refresh)
Chrome: Ctrl+Shift+R
Firefox: Ctrl+Shift+R
```

### Step 2: Login to CRM
Navigate to: `https://mowology.ca/crm/portfolio/?tab=items`

### Step 3: Verify Button Changes

#### Desktop View (Wide Screen)
You should see action buttons that look like:
```
┌──────────────┬──────────────┬──────────────┐
│ 👁 View      │ ✎ Edit       │ 🗑 Delete    │
└──────────────┴──────────────┴──────────────┘
```

Each button should:
- ✅ Display an icon AND a text label
- ✅ Be color-coded:
  - View button = Blue
  - Edit button = Orange
  - Delete button = Red
- ✅ Change color on hover (darker shade)
- ✅ Have proper spacing between buttons

#### Mobile View (< 576px wide)
Resize your browser window to mobile width. Buttons should:
- ✅ Show icons only (no labels)
- ✅ Keep the same color scheme
- ✅ Have compact spacing

### Step 4: Test Each Action

**View Button:**
1. Click the "👁 View" button
2. Verify that the project details page loads

**Edit Button:**
1. Click the "✎ Edit" button
2. Verify that the edit form appears

**Delete Button:**
1. Click the "🗑 Delete" button
2. Verify that a confirmation dialog appears
3. Click "Cancel" to exit (don't actually delete!)

### Step 5: Verify Hover States

Hover your mouse over each button:
- ✅ View button should darken to darker blue
- ✅ Edit button should darken to darker orange
- ✅ Delete button should darken to darker red
- ✅ All should have smooth transitions (0.2s)

## Expected Results

| Element | Expected | Status |
|---------|----------|--------|
| View Button | Blue with icon + label | ☐ |
| Edit Button | Orange with icon + label | ☐ |
| Delete Button | Red with icon + label | ☐ |
| Button spacing | 8px gap on desktop | ☐ |
| Mobile labels | Hidden on < 576px | ☐ |
| Hover states | Color darkens | ☐ |
| Click actions | Links work correctly | ☐ |

## Troubleshooting

**Buttons still showing as icons only?**
- Hard refresh the page (Cmd+Shift+R or Ctrl+Shift+R)
- Check browser cache in DevTools (Settings → Network → Disable cache)

**Colors not showing?**
- Check that `/public/crm/css/mowology-brand.css` loaded
- Open DevTools and check Network tab for CSS file
- Verify CSS file has no errors

**Labels not showing on mobile?**
- Resize browser to width < 576px
- Check DevTools responsive mode
- Verify @media query is loading

**Buttons not responding to clicks?**
- Check browser console (F12) for JavaScript errors
- Verify URLs in View/Edit buttons are correct
- Test in a different browser

## Performance Notes

- CSS-only solution (no JavaScript overhead)
- Smooth hover transitions (0.2s)
- Flexbox layout (efficient rendering)
- No additional HTTP requests

## CSS Classes Reference

```css
.mw-action-buttons      /* Container for button group */
.mw-action-btn          /* Base button styling */
.mw-action-label        /* Text label for button */
.mw-action-view         /* View button (blue) */
.mw-action-edit         /* Edit button (orange) */
.mw-action-delete       /* Delete button (red) */
```

## Next Steps

After confirming buttons work:
1. ✅ Check that all 3 buttons are clearly visible
2. ✅ Verify color scheme is consistent
3. ✅ Test on mobile device (if available)
4. ✅ Document any issues or feedback

---

**Created:** February 8, 2026
**Time to test:** ~5 minutes
