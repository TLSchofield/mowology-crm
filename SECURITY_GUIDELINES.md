# Security Implementation Guidelines

Quick reference for developers to prevent security vulnerabilities in new code.

## SQL Injection Prevention (CWE-89)

### Rule: ALWAYS use prepared statements for ANY query with user data

```php
// ✅ CORRECT
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$userEmail]);
$user = $stmt->fetch();

// ✅ CORRECT - Multiple parameters
$stmt = $db->prepare("
    SELECT * FROM contacts
    WHERE first_name = ? AND last_name = ?
    LIMIT 1
");
$stmt->execute([$firstName, $lastName]);

// ❌ WRONG - Never concatenate!
$stmt = $db->query("SELECT * FROM users WHERE email = '" . $_POST['email'] . "'");

// ❌ WRONG - Never use sprintf!
$result = $db->query(sprintf("SELECT * FROM users WHERE id = %d", $_GET['id']));
```

---

## XSS Prevention (CWE-79)

### Rule: ALWAYS escape output with htmlspecialchars()

```php
// ✅ CORRECT - Use h() helper or htmlspecialchars()
echo h($user['name']);
echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

// ✅ CORRECT - In HTML attributes
<input value="<?php echo htmlspecialchars($value); ?>">

// ❌ WRONG - Direct output
echo $_POST['message'];  // Attacker can inject JS!

// ❌ WRONG - Forgetting attribute context
<div data-user="<?php echo $username; ?>">  // Vulnerable!
```

---

## CSRF Prevention (CWE-352)

### Rule: ALL forms must include CSRF token validation

```php
// ✅ CORRECT - Include token in form
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <!-- form fields -->
</form>

// ✅ CORRECT - Verify on submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Security check failed');
    }
    // Process form...
}

// ❌ WRONG - No CSRF token
<form method="POST">
    <input type="text" name="action">
    <button>Submit</button>  // No token - vulnerable!
</form>
```

---

## Input Validation Pattern

### Rule: Validate, Sanitize, Use in Order

```php
// Step 1: Collect and validate
$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email address';
}

// Step 2: If validation passes, sanitize
if (empty($errors)) {
    $email = strtolower($email);  // Normalize
}

// Step 3: Use in prepared statement
$stmt = $db->prepare("SELECT * FROM contacts WHERE email = ?");
$stmt->execute([$email]);
```

---

## File Upload Security (CWE-434)

### Rule: Validate, Sanitize Filename, Store Safely

```php
// ✅ CORRECT
if (!in_array($_FILES['image']['type'], ['image/jpeg', 'image/png'])) {
    die('Invalid file type');
}

if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
    die('File too large');
}

// Use timestamp + random name, not user-provided filename
$filename = 'project_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
$filepath = '/path/outside/webroot/' . $filename;

if (!move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
    die('Upload failed');
}

// ❌ WRONG
$filename = $_FILES['image']['name'];  // User controls filename!
move_uploaded_file($_FILES['image']['tmp_name'], '/var/www/uploads/' . $filename);
```

---

## Authentication & Sessions

### Rule: Use secure session handling

```php
// ✅ CORRECT - In session_config.php
session_set_cookie_params([
    'httponly' => true,      // No JS access to session cookie
    'secure' => true,        // HTTPS only
    'samesite' => 'Lax',     // CSRF protection
    'lifetime' => 3600,      // 1 hour
]);

// ✅ CORRECT - Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: /loginAuth/login.php');
    exit();
}

// ❌ WRONG - Weak session security
ini_set('session.cookie_httponly', '0');  // Allows JS theft!
ini_set('session.cookie_secure', '0');    // Allows HTTP interception!
```

---

## Logging & Error Handling

### Rule: Log security events, don't expose errors

```php
// ✅ CORRECT
error_log("Failed login attempt from " . $_SERVER['REMOTE_ADDR']);

try {
    // Database operation
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die('An error occurred. Please try again.');  // Generic message
}

// ❌ WRONG - Exposing error details
echo "Database error: " . $e->getMessage();  // Shows internal details!

// ❌ WRONG - No logging
if ($login_failed) {
    // No record of attack attempt
}
```

---

## Database User Permissions

### Rule: Use least privilege principle

```sql
-- ✅ CORRECT - Limited permissions
CREATE USER 'mowology_user'@'localhost' IDENTIFIED BY 'strong_password';

-- Grant only needed permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON mowology_landscape_crm.* TO 'mowology_user'@'localhost';

-- Explicitly deny dangerous operations
-- (Automatic with above - user cannot DROP, CREATE, ALTER)

-- ❌ WRONG - Too many permissions
GRANT ALL PRIVILEGES ON *.* TO 'mowology_user'@'localhost';  // Dangerous!
```

---

## Code Review Checklist

Use this checklist when reviewing code for security:

### For Every Form Submission:
- [ ] CSRF token present in form?
- [ ] CSRF token validated on POST?
- [ ] All user input trimmed/validated?
- [ ] All SQL queries use prepared statements?
- [ ] All parameters use ? placeholders?
- [ ] All output escaped with htmlspecialchars()?

### For Every Database Query:
- [ ] Uses $db->prepare()?
- [ ] Uses execute() with parameter array?
- [ ] No string concatenation with variables?
- [ ] No sprintf() with user data?
- [ ] No eval() or dynamic code execution?

### For Every File Upload:
- [ ] File type validated?
- [ ] File size validated?
- [ ] Filename sanitized (timestamp + random)?
- [ ] Stored outside web root?
- [ ] Upload directory not executable?

### For Every Output:
- [ ] Escaped with htmlspecialchars() or h()?
- [ ] Using ENT_QUOTES context?
- [ ] UTF-8 charset specified?
- [ ] No JSON in HTML attributes (use data-* properly)?

---

## Testing for Vulnerabilities

### SQL Injection Test Cases
```
' OR '1'='1
'; DROP TABLE users; --
1 UNION SELECT NULL, NULL, NULL --
admin' --
" OR 1=1 --
```

**Expected Result:** All should be treated as literal strings, no data compromise.

### XSS Test Cases
```
<img src=x onerror="alert('XSS')">
<script>alert('XSS')</script>
" onfocus="alert('XSS')" autofocus="
```

**Expected Result:** All should be displayed as text, no code execution.

---

## Quick Fix: Adding Security to Existing Code

### If you find code like this:
```php
$result = $db->query("SELECT * FROM users WHERE id = " . $_GET['id']);
```

### Fix it like this:
```php
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);
$result = $stmt;
```

---

## Resources

- **OWASP Top 10:** https://owasp.org/Top10/
- **PHP Security:** https://www.php.net/manual/en/security.php
- **PDO Security:** https://www.php.net/manual/en/pdo.prepared-statements.php
- **CWE Top 25:** https://cwe.mitre.org/top25/

---

**Last Updated:** February 6, 2026
