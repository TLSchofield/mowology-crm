# ⚠️ DATABASE CRITICAL CONSTRAINTS - READ FIRST

**PRODUCTION DATABASE:** MySQL 5.7+
**CONSTRAINT LEVEL:** STRICT - Non-negotiable

---

## The Error You Just Saw

```sql
#1064 - You have an error in SQL syntax;
check the manual that corresponds to your MySQL server version at line 11.
```

**Root Cause:** `IF NOT EXISTS` syntax in `ALTER TABLE ADD COLUMN` is **INVALID in MySQL 5.7**

❌ **WRONG (MySQL 5.7 incompatible):**
```sql
ALTER TABLE quotes
ADD COLUMN IF NOT EXISTS property_id INT;
```

✅ **CORRECT (MySQL 5.7 compatible):**
```sql
ALTER TABLE quotes
ADD COLUMN property_id INT;
-- MySQL will error naturally if column already exists
-- OR use conditional logic in application code
```

---

## MySQL 5.7 Compatibility Rules

### ❌ DO NOT USE (MySQL 8.0+ only)

| Feature | Example | Why Not |
|---------|---------|---------|
| Window Functions | `ROW_NUMBER() OVER (PARTITION BY...)` | Not in 5.7 |
| JSON Functions | `JSON_EXTRACT()`, `JSON_ARRAY()` | Not in 5.7 |
| Generated Columns | `age INT GENERATED AS (YEAR(NOW()) - birth_year)` | Not in 5.7 |
| `IF NOT EXISTS` in ALTER | `ALTER TABLE ADD COLUMN IF NOT EXISTS col` | Not in 5.7 |
| `IF EXISTS` in DROP | `DROP TABLE IF EXISTS tbl;` | OK, but DROP INDEX IF EXISTS is risky |
| Recursive CTEs | `WITH RECURSIVE...` | Not in 5.7 |
| Check Constraints | `CHECK (age > 18)` | Syntax OK but not enforced |

### ✅ DO USE (MySQL 5.7+ compatible)

| Feature | Example | Notes |
|---------|---------|-------|
| Prepared Statements | `SELECT * FROM users WHERE id = ?` | Always use `?` placeholders |
| Standard ENUM | `ENUM('draft', 'published', 'archived')` | Works fine |
| JSON Storage | Store JSON strings, query in PHP | Don't use JSON_EXTRACT |
| Subqueries | `SELECT * FROM (SELECT ...) AS subq` | Works fine |
| JOINs | All standard JOIN types | No issue |
| Indexes | All standard index types | Works fine |
| Foreign Keys | `FOREIGN KEY (...) REFERENCES` | With proper charset collation |
| Transactions | `START TRANSACTION`, COMMIT, ROLLBACK | Works fine |

---

## Migration File Template (MySQL 5.7 Safe)

```sql
-- ============================================================================
-- Safe Migration Template for MySQL 5.7+
-- ============================================================================

-- Check current schema version (informational)
-- No conditionals - let MySQL error naturally if constraint violated

-- Modify existing table
ALTER TABLE existing_table
  ADD COLUMN new_column INT DEFAULT 0;
  -- If column exists, MySQL errors: "Duplicate column name"
  -- This is EXPECTED - catch in PHP and skip

-- Create new table (use standard syntax)
CREATE TABLE IF NOT EXISTS new_table (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci;

-- Add index (standard syntax)
CREATE INDEX idx_status ON table_name(status);
-- If index exists, MySQL errors: "Duplicate key name"
-- This is EXPECTED - catch in PHP and skip

-- Update data (standard syntax)
UPDATE table_name SET column = value WHERE condition;

-- Insert seed data
INSERT INTO table_name (col1, col2) VALUES (val1, val2);

-- Log migration completion
INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('NNN_migration_name.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
```

---

## Error Handling Pattern

**In PHP, wrap migrations in try-catch:**

```php
try {
    $db->query("ALTER TABLE quotes ADD COLUMN property_id INT;");
} catch (PDOException $e) {
    // Expected errors: "Duplicate column name", "Duplicate key name"
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        error_log("Migration: Column already exists (skipped)");
    } else {
        throw $e; // Re-throw unexpected errors
    }
}
```

---

## Required Charset & Collation

**All tables MUST use:**
```sql
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE utf8mb4_general_ci
```

**Why?**
- `utf8mb4`: Supports full Unicode (emojis, special chars)
- `general_ci`: Case-insensitive, suitable for most text
- **CRITICAL for foreign keys**: Collations must MATCH exactly

**Example - Foreign Key (WRONG collation = error):**
```sql
-- ❌ WRONG: Mismatched collations
CREATE TABLE orders (
  id INT PRIMARY KEY,
  user_id INT,
  FOREIGN KEY (user_id) REFERENCES users(id)  -- Might error if collations differ
) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- Different collation!

-- ✅ CORRECT: Matching collations
CREATE TABLE orders (
  id INT PRIMARY KEY,
  user_id INT,
  FOREIGN KEY (user_id) REFERENCES users(id)
) CHARSET utf8mb4 COLLATE utf8mb4_general_ci;  -- Same as users table
```

---

## Common Migration Mistakes

### ❌ Mistake 1: IF NOT EXISTS in ALTER ADD COLUMN

```sql
-- WRONG
ALTER TABLE table_name
ADD COLUMN IF NOT EXISTS col_name INT;

-- RIGHT
ALTER TABLE table_name
ADD COLUMN col_name INT;
-- MySQL errors if column exists → catch in application
```

### ❌ Mistake 2: Using Window Functions

```sql
-- WRONG (MySQL 8.0+ only)
SELECT
  id,
  ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY date DESC) as row_num
FROM activities;

-- RIGHT (MySQL 5.7 compatible)
SELECT * FROM activities WHERE id IN (
  SELECT id FROM (
    SELECT id, ROW_NUMBER() OVER (...) -- Still won't work in 5.7!
  ) AS numbered
  WHERE row_num = 1
);

-- BEST (actually works in 5.7)
SELECT DISTINCT ON user_id ... -- Not standard SQL
-- OR use subquery + GROUP BY carefully
```

### ❌ Mistake 3: JSON_EXTRACT in WHERE clause

```sql
-- WRONG (JSON functions not in 5.7)
SELECT * FROM config WHERE JSON_EXTRACT(data, '$.key') = 'value';

-- RIGHT (store/query in PHP)
SELECT * FROM config;
$data = json_decode($row['data'], true);
if ($data['key'] === 'value') { ... }
```

### ❌ Mistake 4: Forgetting to specify charset/collation

```sql
-- WRONG (defaults may not match)
CREATE TABLE new_table (
  id INT PRIMARY KEY,
  name VARCHAR(255)
);

-- RIGHT (explicit, matches existing tables)
CREATE TABLE new_table (
  id INT PRIMARY KEY,
  name VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci;
```

---

## MySQL 5.7 Version Check

**In PHP, verify version:**

```php
$result = $db->query("SELECT VERSION() as mysql_version;");
$version = $result->fetch(PDO::FETCH_ASSOC)['mysql_version'];
// Output: "5.7.33-0ubuntu0.18.04.1"

if (version_compare($version, '5.7', '<')) {
    die("MySQL 5.7+ required. Current: $version");
}
```

**In your .htaccess or setup:**

```
# Check MySQL version before proceeding
php_value auto_prepend_file /app_config/version_check.php
```

---

## Safe Migration Examples

### Example 1: Add Column (Safe)

```sql
-- Safe migration for MySQL 5.7
ALTER TABLE quotes
ADD COLUMN property_id INT UNSIGNED DEFAULT 0;
```

**What to do if column exists:**
```php
try {
    $db->query("ALTER TABLE quotes ADD COLUMN property_id INT UNSIGNED DEFAULT 0;");
    error_log("Migration: Added property_id column to quotes");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        error_log("Migration: property_id already exists (skipping)");
    } else {
        throw $e;
    }
}
```

### Example 2: Add Index (Safe)

```sql
-- Safe migration for MySQL 5.7
CREATE INDEX idx_quotes_status ON quotes(status);
```

**What to do if index exists:**
```php
try {
    $db->query("CREATE INDEX idx_quotes_status ON quotes(status);");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        error_log("Migration: Index already exists (skipping)");
    } else {
        throw $e;
    }
}
```

### Example 3: Create Table (Safe)

```sql
-- Safe migration for MySQL 5.7
CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_general_ci;
```

**Note:** `IF NOT EXISTS` works for CREATE TABLE, only problematic for ALTER TABLE ADD COLUMN

---

## Checklist Before Writing Any Migration

- [ ] Using MySQL 5.7 syntax only (no window functions, JSON_EXTRACT, etc.)
- [ ] NOT using `IF NOT EXISTS` in `ALTER TABLE ADD COLUMN`
- [ ] Using `utf8mb4_general_ci` collation on all tables
- [ ] All string columns specify `VARCHAR(N)` with length
- [ ] Foreign key collations match parent table collations
- [ ] Using prepared statements in PHP with `?` placeholders
- [ ] Prepared statements in SQL are indexed (no table scans)
- [ ] Migration includes logging in migrations_log table
- [ ] Tested migration on MySQL 5.7 test server
- [ ] Documented expected errors (duplicates, etc.)

---

## Reference: Mowology Project Standards

**From CLAUDE.md:**

> Your production database uses MySQL 5.7+
>
> This is a critical constraint for all SQL queries and schema changes:
> - ✅ No window functions
> - ✅ No JSON functions
> - ✅ No generated columns
> - ✅ Use `utf8mb4_general_ci` collation
> - ✅ Prepared statements required

**All migrations MUST follow these rules.**

---

## When You See SQL Errors

**Step 1: Check MySQL version constraint**
- Is this a MySQL 5.7 issue?
- Am I using a MySQL 8.0+ feature?

**Step 2: Check syntax**
- Am I using `IF NOT EXISTS` in ALTER TABLE ADD COLUMN? (❌ Wrong)
- Am I using window functions? (❌ Wrong)
- Am I using JSON_EXTRACT? (❌ Wrong)

**Step 3: Check collation**
- Are all tables `utf8mb4_general_ci`?
- Does foreign key source match target collation?

**Step 4: Test in PHP with try-catch**
- Wrap in PDOException handler
- Log expected errors (duplicates)
- Re-throw unexpected errors

---

## Resources

- **MySQL 5.7 Docs:** https://dev.mysql.com/doc/refman/5.7/en/
- **Migration Format:** See any file in `database/migrations/`
- **Project Constraints:** Read `CLAUDE.md` section "DATABASE VERSION REQUIREMENT"

---

**GOLDEN RULE:**

> If it works in MySQL 5.7, it works everywhere.
> If it ONLY works in MySQL 8.0+, you can't use it.

**When in doubt, test on MySQL 5.7 first.**

---

**Last Updated:** February 2026
**Database Version:** MySQL 5.7+ (strict)
**Collation Standard:** utf8mb4_general_ci
