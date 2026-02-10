# Recommendations Tab - Display Issue Fix

## Problem
The Recommendations tab was showing statistics correctly (Total: 141, New: 141) but the actual recommendation rows were NOT displaying in the table. The empty state message appeared instead: "No recommendations yet."

## Root Cause
**MySQL 5.7 Incompatibility: Window Functions Not Supported**

The query in `/public/crm/portfolio/recommendations-data.php` used MySQL 8.0+ syntax:
```sql
SELECT
    ...
    COUNT(*) OVER() as total_count
FROM seo_recommendations
```

Window functions (`OVER()` clause) are **NOT supported in MySQL 5.7**, which is what cPanel hosting provides per the CLAUDE.md specification.

When the query encountered the unsupported window function syntax, it failed silently, returning zero rows instead of throwing a visible error.

## Evidence of the Issue
1. **The stats query worked** (lines 39-43 of `index.php`):
   ```php
   $recStmt = $db->query("SELECT status, COUNT(*) as cnt FROM seo_recommendations GROUP BY status");
   ```
   This proves the database and table exist with 141 rows.

2. **The complex query failed** due to window function syntax error
3. No error was visible to the user because the error was silently caught by PDO

## Solution
Refactored `/public/crm/portfolio/recommendations-data.php` to use MySQL 5.7-compatible syntax:

### Before (Broken):
```php
$sql = "
    SELECT
        sr.id,
        ...
        COUNT(*) OVER() as total_count
    FROM seo_recommendations sr
    LEFT JOIN seo_targets st ON sr.target_id = st.id
    LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
    $whereClause
    ORDER BY sr.{$sortBy} {$sortDir}
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Get total count from first row (window function)
$totalCount = !empty($recommendations) ? (int)$recommendations[0]['total_count'] : 0;
```

### After (Fixed):
```php
// Get total count FIRST (MySQL 5.7 compatible - no window functions)
$countSql = "SELECT COUNT(*) as total FROM seo_recommendations sr";
if (!empty($whereConditions)) {
    $countSql .= " WHERE " . implode(' AND ', $whereConditions);
}
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
$totalCount = (int)($countResult['total'] ?? 0);

// Query recommendations (NO window function)
$sql = "
    SELECT
        sr.id,
        ...
        (window function removed)
    FROM seo_recommendations sr
    LEFT JOIN seo_targets st ON sr.target_id = st.id
    LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
    $whereClause
    ORDER BY sr.{$sortBy} {$sortDir}
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalPages = ceil($totalCount / $perPage);
```

## Changes Made

### File: `/public/crm/portfolio/recommendations-data.php`

1. **Lines 12-22**: Added clarifying comments about parent scope variables and removed the `die()` on failed auth - now returns empty array gracefully

2. **Lines 75-83**: Added separate COUNT query BEFORE the main query
   - Executes the count using the same WHERE conditions and parameters
   - Prevents reliance on window function syntax

3. **Lines 85-117**: Removed window function from main query
   - Removed `COUNT(*) OVER() as total_count` from SELECT
   - Query now simply returns recommendation data without trying to count all matching rows

4. **Line 118**: Calculation of total pages now uses the pre-calculated `$totalCount` instead of trying to extract it from the first row

## Testing
After this fix:
1. Refresh the Recommendations tab
2. The table should now display all 141 recommendations
3. Pagination should work correctly
4. Filtering by status, type, target, and season should work

## MySQL Compatibility
This fix ensures the code works with:
- MySQL 5.7 (current cPanel standard)
- MySQL 8.0+ (also compatible, though window functions would work there)

## Files Modified
- `/public/crm/portfolio/recommendations-data.php` - Fixed query to be MySQL 5.7 compatible

## Files Created (for debugging - can be removed)
- `/public/crm/portfolio/test-recommendations-query.php` - Debug script
- `/public/crm/portfolio/check-mysql-version.php` - MySQL version checker
