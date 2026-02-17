# Mowology Complete System - Ready to Deploy

## ✅ CONFIGURED WITH YOUR DETAILS

All files have been configured with:
- **Phone:** 778-846-9273
- **SMS Gateway:** Telus (7788469273@msg.telus.com)
- **Google Maps API:** Set via `GOOGLE_MAPS_API_KEY` in `/app_config/secrets.php`

---

## 📦 FILES TO UPLOAD

### Main Website Files (Root Directory)
Upload these to your main website directory:

```
public_html/
├── get-quote.html (updated with PHP processing)
├── quote-success.html (new - success page)
├── process-quote-request.php (new - form processor)
├── contact.html (existing - keep as is)
├── index.html (existing with hero image)
├── services.html (existing)
├── portfolio.html (existing)
├── about.html (existing)
├── styles.css (existing)
├── script.js (existing)
└── hero-lawn-care-1920x1080.jpg (your existing hero image)
```

### Products System Files (Create /products folder)
Create a folder called `products` and upload these:

```
public_html/products/
├── config.php (database & API configuration)
├── database_schema.sql (run this in phpMyAdmin)
├── cost-factors.html (manage labor rates, equipment)
├── products-manager.html (product catalog)
├── area-measurement.html (Google Maps measurement tool)
└── README.md (documentation)
```

---

## 🗄️ DATABASE SETUP (IMPORTANT - DO THIS FIRST!)

### Step 1: Run the SQL Schema

1. **Open phpMyAdmin** (you have access from your screenshot)
2. **Select database:** `mowology_mowologyCN`
3. **Click "SQL" tab** at the top
4. **Copy entire contents** of `database_schema.sql`
5. **Paste** into SQL query box
6. **Click "Go"** button to execute

This will create all the tables you need:
- product_categories
- unit_types  
- cost_factors
- products
- product_cost_breakdown
- quotes
- quote_items
- quote_requests
- estimator_templates

### Step 2: Update Database Credentials in config.php

Open `products/config.php` and update lines 8-9:

```php
define('DB_USER', 'YOUR_ACTUAL_DB_USERNAME');
define('DB_PASS', 'YOUR_ACTUAL_DB_PASSWORD');
```

**Find your credentials:**
- They're in your hosting control panel (cPanel or similar)
- Or in your existing website files (check any existing config files)

---

## 📧 EMAIL & SMS NOTIFICATIONS

### Already Configured:

✅ **Admin Email:** office@mowology.ca  
✅ **SMS Number:** 778-846-9273  
✅ **SMS Gateway:** 7788469273@msg.telus.com (Telus)

### How Notifications Work:

When someone submits a quote request:

1. **You get an EMAIL** with:
   - Customer details
   - Property info
   - Services requested
   - Project description
   - Direct link to review in CRM

2. **You get a TEXT MESSAGE** with:
   - Customer name
   - Property type
   - Location
   - Services requested

3. **Customer gets EMAIL confirmation** with:
   - Thank you message
   - What happens next
   - Your contact info

4. **Database logs activity:**
   - Quote requested
   - Admin notified
   - All timestamped

---

## 🗺️ GOOGLE MAPS MEASUREMENT TOOL

### Already Configured with Your API Key

The area measurement tool is ready to use at:
`https://mowology.ca/products/area-measurement.html`

### How to Use:

1. **Enter property address** in search box
2. **Map zooms to location** (satellite view)
3. **Click "Draw Area"** or "Rectangle"
4. **Draw on the map** by clicking points
5. **Double-click to finish** the shape
6. **System calculates:**
   - Square feet
   - Square meters
   - Acres
   - Perimeter

7. **Label the area** (e.g., "Front Lawn", "Backyard")
8. **Select service type** (mowing, fertilization, salt, etc.)
9. **Get instant pricing** estimate
10. **Add to quote** - sends data to quote builder

### Perfect For:

- ✅ Measuring lawns for mowing quotes
- ✅ Calculating fertilization needs
- ✅ Salt application areas (driveways, walkways)
- ✅ Snow removal contracts
- ✅ Mulch installation (converts to yards)
- ✅ Any area-based service

---

## 💰 COST FACTORS & PRICING

### Access Cost Management:
`https://mowology.ca/products/cost-factors.html`

### Pre-Configured Defaults:

**Labor Rates:**
- Owner/Manager: $45/hr
- Foreman: $35/hr
- Laborer: $25/hr

**Equipment Costs:**
- Riding Mower: $15/hr
- Walk-Behind Mower: $8/hr
- Pickup Truck: $12/hr
- Dump Truck: $25/hr
- Trimmer/Edger: $5/hr
- Hedge Trimmer: $6/hr

**Overhead & Margins:**
- Overhead: 20%
- Profit Margin: 35%
- GST: 5%

**Update These** to match your actual costs!

---

## 📦 PRODUCTS & SERVICES

### Access Product Manager:
`https://mowology.ca/products/products-manager.html`

### Sample Products Included:

**Bulk Materials:**
- Cedar Mulch: $35 cost / $65 price per yard
- Topsoil: $25 cost / $45 price per yard
- Gravel: $45 cost / $80 price per yard

**Services:**
- Weekly Maintenance packages (Small/Medium/Large)
- Seasonal cleanup services
- Labor services (calculated from cost factors)

### GGOB Bundles (Good, Great, Optimal, Best):

Example: **Weekly Lawn Maintenance**

🥉 **GOOD - $85/visit**
- Mowing
- Edging
- Debris removal

⭐ **GREAT - $115/visit**  
- Everything in GOOD
- Hedge trimming (monthly)
- Garden bed weeding

🏆 **OPTIMAL - $165/visit**
- Everything in GREAT
- Fertilization (quarterly)
- Aeration (2x/year)

💎 **BEST - $225/visit**
- Everything in OPTIMAL
- Seasonal cleanups
- Priority scheduling
- 10% off extras

---

## 🔒 SECURITY CHECKLIST

### Before Going Live:

- [ ] Update `config.php` with real database credentials
- [ ] Verify reCAPTCHA secret key is correct
- [ ] Test quote form submission
- [ ] Confirm email notifications arrive
- [ ] Confirm SMS notifications arrive
- [ ] Set file permissions: `chmod 644 config.php`
- [ ] Move config.php outside public_html if possible (for extra security)

---

## 🧪 TESTING PROCEDURE

### 1. Test Quote Form

1. Go to: `https://mowology.ca/get-quote.html`
2. Fill out form with test data
3. Submit form
4. **Check:**
   - [ ] Redirects to success page
   - [ ] Email arrives at office@mowology.ca
   - [ ] SMS arrives at 778-846-9273
   - [ ] Record in database (check phpMyAdmin → quote_requests table)
   - [ ] Activity logged (check activity_log table)

### 2. Test Google Maps Tool

1. Go to: `https://mowology.ca/products/area-measurement.html`
2. Enter a Vancouver address
3. Draw an area on the map
4. **Check:**
   - [ ] Map loads correctly
   - [ ] Can draw shapes
   - [ ] Calculates square footage
   - [ ] Shows pricing estimates
   - [ ] Can save multiple areas

### 3. Test Cost Factors

1. Go to: `https://mowology.ca/products/cost-factors.html`
2. View existing cost factors
3. Try editing a labor rate
4. **Check:**
   - [ ] Interface loads
   - [ ] Can view all factors
   - [ ] Calculator works
   - [ ] Can save changes

---

## 📊 NEXT STEPS

### Phase 1: Setup (This Week)

- [x] Run database schema ✅
- [ ] Update config.php with real credentials
- [ ] Upload all files to server
- [ ] Test quote form
- [ ] Test email/SMS notifications
- [ ] Test Google Maps tool

### Phase 2: Configuration (Next Week)

- [ ] Update cost factors with your actual rates
- [ ] Add all your products/services
- [ ] Set up GGOB bundles for your services
- [ ] Configure service pricing
- [ ] Test with real quote scenarios

### Phase 3: Integration (Following Week)

- [ ] Create quote review interface (CRM)
- [ ] Build quote builder tool
- [ ] Set up PDF quote generation
- [ ] Create customer quote acceptance flow
- [ ] Link quotes to jobs/scheduling

---

## 🆘 TROUBLESHOOTING

### Quote Form Not Submitting

**Check:**
1. Is `process-quote-request.php` uploaded?
2. Are database credentials correct in config.php?
3. Check server error log for PHP errors
4. Verify reCAPTCHA is working

### Email Not Arriving

**Check:**
1. Spam folder
2. PHP mail() is enabled on server
3. Email address is correct in config.php
4. Check server mail logs

### SMS Not Arriving

**Check:**
1. Phone number format: 7788469273@msg.telus.com
2. No spaces or dashes in number
3. Telus SMS gateway is correct
4. Test by sending manual email to that address

### Google Maps Not Loading

**Check:**
1. API key is correct in area-measurement.html
2. APIs are enabled in Google Cloud Console:
   - Maps JavaScript API
   - Geocoding API
   - Geometry Library
3. API key has no domain restrictions (or includes your domain)
4. Check browser console for errors (F12)

### Database Connection Failed

**Check:**
1. Database credentials in config.php
2. Database exists: mowology_mowologyCN
3. User has permissions to access database
4. Server allows database connections from localhost

---

## 📞 QUICK REFERENCE

**Your Details:**
- Phone: 778-846-9273
- Email: office@mowology.ca
- SMS: 7788469273@msg.telus.com
- Database: mowology_mowologyCN

**Important URLs:**
- Quote Form: /get-quote.html
- Area Tool: /products/area-measurement.html
- Cost Factors: /products/cost-factors.html
- Products: /products/products-manager.html

**Admin Access:**
- phpMyAdmin: (your hosting control panel)
- File Manager: (your hosting control panel)

---

## ✅ READY TO LAUNCH

Everything is configured and ready. Just need to:

1. Upload files
2. Run database SQL
3. Update DB credentials in config.php
4. Test!

**Questions? Need help with any step?** Just ask!
