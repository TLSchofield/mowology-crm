Show the live database schema for: $ARGUMENTS

If a table name was provided, show its columns from the dev context that was injected at session start (look for it in the conversation above under "MOWOLOGY DEV CONTEXT"). If the dev context wasn't injected or the table isn't in the key tables list, use the Bash tool to run:

```bash
mysql -u$(grep "DB_USER" /Users/timschofield/Projects/mowology-crm/public/app_config/config.php 2>/dev/null | head -1) 2>/dev/null || true
```

Alternatively, read the schema snapshot if available:
```bash
cat /Users/timschofield/Projects/mowology-crm/storage/schema_snapshot.json 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); t='$ARGUMENTS'; print(json.dumps(d.get('tables',{}).get(t,{}), indent=2)) if t else print(list(d.get('tables',{}).keys()))"
```

If $ARGUMENTS is empty, list all tables from the dev context or snapshot.

Display the result in a clean markdown table with columns: Column Name | Type | Nullable | Default | Notes
