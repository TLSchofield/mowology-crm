# Quick Fix: Add Tags Column to Database

If you're getting this error:
```
Unknown column 'tags' in 'field list'
```

Follow these steps to add the tags column:

## Option 1: Via phpMyAdmin (Easiest)

1. Go to cPanel → phpMyAdmin
2. Select database: `mowology_landscape_crm`
3. Click "SQL" tab
4. Copy & paste this SQL:
```sql
ALTER TABLE portfolio_projects ADD COLUMN `tags` json COMMENT 'Array of project tags (e.g., ["Weekly Maintenance", "Strata"])' AFTER `categories`;
```
5. Click "Go"
6. Done! Page will refresh

## Option 2: Via Migration File

1. Run this SQL via command line:
```bash
mysql -u [username] -p [database] < database/migrations/014_add_tags_to_portfolio.sql
```

## Option 3: Via Direct MySQL

```bash
mysql -u [username] -p
USE mowology_landscape_crm;
ALTER TABLE portfolio_projects ADD COLUMN `tags` json COMMENT 'Array of project tags' AFTER `categories`;
EXIT;
```

## Verify It Worked

1. Go back to CRM
2. Click Portfolio → Add Project (or edit existing)
3. Should now have "Project Tags" field
4. Form should work without errors

## What This Does

- Adds a `tags` column to the `portfolio_projects` table
- Placed after the `categories` column
- Stores tags as JSON array
- Allows 0, 1, or many tags per project

---

After running this migration, everything should work perfectly!
