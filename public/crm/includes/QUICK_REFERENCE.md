# CRM Error Handler - Quick Reference Card

## 🚀 5-Minute Setup

### 1. Add to page top (after auth)
```php
require_once __DIR__ . '/../includes/error-handler.php';
$errorHandler = new CRMErrorHandler('Page Name', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;
```

### 2. Wrap database code
```php
try {
    $stmt = $db->prepare("UPDATE table SET x = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Update failed');
}
```

### 3. Validate input
```php
if (!$errorHandler->validateRequiredParams(['name', 'email'])) {
    exit; // Already logged and alerted
}
```

### 4. Add alert display (template)
```php
<?php if (isset($_SESSION['alert'])):
    $a = $_SESSION['alert'];
    $class = 'alert-'.$a['type']; ?>
    <div class="alert <?php echo $class; ?> alert-dismissible fade show">
        <?php echo h($a['message']); ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['alert']);
endif; ?>
```

---

## 🎯 Common Tasks

### Log a database error
```php
try { /* operation */ }
catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, 'query', ['id' => $id], 'Failed');
}
```

### Log any error
```php
try { /* operation */ }
catch (Exception $e) {
    $errorHandler->logError('Operation failed', $e, ['context' => 'data']);
}
```

### Validate required fields
```php
if (!$errorHandler->validateRequiredParams(['name', 'email', 'phone'])) {
    header('Location: form.php?error=validation');
    exit;
}
```

### Validate CSRF token
```php
if (!$errorHandler->validateCsrfToken($_POST['csrf_token'] ?? '')) {
    exit; // Error handled
}
```

### Try/catch pattern
```php
$result = $errorHandler->tryOperation(
    fn() => complexFunction($data),
    'Operation failed',
    ['data_id' => $data['id'] ?? null]
);
if ($result === null) {
    header('Location: index.php?error=operation');
    exit;
}
```

### Display alert to user
```php
$errorHandler->displayAlert('error', 'Something went wrong');
// For HTML: stored in session
// For AJAX: returns JSON immediately
```

### Check for errors
```php
if ($errorHandler->hasErrors()) {
    // Handle error state
    $errorIds = $errorHandler->getErrors();
}
```

---

## 📝 API Quick Reference

| Method | Purpose | Example |
|--------|---------|---------|
| `logError()` | Log any error | `logError('msg', $e, ['ctx'])` |
| `logDatabaseError()` | Log DB errors | `logDatabaseError($e, 'query', [$id])` |
| `displayAlert()` | Show alert | `displayAlert('error', 'msg')` |
| `validateRequiredParams()` | Check fields | `validateRequiredParams(['name'])` |
| `validateCsrfToken()` | Check CSRF | `validateCsrfToken($_POST['csrf'])` |
| `tryOperation()` | Safe execution | `tryOperation($fn, 'error msg')` |
| `getErrors()` | Get error IDs | `$ids = getErrors()` |
| `hasErrors()` | Check if errors | `if (hasErrors())` |

---

## 🔍 Server Log Viewing

### View recent errors
```bash
tail -100 /home/mowology/logs/error_log | grep "ERROR ID"
```

### Find specific error
```bash
grep "ERR-20260208120530" /home/mowology/logs/error_log
```

### Find errors on page
```bash
grep "Page: Dashboard" /home/mowology/logs/error_log
```

### Find errors from user
```bash
grep "admin@example.com" /home/mowology/logs/error_log
```

### Real-time log watch
```bash
tail -f /home/mowology/logs/error_log | grep "ERR-"
```

---

## 🌐 AJAX Responses

### Error response
```json
{
    "success": false,
    "type": "error",
    "message": "User-friendly message"
}
```

### Success response
```json
{
    "success": true,
    "type": "success",
    "message": "Operation completed"
}
```

### JavaScript handling
```javascript
fetch('/api.php', {method:'POST', body: fd})
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert('✓ ' + data.message);
    } else {
      alert('Error: ' + data.message);
      console.error('Details:', data.details);
    }
  });
```

---

## 🚦 Implementation Checklist

- [ ] Add error-handler.php to page
- [ ] Wrap database operations in try/catch
- [ ] Add input validation
- [ ] Add CSRF validation
- [ ] Add session alert display in template
- [ ] Test database error logging
- [ ] Test validation error display
- [ ] Test AJAX error responses
- [ ] Monitor server logs
- [ ] Check that error IDs appear correctly

---

## 💾 Session Alert Template

```php
<?php
if (isset($_SESSION['alert'])):
    $alert = $_SESSION['alert'];
    $alertClass = [
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'success' => 'alert-success',
        'info' => 'alert-info'
    ][$alert['type']] ?? 'alert-info';
?>
    <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
        <strong><?php echo ucfirst($alert['type']); ?>:</strong>
        <?php echo h($alert['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>
```

---

## 🔐 Security Notes

✅ Technical details only in server logs
✅ User-friendly messages to browser
✅ Stack traces never exposed
✅ CSRF validation automatic
✅ Input validation built-in
✅ Unique error IDs for tracking
✅ User info logged server-side only
✅ IP addresses captured safely

---

## 📚 Full Documentation

- **ERROR_HANDLER_GUIDE.md** - Complete reference with examples
- **ERROR_HANDLER_TEMPLATE.php** - Copy/paste templates
- **IMPLEMENTATION_CHECKLIST.md** - Step-by-step implementation
- **error-handler.php** - Source code (well-commented)

---

## 🆘 Troubleshooting

**Alert not showing:**
- Check session is started
- Check alert display template is in place
- Check `$_SESSION['alert']` is unset after display

**Errors not logging:**
- Verify error-handler.php included
- Check errorHandler initialized with `$GLOBALS['crm_error_handler']`
- Check `/home/mowology/logs/error_log` is writable

**AJAX errors not returning JSON:**
- Verify error-handler.php is included in API endpoint
- Check `Content-Type: application/json` header is set
- Verify AJAX request is detected (X-Requested-With header)

**Error IDs not appearing:**
- Check error_log file location
- Verify logging is enabled in php.ini
- Check file permissions on error_log

---

**Quick links:**
- [Full Guide](ERROR_HANDLER_GUIDE.md)
- [Template](ERROR_HANDLER_TEMPLATE.php)
- [Checklist](IMPLEMENTATION_CHECKLIST.md)
- [System Info](/ERROR_REPORTING_SYSTEM.md)

---
**Last Updated:** February 2026 | **Version:** 1.0
