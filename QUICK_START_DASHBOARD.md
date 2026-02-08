# Quick Start: Dashboard & Territory Map

## What's New

✅ **Dashboard** now shows LIVE data from your database
✅ **Territory Map** displays jobs, quotes, and quote requests with color-coded pins
✅ **Quote Requests** integrated into map with urgency-based colors

---

## Dashboard at a Glance

```
https://mowology.ca/crm/dashboard_appstack.php
```

### The Four Stats Cards

| Card | Shows | Updates |
|------|-------|---------|
| 🔔 New Inquiries | Quote requests waiting to be reviewed | Auto (from DB) |
| 📝 Quotes Sent | Quotes awaiting customer response | Auto (from DB) |
| ✅ Jobs Accepted | Converted quotes ready for work | Auto (from DB) |
| 🚀 Active Jobs | Jobs currently scheduled or in progress | Auto (from DB) |

### Recent Activity Timeline

Shows last 5 activities:
- Quotes created
- Jobs created
- Who did it
- When it happened

---

## Territory Map at a Glance

```
https://mowology.ca/crm/map_appstack.php
```

### What You See

**Top of map:** Three filter buttons
- Jobs (on/off)
- Quotes (on/off)
- Requests (on/off)

**On the map:** Color-coded pins
- 🟢 Green = Jobs
- 🟢 Dark Green = Quotes
- 🟠 Orange = ASAP Requests
- 🟡 Yellow = Soon Requests
- 🔵 Blue = Inquiring Requests

**Bottom right:** Legend showing what each color means

**Below map:** Two lists
- Active properties (left)
- Pending quote requests (right)

---

## Color Reference

### Quote Request Urgency

| Urgency | Color | Means |
|---------|-------|-------|
| **ASAP** | 🟠 Orange | Very urgent, needs immediate attention |
| **SOON** | 🟡 Amber | Coming up soon, respond within days |
| **INQUIRING** | 🔵 Blue | General inquiry, standard response time |

---

## How to Use

### Check Dashboard for Overview

1. Log in to CRM
2. Go to Dashboard
3. See instant numbers for quotes, jobs, inquiries, active work
4. Check Recent Activity to see what team members are doing

### Find Properties on Territory Map

1. Go to Territory Map
2. Look at the "Active Properties" list (left side)
3. Click any property to see details
4. Or toggle layers to hide/show jobs, quotes, or requests

### Process Quote Requests

1. Go to Territory Map
2. Look at the "Pending Quote Requests" list (right side)
3. Click any request to open in quote workflow
4. OR click the pin on the map to jump to that request

---

## FAQ

**Q: Why do some quote requests not show on the map?**
A: They need a property address with coordinates (latitude/longitude). Add the address and geocode it.

**Q: Can I see specific types of items on the map?**
A: Yes! Use the filter buttons at the top to toggle Jobs, Quotes, and Requests on/off.

**Q: How often does the data update?**
A: Every time you refresh the page. Data is always pulled fresh from the database.

**Q: What if the colors look wrong?**
A: Do a hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac).

**Q: Can I zoom on the map?**
A: Current version shows a grid layout. Future versions will support real map with zoom/pan.

---

## Related Pages

- Dashboard: `/crm/dashboard_appstack.php`
- Territory Map: `/crm/map_appstack.php`
- Quote Requests: `/crm/products/quote-requests.php`
- Quote Workflow: `/crm/quote-workflow.php`

---

## Next Steps

1. **Add property coordinates** to your properties (latitude/longitude) for real map visualization
2. **Create some test data** — Add quotes and jobs to see the dashboard in action
3. **Process quote requests** — Click one to open in the quote workflow
4. **Use filters** — Toggle layers on the map to focus on what matters

---

## Need Help?

See: `DASHBOARD_MAP_SETUP.md` for detailed documentation
