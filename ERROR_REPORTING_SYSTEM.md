# CRM Unified Error Reporting System

## What Was Created

A comprehensive, production-ready error reporting system for all CRM pages has been installed in:

```
/public/crm/includes/
├── error-handler.php              (Core system - 400+ lines)
├── ERROR_HANDLER_GUIDE.md          (Complete documentation)
├── ERROR_HANDLER_TEMPLATE.php      (Copy/paste examples)
└── IMPLEMENTATION_CHECKLIST.md     (Implementation guide)
```

## Files Breakdown

### 1. error-handler.php (Core System)

**What it does:**
- Centralized error handling class `CRMErrorHandler`
- Automatic context capture (page, user, session, IP, request method)
- Server-side logging with unique error IDs
- User-friendly alert display (no technical details)
- Database error handling with query context
- AJAX JSON response formatting
- Input validation (required params, CSRF tokens)
- Global helper functions

**Key Methods:**
```php
logError()              // Log error with context
logDatabaseError()      // Log PDO exceptions with query
displayAlert()          // Show user alert (HTML or JSON)
validateRequiredParams() // Validate form input
validateCsrfToken()     // Validate CSRF tokens
tryOperation()          // Wrap operations with error handling
getErrors()             // Get list of error IDs
hasErrors()             // Check if errors occurred
```

### 2. ERROR_HANDLER_GUIDE.md (Documentation)

**Covers:**
- Quick start (3 lines of code to initialize)
- Usage examples (database, validation, CSRF, etc.)
- Error logging details with sample output
- Error ID format explanation
- HTML alert display template
- AJAX error response format
- Database error handling patterns
- Server-side log location and commands
- Complete API reference
- Security considerations

### 3. ERROR_HANDLER_TEMPLATE.php (Copy/Paste)

**Shows:**
- How to initialize in a new page
- 5 common usage patterns with examples
- How to display alerts in templates
- How to handle AJAX errors
- Best practices for error handling

### 4. IMPLEMENTATION_CHECKLIST.md (Implementation Guide)

**Includes:**
- Quick implementation (5 minutes per page)
- Step-by-step instructions
- List of 15+ CRM pages to update
- Common code patterns
- Template for root-level vs subdirectory pages
- Testing procedures
- Deployment checklist
- Debugging commands

## Key Features

### 🔍 Automatic Context Collection
Every error includes:
- Page name
- Request method (GET/POST)
- Session ID
- User IP address
- Current user (email + ID)
- Timestamp
- Error ID (unique tracking)

### 🆔 Unique Error IDs
Format: `ERR-YYYYMMDDHHMSS-XXXXXXXX`
- Can be shared with users
- Enables support ticket tracking
- Makes debugging easier

### 🛡️ Security-First Design
- Technical details only in server logs
- User-friendly messages shown to browser
- Stack traces never exposed
- CSRF token validation built-in
- Input validation before database operations

### 📊 Comprehensive Logging
Server log entries include:
- Error message and context
- Full exception details
- Query context for database errors
- Stack trace for debugging
- User and session information

Example:
```
[ERROR ID: ERR-20260208120530-a1b2c3d4] Operation failed |
Page: Dashboard | Method: POST | Session: abc123 |
User IP: 192.168.1.100 | User: admin@example.com (ID: 1)
Exception: PDOException | Code: 23000 | File: db.php:123
Stack: [full trace]
Context: {"operation": "update_user", "user_id": 42}
```

### 🌐 AJAX Support
Automatic JSON response format:
```json
{
    "success": false,
    "type": "error",
    "message": "User-friendly message here"
}
```

## How to Use

### Initialize (1 line)
```php
$errorHandler = new CRMErrorHandler('Dashboard', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;
```

### Database Operations (Try/Catch)
```php
try {
    $stmt = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
    $stmt->execute([$name, $id]);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Failed to update user.');
}
```

### Validate Input (One-liner)
```php
if (!$errorHandler->validateRequiredParams(['name', 'email'])) {
    exit; // Error already logged and displayed
}
```

### Display Alerts in Template
```php
<?php if (isset($_SESSION['alert'])): $alert = $_SESSION['alert']; ?>
    <div class="alert alert-<?php echo $alert['type']; ?>">
        <?php echo h($alert['message']); ?>
    </div>
<?php unset($_SESSION['alert']); endif; ?>
```

## Error Log Example

Location: `/home/mowology/logs/error_log`

View recent errors:
```bash
tail -100 /home/mowology/logs/error_log | grep "ERROR ID"
```

Find specific error:
```bash
grep "ERR-20260208120530" /home/mowology/logs/error_log
```

Find errors from specific page:
```bash
grep "Page: Dashboard" /home/mowology/logs/error_log
```

Find errors from specific user:
```bash
grep "admin@example.com" /home/mowology/logs/error_log
```

## Next Steps

1. **Read the documentation:**
   - Start with `IMPLEMENTATION_CHECKLIST.md` (5 min read)
   - Reference `ERROR_HANDLER_GUIDE.md` for details

2. **Implement on high-priority pages:**
   - dashboard_appstack.php
   - quotes_appstack.php
   - jobs_appstack.php
   - clients_appstack.php

3. **Test each page:**
   - Intentionally trigger an error
   - Verify it appears in server logs
   - Verify user sees friendly alert

4. **Monitor logs:**
   - Watch `/home/mowology/logs/error_log`
   - Track error patterns
   - Use error IDs for support

5. **Roll out to remaining pages:**
   - Use the template files
   - Follow the implementation checklist
   - Test AJAX endpoints separately

## Implementation Timeline

**Phase 1 (Week 1):** High-priority pages
- dashboard_appstack.php
- quotes_appstack.php
- jobs_appstack.php
- clients_appstack.php
- settings.php

**Phase 2 (Week 2):** Feature modules
- quotes/* pages
- jobs/* pages
- invoices/* pages
- products/* pages

**Phase 3 (Week 3):** API endpoints
- API error responses
- utility pages
- maintenance endpoints

## What Gets Logged

✅ Database errors (with query context)
✅ Validation failures (missing fields, bad CSRF)
✅ Business logic exceptions
✅ AJAX errors (JSON responses)
✅ PHP errors (caught automatically)
✅ Uncaught exceptions
✅ Custom error logging

## What Users See

Instead of technical errors like:
```
Fatal error: Uncaught PDOException: SQLSTATE[23000]:
Integrity constraint violation in /path/to/file.php:123
```

Users see friendly messages like:
```
Error: Email already registered. Please use another
email or log in. (Reference: ERR-20260208120530-a1b2c3d4)
```

## Support

- **Questions?** See `ERROR_HANDLER_GUIDE.md`
- **Implementation?** See `IMPLEMENTATION_CHECKLIST.md`
- **Examples?** See `ERROR_HANDLER_TEMPLATE.php`
- **Integration?** See `error-handler.php` source code

## Statistics

- **Lines of code:** ~450 (error-handler.php)
- **Documentation:** ~600 lines (guides + checklist)
- **Time to implement per page:** ~5 minutes
- **Error context fields:** 9+ (page, user, session, IP, etc.)
- **CRM pages to update:** 15+
- **API endpoints to update:** 10+

---

**Created:** February 8, 2026
**Version:** 1.0
**Status:** Production Ready
**Author:** Claude Code

All files are located in `/public/crm/includes/` and ready for immediate use.
