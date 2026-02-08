# Mowology CRM - Phase 1 Installation Guide

## 🎉 Welcome to Your Custom CRM System!

This is **Phase 1: Foundation** - a secure login system with a professional dashboard for your landscaping and snow removal business.

---

## 📦 What's Included in Phase 1

✅ **Secure Authentication System**
- Password hashing (bcrypt)
- Session management
- CSRF protection
- SQL injection protection (prepared statements)

✅ **Professional Dashboard**
- Business statistics overview
- Activity logging
- Modern, distinctive design
- Mobile-responsive layout

✅ **Database Schema**
- Users table
- Clients table (ready for Phase 2)
- Property measurements table
- Quotes table
- Activity log table

---

## 🚀 Installation Steps

### Step 1: Create MySQL Database

1. **Log into your cPanel** at your Canadian hosting provider
2. **Go to "MySQL Databases"**
3. **Create a new database:**
   - Database Name: `landscape_crm` (or your prefix + landscape_crm)
   - Click "Create Database"
4. **Create a database user:**
   - Username: Choose a username (remember it!)
   - Password: Create a strong password (remember it!)
   - Click "Create User"
5. **Add user to database:**
   - Select the user you just created
   - Select the database you just created
   - Grant "ALL PRIVILEGES"
   - Click "Add"

📝 **IMPORTANT:** Write down these credentials:
- Database Name: `__________________`
- Database Username: `__________________`
- Database Password: `__________________`
- Database Host: Usually `localhost`

---

### Step 2: Import Database Schema

1. **Go to "phpMyAdmin"** in your cPanel
2. **Select your database** (`landscape_crm`) from the left sidebar
3. **Click the "Import" tab** at the top
4. **Choose file:** Select `database_schema.sql`
5. **Click "Go"** at the bottom
6. ✅ You should see "Import has been successfully finished"

---

### Step 3: Configure Database Connection

1. **Open `config.php`** in a text editor
2. **Update these lines** with your actual database credentials:

```php
define('DB_HOST', 'localhost');  // Usually 'localhost'
define('DB_NAME', 'your_actual_database_name');  // From Step 1
define('DB_USER', 'your_actual_username');  // From Step 1
define('DB_PASS', 'your_actual_password');  // From Step 1
```

3. **Save the file**

---

### Step 4: Upload Files to Your Server

**Option A: Using cPanel File Manager (Recommended)**

1. **Go to "File Manager"** in cPanel
2. **Navigate to `public_html`** (or your website's root directory)
3. **Create a new folder** called `crm` (optional, for organization)
4. **Upload all PHP files:**
   - config.php
   - auth.php
   - login.php
   - logout.php
   - dashboard.php
   - clients.php
   - map.php
   - quotes.php
5. **Click "Upload"** and select all files at once

**Option B: Using FTP**

1. Connect to your server using an FTP client (like FileZilla)
2. Navigate to `public_html` (or your website directory)
3. Upload all PHP files

---

### Step 5: Set File Permissions

**Important for security!**

1. In File Manager, select `config.php`
2. Click "Permissions" or "Change Permissions"
3. Set to `644` (read-only for group/others)
4. Repeat for all PHP files

---

### Step 6: Test Your Installation

1. **Open your browser**
2. **Navigate to:** `https://yourdomain.com/crm/login.php`
   (Replace `yourdomain.com` with your actual domain)

3. **You should see the Mowology login page!** 🎉

---

## 🔐 Default Login Credentials

**Email:** `mowology@icloud.com`
**Password:** `Mowology2025!`

⚠️ **IMPORTANT:** Change this password immediately after your first login!

---

## 🧪 Testing Checklist

After installation, verify everything works:

- [ ] Login page loads properly
- [ ] Can log in with default credentials
- [ ] Dashboard displays correctly
- [ ] Can log out successfully
- [ ] Login page shows error for wrong password
- [ ] Navigation links work (even if placeholder pages)

---

## 🔧 Troubleshooting

### "Connection failed" error
- Double-check your database credentials in `config.php`
- Verify the database name includes any prefix your host adds
- Ensure the database user has ALL PRIVILEGES

### "Page not found" error
- Check that files are in the correct directory
- Verify file names are exactly: `login.php`, `dashboard.php`, etc.
- Check that your URL path is correct

### Login page loads but can't log in
- Verify you imported `database_schema.sql` successfully
- Check phpMyAdmin to see if the `users` table exists
- Look for any PHP errors at the top of the page

### Blank white page
- Enable error reporting: In cPanel → PHP Settings → display_errors = On
- Check PHP error logs in cPanel

---

## 📱 Accessing Your CRM

After successful installation:

- **Login Page:** `https://yourdomain.com/crm/login.php`
- **Dashboard:** `https://yourdomain.com/crm/dashboard.php`
- **Logout:** `https://yourdomain.com/crm/logout.php`

Bookmark the login page for easy access!

---

## 🎯 What's Next?

You've completed **Phase 1: Foundation**! 

**Ready for Phase 2?**
- Client Management (Add/Edit/Delete clients)
- Client listing with filters
- Status management
- Search functionality

Just let me know when you're ready to continue!

---

## 🔒 Security Notes

Your Phase 1 system includes:
- ✅ Password hashing (bcrypt)
- ✅ SQL injection protection (PDO prepared statements)
- ✅ CSRF token protection
- ✅ Session security settings
- ✅ XSS protection (input sanitization)
- ✅ HTTPS enforcement (via your active SSL certificate)

**Additional security recommendations:**
1. Change the default admin password immediately
2. Keep `config.php` permissions at 644
3. Never commit `config.php` to version control
4. Regularly backup your database
5. Keep PHP and MySQL updated

---

## 📞 Support

If you run into any issues during installation:
1. Check the troubleshooting section above
2. Review your cPanel error logs
3. Let me know what error message you're seeing

---

**Built with ❤️ for Mowology**
Phase 1 Complete - Secure Foundation ✓
