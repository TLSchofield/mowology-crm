Generate a new CRM AppStack page for: $ARGUMENTS

Parse $ARGUMENTS as: "page-slug Page Title [nav-key]"
- page-slug: kebab-case filename (e.g. client-report → client-report_appstack.php)
- Page Title: human-readable title shown in <title> and page heading
- nav-key: optional sidebar highlight key (dashboard|clients|quotes|jobs|invoices|schedule|map|products|settings)

Output the complete PHP file content. For a ROOT-level CRM page (`/crm/`):

```php
<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'PAGE_TITLE';
$activePage = 'NAV_KEY';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0" style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--mw-forest);">PAGE_TITLE</h2>
        <p class="text-muted mb-0" style="font-size:.85rem;">Description here</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <!-- Page content here -->
    </div>
</div>

<?php include 'includes/appstack_footer.php'; ?>
```

For a SUBDIRECTORY page (`/crm/quotes/`, `/crm/jobs/`, etc.), use `dirname(__DIR__)` instead of `__DIR__ . '/../'`.

Rules:
- NO <!DOCTYPE>, <html>, <head>, <body> — appstack_head.php outputs all of that
- NO inline <style> blocks — CSS goes in mowology-brand.css
- Use --mw-* CSS variables for all colors
- Use Feather icons: `<i data-feather="icon-name"></i>`
- Use Bootstrap 4 grid: .row, .col-md-6, etc.
- Use .card, .card-header, .card-body for content sections
- All DB queries must use prepared statements
- All output must use h() or htmlspecialchars()
- Forms must include: `<?php echo generateCSRFToken(); ?>` hidden input

Also output: the CSS class names to add to mowology-brand.css (using .mw- prefix), and if a new sidebar entry is needed, the array entry to add to appstack_sidebar.php.
