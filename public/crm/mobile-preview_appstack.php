<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

$pageTitle    = 'Mobile Preview';
$activePage   = 'mobile-preview';

// Devices — width × height in CSS pixels
$devices = [
    'iphone-14'     => ['label' => 'iPhone 14',         'w' => 390, 'h' => 844,  'icon' => 'smartphone'],
    'iphone-se'     => ['label' => 'iPhone SE',         'w' => 375, 'h' => 667,  'icon' => 'smartphone'],
    'samsung-s23'   => ['label' => 'Samsung Galaxy S23','w' => 360, 'h' => 780,  'icon' => 'smartphone'],
    'samsung-a54'   => ['label' => 'Samsung Galaxy A54','w' => 360, 'h' => 800,  'icon' => 'smartphone'],
    'ipad-mini'     => ['label' => 'iPad Mini',         'w' => 744, 'h' => 1133, 'icon' => 'tablet'],
    'pixel-7'       => ['label' => 'Pixel 7',           'w' => 412, 'h' => 915,  'icon' => 'smartphone'],
];

$deviceA = $_GET['a'] ?? 'iphone-14';
$deviceB = $_GET['b'] ?? 'samsung-s23';
$deviceA = isset($devices[$deviceA]) ? $deviceA : 'iphone-14';
$deviceB = isset($devices[$deviceB]) ? $deviceB : 'samsung-s23';

$targetDate  = $_GET['date']  ?? date('Y-m-d');
$targetPage  = $_GET['page']  ?? '/crm/jobs/schedule.php';
$allowedPages = [
    '/crm/jobs/schedule.php'     => 'Schedule (Mobile Cards)',
    '/crm/dashboard_appstack.php'=> 'Dashboard',
];

// $targetPage is the validated path key; $allowedPages[key] = label
$iframeSrc = array_key_exists($targetPage, $allowedPages) ? $targetPage : '/crm/jobs/schedule.php';
$iframeUrl = $iframeSrc . '?preview=1&date=' . urlencode($targetDate);

// Read deployed CSS/SW version for the info panel
$swPath  = __DIR__ . '/../service-worker.js';
$swVersion = 'unknown';
if (is_readable($swPath)) {
    $swContent = file_get_contents($swPath, false, null, 0, 2000);
    if (preg_match("/CACHE_VERSION\s*=\s*'([^']+)'/", $swContent, $m)) {
        $swVersion = $m[1];
    }
}
?>
<?php include 'includes/appstack_head.php'; ?>

<!-- ═══════════════════════════════════════════════════
     MOBILE PREVIEW — device frame simulator
     Lets you inspect how the PWA/mobile schedule looks
     at exact device widths without a real device.
     ═══════════════════════════════════════════════════ -->

<div class="mw-dp-toolbar card mb-3 p-0">
    <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center gap-3">

        <!-- Left: title + page selector -->
        <div class="d-flex align-items-center gap-3 flex-wrap flex-grow-1">
            <h5 class="mb-0 d-flex align-items-center gap-2">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--mw-green)"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                Mobile Preview
            </h5>
            <form method="get" class="d-flex align-items-center gap-2 flex-wrap mb-0" id="mwDpForm">
                <select name="page" class="form-control form-control-sm mw-dp-select" onchange="document.getElementById('mwDpForm').submit()">
                    <?php foreach ($allowedPages as $path => $label): ?>
                        <option value="<?php echo htmlspecialchars($path); ?>" <?php echo $path === $iframeSrc ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="a" class="form-control form-control-sm mw-dp-select" onchange="document.getElementById('mwDpForm').submit()">
                    <?php foreach ($devices as $key => $dev): ?>
                        <option value="<?php echo $key; ?>" <?php echo $key === $deviceA ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dev['label']); ?> (<?php echo $dev['w']; ?>px)
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="b" class="form-control form-control-sm mw-dp-select" onchange="document.getElementById('mwDpForm').submit()">
                    <?php foreach ($devices as $key => $dev): ?>
                        <option value="<?php echo $key; ?>" <?php echo $key === $deviceB ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dev['label']); ?> (<?php echo $dev['w']; ?>px)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($targetDate); ?>">
            </form>
        </div>

        <!-- Right: actions + info badges -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="mw-dp-badge-sw">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                SW: <strong><?php echo htmlspecialchars($swVersion); ?></strong>
            </span>
            <button type="button" class="btn btn-sm mw-dp-btn-reload" onclick="mwDpReload()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                Reload frames
            </button>
            <a href="<?php echo htmlspecialchars($iframeSrc); ?>" target="_blank" class="btn btn-sm mw-dp-btn-open">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Open page
            </a>
        </div>

    </div>
</div>

<!-- ── Device frames ── -->
<div class="mw-dp-stage" id="mwDpStage">

    <!-- Frame A -->
    <div class="mw-dp-frame-wrap" id="mwDpFrameA"
         data-width="<?php echo (int)$devices[$deviceA]['w']; ?>"
         data-height="<?php echo (int)$devices[$deviceA]['h']; ?>">
        <div class="mw-dp-device-label">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
            <?php echo htmlspecialchars($devices[$deviceA]['label']); ?>
            <span class="mw-dp-dims"><?php echo $devices[$deviceA]['w']; ?> × <?php echo $devices[$deviceA]['h']; ?></span>
        </div>
        <div class="mw-dp-phone-shell" id="mwDpShellA">
            <div class="mw-dp-notch"></div>
            <div class="mw-dp-screen" id="mwDpScreenA">
                <iframe
                    id="mwDpIframeA"
                    src="<?php echo htmlspecialchars($iframeUrl); ?>"
                    width="<?php echo (int)$devices[$deviceA]['w']; ?>"
                    height="<?php echo (int)$devices[$deviceA]['h']; ?>"
                    frameborder="0"
                    scrolling="yes"
                    title="<?php echo htmlspecialchars($devices[$deviceA]['label']); ?> preview">
                </iframe>
            </div>
            <div class="mw-dp-home-bar"></div>
        </div>
        <!-- Inspect overlay -->
        <div class="mw-dp-inspect-bar" id="mwDpInspectA">
            <span class="mw-dp-inspect-label">Inspect element</span>
            <input type="text" class="mw-dp-inspect-input" placeholder=".mw-mc-compact-main" data-frame="A">
            <button class="mw-dp-inspect-btn" data-frame="A">Inspect</button>
            <div class="mw-dp-inspect-result" id="mwDpResultA"></div>
        </div>
    </div>

    <!-- Frame B -->
    <div class="mw-dp-frame-wrap" id="mwDpFrameB"
         data-width="<?php echo (int)$devices[$deviceB]['w']; ?>"
         data-height="<?php echo (int)$devices[$deviceB]['h']; ?>">
        <div class="mw-dp-device-label">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
            <?php echo htmlspecialchars($devices[$deviceB]['label']); ?>
            <span class="mw-dp-dims"><?php echo $devices[$deviceB]['w']; ?> × <?php echo $devices[$deviceB]['h']; ?></span>
        </div>
        <div class="mw-dp-phone-shell" id="mwDpShellB">
            <div class="mw-dp-notch"></div>
            <div class="mw-dp-screen" id="mwDpScreenB">
                <iframe
                    id="mwDpIframeB"
                    src="<?php echo htmlspecialchars($iframeUrl); ?>"
                    width="<?php echo (int)$devices[$deviceB]['w']; ?>"
                    height="<?php echo (int)$devices[$deviceB]['h']; ?>"
                    frameborder="0"
                    scrolling="yes"
                    title="<?php echo htmlspecialchars($devices[$deviceB]['label']); ?> preview">
                </iframe>
            </div>
            <div class="mw-dp-home-bar"></div>
        </div>
        <!-- Inspect overlay -->
        <div class="mw-dp-inspect-bar" id="mwDpInspectB">
            <span class="mw-dp-inspect-label">Inspect element</span>
            <input type="text" class="mw-dp-inspect-input" placeholder=".mw-mc-compact-main" data-frame="B">
            <button class="mw-dp-inspect-btn" data-frame="B">Inspect</button>
            <div class="mw-dp-inspect-result" id="mwDpResultB"></div>
        </div>
    </div>

</div><!-- /.mw-dp-stage -->

<!-- ── CSS Helper panel ── -->
<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
        <h6 class="mb-0 d-flex align-items-center gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Mobile Card Key Classes
        </h6>
        <small class="text-muted">Use these in the Inspect box above</small>
    </div>
    <div class="card-body py-2 px-3">
        <div class="mw-dp-class-grid">
            <?php
            $keyClasses = [
                '.mw-mc-container'      => 'Outer container (hidden on desktop)',
                '.mw-mc-scroll-area'    => 'Scrollable card list',
                '.mw-mc-card'           => 'Card root (flex-direction: row)',
                '.mw-mc-card-compact'   => 'Compact (untapped) card',
                '.mw-mc-card-hero'      => 'GPS-promoted hero card',
                '.mw-mc-card-body'      => 'Card body (flex:1)',
                '.mw-mc-compact-main'   => 'Time + GO button row (flex)',
                '.mw-mc-compact-info'   => 'Left info block (flex:1)',
                '.mw-mc-compact-route'  => 'Route/GO button base',
                '.mw-mc-compact-go'     => 'GO pill button',
                '.mw-mc-expand-detail'  => 'Expanded detail (inside card-body)',
                '.mw-mc-services'       => 'Service pills row',
                '.mw-mc-photo-strips'   => 'Before/after photo strips',
                '.mw-mc-pill-drawer'    => 'Pill action drawer',
            ];
            foreach ($keyClasses as $cls => $desc): ?>
                <div class="mw-dp-class-row">
                    <code class="mw-dp-class-name"><?php echo htmlspecialchars($cls); ?></code>
                    <span class="mw-dp-class-desc"><?php echo htmlspecialchars($desc); ?></span>
                    <button class="mw-dp-class-copy" onclick="mwDpCopyAndInspect('<?php echo htmlspecialchars($cls); ?>')">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        Inspect
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(function() {

    // ── Scale frames to fit the viewport ────────────────────────────────────
    function scaleFrames() {
        ['A', 'B'].forEach(function(side) {
            var wrap  = document.getElementById('mwDpFrame' + side);
            var shell = document.getElementById('mwDpShell' + side);
            if (!wrap || !shell) return;

            var deviceW  = parseInt(wrap.dataset.width,  10);
            var deviceH  = parseInt(wrap.dataset.height, 10);

            // Phone shell is wider/taller than the device viewport (bezels)
            var shellW = deviceW + 28;   // 14px bezel each side
            var shellH = deviceH + 96;   // notch + home bar

            shell.style.width  = shellW + 'px';
            shell.style.height = shellH + 'px';

            // Available height (viewport minus toolbar, paddings)
            var stageH = window.innerHeight - 280;
            var maxH   = Math.max(400, stageH);
            var scale  = Math.min(1, maxH / shellH, (wrap.offsetWidth - 20) / shellW);

            shell.style.transform       = 'scale(' + scale + ')';
            shell.style.transformOrigin = 'top center';

            // Collapse wrap height so it matches scaled shell
            wrap.style.minHeight = Math.round(shellH * scale + 40) + 'px';
        });
    }

    window.addEventListener('load',   scaleFrames);
    window.addEventListener('resize', scaleFrames);

    // ── Reload frames ────────────────────────────────────────────────────────
    window.mwDpReload = function() {
        ['A', 'B'].forEach(function(side) {
            var frame = document.getElementById('mwDpIframe' + side);
            if (frame) { frame.src = frame.src; }
        });
    };

    // ── Element inspector ────────────────────────────────────────────────────
    // Runs a getComputedStyle query inside the iframe and displays results.
    function runInspect(selector, side) {
        var frame = document.getElementById('mwDpIframe' + side);
        var result = document.getElementById('mwDpResult' + side);
        if (!frame || !result) return;

        result.innerHTML = '<span class="mw-dp-inspect-loading">Inspecting…</span>';

        try {
            var doc = frame.contentDocument || frame.contentWindow.document;
            var el  = doc.querySelector(selector);
            if (!el) {
                result.innerHTML = '<span class="mw-dp-inspect-miss">No element found for <code>' + escHtml(selector) + '</code></span>';
                return;
            }

            var cs  = frame.contentWindow.getComputedStyle(el);
            var rect = el.getBoundingClientRect();

            var props = [
                ['display',         cs.display],
                ['flex-direction',  cs.flexDirection],
                ['align-items',     cs.alignItems],
                ['flex',            cs.flex],
                ['flex-grow',       cs.flexGrow],
                ['flex-shrink',     cs.flexShrink],
                ['flex-basis',      cs.flexBasis],
                ['width',           Math.round(rect.width)  + 'px  (computed)'],
                ['height',          Math.round(rect.height) + 'px  (computed)'],
                ['padding',         cs.padding],
                ['margin',          cs.margin],
                ['overflow',        cs.overflow],
                ['position',        cs.position],
            ];

            var rows = props.map(function(p) {
                var isDefault = (p[1] === 'normal' || p[1] === 'auto' || p[1] === '0px' || p[1] === 'none' || p[1] === '');
                return '<tr class="' + (isDefault ? 'mw-dp-inspect-dim' : 'mw-dp-inspect-highlight') + '">'
                     + '<td class="mw-dp-inspect-prop">' + escHtml(p[0]) + '</td>'
                     + '<td class="mw-dp-inspect-val">'  + escHtml(p[1]) + '</td>'
                     + '</tr>';
            }).join('');

            result.innerHTML = '<table class="mw-dp-inspect-table"><tbody>' + rows + '</tbody></table>';
        } catch(e) {
            result.innerHTML = '<span class="mw-dp-inspect-miss">Cannot inspect (cross-origin or not loaded). Try reloading frames.</span>';
        }
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Wire inspect buttons
    document.querySelectorAll('.mw-dp-inspect-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var side  = btn.dataset.frame;
            var input = document.querySelector('.mw-dp-inspect-input[data-frame="' + side + '"]');
            if (input && input.value.trim()) {
                runInspect(input.value.trim(), side);
            }
        });
    });

    // Wire inspect inputs (Enter key)
    document.querySelectorAll('.mw-dp-inspect-input').forEach(function(input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                var side = input.dataset.frame;
                if (input.value.trim()) runInspect(input.value.trim(), side);
            }
        });
    });

    // Copy-and-inspect from class reference grid
    window.mwDpCopyAndInspect = function(cls) {
        // Fill both inputs and run inspect on both frames
        document.querySelectorAll('.mw-dp-inspect-input').forEach(function(inp) {
            inp.value = cls;
        });
        runInspect(cls, 'A');
        runInspect(cls, 'B');
        // Scroll to inspect bars
        document.getElementById('mwDpStage').scrollIntoView({behavior: 'smooth', block: 'nearest'});
    };

})();
</script>

<?php include 'includes/appstack_footer.php'; ?>
