# Dashboard & Territory Map Setup

## Overview

The dashboard and territory map have been enhanced with:
- **Live data** pulled directly from your database (quotes, jobs, inquiries)
- **Real-time statistics** that update as you work
- **Interactive territory map** with color-coded pins for jobs, quotes, and quote requests
- **Quote request visualization** with urgency-based color coding

---

## Dashboard Updates

### What Changed

The dashboard now displays **real data** instead of placeholder statistics:

| Card | Data Source |
|------|-------------|
| **New Inquiries** | Quote requests with status `new` or `reviewing` |
| **Quotes Sent** | Quotes with status `sent` |
| **Jobs Accepted** | Quotes with status `accepted` |
| **Active Jobs** | Jobs with status `scheduled` or `in_progress` |

### Recent Activity

Shows the 5 most recent quotes and jobs created, with:
- Who created it (full name)
- When it was created (date/time)
- Quote/Job number
- Links to open in relevant sections

### Incoming Quote Requests

Displays up to 6 pending quote requests, sorted by:
1. Urgency (ASAP → Soon → Inquiring)
2. Creation date (newest first)

Shows for each request:
- Contact name
- Services requested
- Time since created
- Current status
- Property address (if available)

---

## Territory Map

### Overview

Located at: `https://mowology.ca/crm/map_appstack.php`

The map displays three types of data:

| Type | Color | Icon | Represents |
|------|-------|------|-----------|
| **Jobs** | Green (✓) | Checkmark | Active jobs (scheduled/in progress) |
| **Quotes** | Dark Green (📍) | Pin | Active quotes (sent/accepted) |
| **Requests - ASAP** | Orange (🔥) | Fire | Urgent quote requests |
| **Requests - Soon** | Amber (⏰) | Clock | Soon quote requests |
| **Requests - Inquiring** | Blue (❓) | Question | General inquiries |

### Features

#### Layer Filtering

Three toggle buttons control which data layers appear:
- **Jobs** — Toggle job locations on/off
- **Quotes** — Toggle quote locations on/off
- **Requests** — Toggle quote request locations on/off

Click any button to show/hide that layer.

#### Interactive Pins

- **Hover** on a pin to see a tooltip with details
- **Click** on a quote request pin to open it in the quote workflow
- **Click** on a property in the list to see its details

#### Two-Column View

**Left: Active Properties**
- Properties with active jobs or quotes
- Shows count of jobs and quotes
- Click to focus on that property

**Right: Pending Quote Requests**
- All quote requests awaiting response
- Color-coded urgency badges
- Address shown (if available)
- Click to open in quote workflow

#### Legend

Bottom-right corner shows:
- Pin colors for each data type
- What each icon/color represents
- Always visible for reference

---

## Data Structure

### Database Queries

#### Properties Map Data
```sql
SELECT properties with active jobs/quotes,
grouped by property,
ordered by activity level,
limited to 50 most active
```

#### Quote Requests Data
```sql
SELECT quote_requests with status 'new' or 'reviewing',
joined with contacts and properties,
grouped and ordered by urgency then date
```

### Data Columns Used

**Properties:**
- id, address, city, province, latitude, longitude
- active_jobs (count), active_quotes (count)

**Quote Requests:**
- id, urgency, status, created_at
- first_name, last_name (contact)
- address, city (property)
- services (comma-separated)

---

## Color Scheme

### Mowology Brand Colors

| Color | Hex | Usage |
|-------|-----|-------|
| Green (Primary) | `#2D8659` | Active jobs, default |
| Dark Green | `#0D3B2E` | Quotes |
| Lime (Active) | `#7FD858` | Navigation highlights |
| Orange (CTA) | `#e85d04` | ASAP requests, urgent |
| Blue | `#3B82F6` | General inquiries |
| Amber | `#F59E0B` | Soon requests |

### Badge Styling

**Urgency Badges** appear on quote request cards:
- **ASAP** — Red background, dark text
- **SOON** — Yellow background, dark text
- **INQUIRING** — Blue background, light text

---

## Live Updates

### How Data Stays Current

Data loads **every time** you visit the pages:
- Dashboard refreshes when you navigate to it
- Map refreshes when you navigate to it
- No manual refresh needed

### Making Data Even More Live

To add **real-time updates** (optional future enhancement):

1. Add a refresh button with: `<button onclick="location.reload()">Refresh</button>`
2. Use JavaScript interval: `setInterval(() => location.reload(), 60000)` (every 60 seconds)
3. Use WebSockets for true real-time push updates

---

## Troubleshooting

### Dashboard Cards Show 0 for Everything

**Reason:** No data in database yet.
**Solution:** Create some quotes and jobs to see live data.

### Territory Map Shows "No properties"

**Reason:** Properties need coordinates.
**Solution:** When adding properties, fill in latitude/longitude from address geocoding.

### Quote Requests Not Appearing on Map

**Reason:**
- No properties linked to requests, OR
- Properties have no coordinates

**Solution:**
- Make sure quote requests are linked to properties
- Add latitude/longitude to those properties

### Pin Colors Don't Match Legend

**Reason:** CSS not loading or browser cache.
**Solution:**
- Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
- Clear browser cache

---

## File Changes

### Modified Files

| File | Changes |
|------|---------|
| `/crm/dashboard_appstack.php` | Real data queries for stats and activity |
| `/crm/map_appstack.php` | Complete territory map with quote requests |
| `/crm/css/mowology-brand.css` | Map legend and urgency badge styles |

### Database Queries

No database schema changes required. Uses existing tables:
- `quotes` (status, created_by, quote_number)
- `jobs` (property_id, status, job_number)
- `quote_requests` (urgency, status, contact_id, property_id)
- `contacts` (first_name, last_name)
- `properties` (address, city, province, latitude, longitude)

---

## Performance Notes

### Query Optimization

- Properties query limited to 50 most active
- Quote requests filtered by status in database
- Proper indexes on status/property fields recommended
- Both queries use GROUP BY for efficiency

### Browser Performance

- SVG-based map (lightweight, scales to any size)
- Grid-based pin layout (no real geolocation yet)
- Smooth transitions on hover
- Interactive tooltips and click handlers

### Improvement Ideas

1. **Real Geocoding:** Use Google Maps API to place pins at actual coordinates
2. **Clustering:** Group nearby pins when zoomed out
3. **Real-time Updates:** WebSocket push instead of page refresh
4. **Filtering:** Search by address, service type, date range
5. **Route Planning:** Optimize job sequences by geography

---

## API Integration (Future)

When ready, can integrate with:
- **Google Maps API** — Real coordinates and map rendering
- **Here Maps API** — Routing and distance optimization
- **Mapbox** — Custom styling and clustering
- **Route optimization** — Order jobs by proximity

---

## Security

- All data bound to logged-in user (requires `requireLogin()`)
- HTML escaped with `h()` function throughout
- Prepared statements in all database queries
- Admin verification on all operations

---

## Support

For bugs or questions:
1. Check browser console (F12) for JavaScript errors
2. Check database for data consistency
3. Verify all required tables exist
4. Ensure coordinates are in properties table

---

## Changelog

**v1.0 (Feb 6, 2026)**
- ✨ Live dashboard statistics (quotes, jobs, inquiries)
- ✨ Territory map with quote requests visualization
- 🎨 Color-coded pins with urgency badges
- 🔄 Real-time data pulls from database
- 🗺️ Interactive legend and layer filtering
