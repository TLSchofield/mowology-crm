<?php
/**
 * Shared AppStack Sidebar for CRM.
 *
 * Expected variables:
 *   $user         — array with at least 'name' key (from getCurrentUser())
 *   $activePage   — string matching a key below to highlight the active nav item
 *
 * Active page keys: 'dashboard', 'clients', 'companies', 'quotes', 'jobs',
 *                   'invoices', 'schedule', 'timeclock', 'expenses',
 *                   'profitability', 'cost-factors', 'intel', 'marketing', 'social', 'cms', 'media',
 *                   'team', 'leaderboard', 'quiz', 'map', 'live-map', 'photos', 'products', 'portfolio',
 *                   'work-zones', 'users', 'settings', 'privacy', 'certification'
 *
 * Nav items support two types:
 *   - Section headers: ['type' => 'header', 'label' => 'Section Name', 'head' => 'otto'] (head = the department head shown beside it)
 *   - Nav links:       ['key' => '...', 'label' => '...', 'icon' => '...', 'href' => '...', 'perm' => '...']
 *
 * The 'perm' key is optional — if present, the item is hidden if the user
 * lacks that permission. The page itself still enforces access control.
 */
if (!isset($activePage)) $activePage = '';
if (!isset($user))       $user = ['name' => 'Admin'];

// The outside accountant's login sees the Ledger and nothing else (AccountantFence, 2026-10-13).
if (!empty($mwLedgerOnly)): ?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/ledger/"><span class="align-middle">Ledger</span></a>
        <ul class="sidebar-nav">
            <li class="sidebar-header">Ledger</li>
            <?php require_once dirname(__DIR__) . '/ledger/_nav.php';
            $__here = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
            foreach (mwLedgerPages() as $__k => [$__l, $__h, $__i]): ?>
            <li class="sidebar-item<?php echo $__here === $__h ? ' active' : ''; ?>"><a class="sidebar-link" href="<?php echo htmlspecialchars($__h); ?>"><i class="align-middle" data-feather="<?php echo htmlspecialchars($__i); ?>"></i> <span class="align-middle"><?php echo htmlspecialchars($__l); ?></span></a></li>
            <?php endforeach; ?>
            <li class="sidebar-item"><a class="sidebar-link" href="/crm/logout_secure.php"><i class="align-middle" data-feather="log-out"></i> <span class="align-middle">Sign out</span></a></li>
        </ul>
        <p class="mw-ledger-sidebar-note">Read-only accountant access. Each page you open is recorded.</p>
    </div>
</nav>
<?php return; endif;

require_once __DIR__ . '/nav-items.php';   // $navItems + badge counts, shared with the phone menu (mobile-nav.php)

// ── Ittai design: the seven AI heads ARE the navigation (round 6, owner 2026-11-10) ──────────────────
// With the it_design choice on ($itShell, appstack_head.php) each head is a row (portrait ringed by its
// state, name, AI tag, the state in words), opening that head's pages; a square card beside the row says
// what the head is doing. Collapsed, the sidebar is a 72 px rail whose rows open a flyout. Order and seats
// follow the Ittai v1.2 Six + One. Same items, permissions and badges as the classic menu below.
if (!empty($itShell)):
    $__h = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    // The brief behind the state words and Kaname's page is the owner's (same gate as the Action Board).
    $__itOwner = false;
    try {
        require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
        $__itDesk = new CharlieDeskService(getDB());
        $__itOwner = $__itDesk->ready() && $__itDesk->isOwner((array)$user);
    } catch (Throwable $__e) { /* not the owner, then */ }
    $__itBlocks = mwIttaiNavBlocks($navItems, (array)$user, (string)$activePage, $__itOwner);
    // Order (Tim, 2026-11-10): Kaname first; the head you are with second, so the open list and the card always
    // land in the same place; then the rest in the order a job travels — Mia brings it in, Sam prices it, Otto
    // does it, Yui talks to the client, Penny banks it, Ada keeps the lights on. The phone menu keeps the Six + One order.
    $__itPipeline = ['mia', 'sam', 'otto', 'yui', 'penny', 'ada'];
    $__itFirst = []; $__itHere = []; $__itRest = [];
    foreach ($__itBlocks as $__b) {
        if ($__b['slug'] === 'charlie') $__itFirst[] = $__b;
        elseif (!empty($__b['here'])) $__itHere[] = $__b;
        else $__itRest[(int)array_search($__b['slug'], $__itPipeline, true)] = $__b;
    }
    ksort($__itRest);
    $__itBlocks = array_merge($__itFirst, $__itHere, array_values($__itRest));
    unset($__itPipeline, $__itFirst, $__itHere, $__itRest, $__b);
    $__itNav = ['heads' => []];
    try {
        require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/HeadNavService.php';
        $__itNav = HeadNavService::forShell(getDB(), $__itOwner);
    } catch (Throwable $__e) { /* the menu still works without the state words */ }
    require_once APP_ROOT . '/Services/BusinessIdentity.php';
    require_once __DIR__ . '/ittai-icons.php';
    $__itBiz = (string)(BusinessIdentity::get()['name'] ?? '');
    if ($__itBiz === '') $__itBiz = 'Ittai';
    $__itWords = preg_split('/\s+/', trim($__itBiz)) ?: [$__itBiz];
    $__itInit = count($__itWords) > 1 ? mb_substr($__itWords[0], 0, 1) . mb_substr($__itWords[1], 0, 1) : mb_substr($__itBiz, 0, 2);
    $__itInit = mb_strtoupper($__itInit);
    $__itRole = ucfirst((string)($user['role'] ?? 'staff'));
    $__itUser = (string)($user['name'] ?? '');
    $__itMac = PHP_OS === 'Darwin' || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'mac') !== false;
    $__itSymbol = is_file(dirname(__DIR__) . '/img/ittai/ittai-symbol-24.svg') ? '/crm/img/ittai/ittai-symbol-24.svg' : '';
    $__itStates = ['working', 'approval', 'blocked', 'idle', 'unavailable'];
    // Which group is open on load: the one holding this page, else the chief of staff's.
    $__itOpen = '';
    foreach ($__itBlocks as $__b) { if (!empty($__b['here'])) { $__itOpen = $__b['slug']; break; } }
    if ($__itOpen === '') $__itOpen = 'charlie';
    itIconSprite();
?>
<nav id="sidebar" class="sidebar it-sidebar" aria-label="Main navigation">
    <?php /* Outside the scroll container so SimpleBar can't clip it: sits on the sidebar's right edge. */ ?>
    <button type="button" class="it-cb" aria-label="Collapse sidebar"><svg class="it-ic" width="24" height="24" aria-hidden="true"><use href="#it-i-left"/></svg></button>
    <div class="sidebar-content js-simplebar">
        <div class="it-top">
            <a class="it-mark" href="/crm/dashboard_appstack.php" aria-label="Ittai, home"><?php if ($__itSymbol !== ''): ?><img class="it-logo" src="<?php echo $__h($__itSymbol); ?>" alt="" width="24" height="24"><?php endif; ?><img class="it-wm" src="/crm/img/ittai/ittai-wordmark.svg" alt="" height="26"></a>
        </div>
        <div class="it-srch">
            <button type="button" class="it-sf" data-spotlight-open aria-label="Search clients, addresses, buildings, phone numbers"><svg class="it-ic" width="24" height="24" aria-hidden="true"><use href="#it-i-search"/></svg><span class="it-sf-text">Search</span><span class="it-kc" aria-hidden="true"><kbd><?php echo $__itMac ? '⌘' : 'Ctrl'; ?></kbd><kbd>K</kbd></span></button>
        </div>
        <nav class="it-nv" aria-label="Areas of the business"><ul class="it-gl">
        <?php foreach ($__itBlocks as ['slug' => $__slug, 'name' => $__name, 'role' => $__role, 'here' => $__here, 'items' => $__items]):
            $__hd    = (array)(($__itNav['heads'] ?? [])[$__slug] ?? []);
            $__st    = in_array($__hd['state'] ?? '', $__itStates, true) ? $__hd['state'] : '';
            $__word  = (string)($__hd['word'] ?? '');
            if ($__st === '') $__word = '';   // no known state: no state span at all
            $__line  = (string)($__hd['line'] ?? '');
            if ($__line === '') $__line = (string)($__hd['reason'] ?? '');
            $__ws    = (string)($__hd['workspace'] ?? '');
            $__ring  = 'it-pt it-pt-' . ($__st !== '' ? $__st : 'none');
            $__isOpen = ($__slug === $__itOpen);
            // The head you are with (the group holding this page) keeps its card open on every page, so a page
            // click never dismisses it and the live line runs from the page up into the card (Tim, 2026-11-10).
            $__sel   = (bool)$__here;
            $__aria  = $__name . ', AI, ' . $__role . ($__word !== '' ? '. ' . $__word . '.' : '.');
            $__stHtml = $__word !== ''
                ? '<span class="it-s it-s-' . $__st . '"><svg class="it-si" width="12" height="12" aria-hidden="true"><use href="#it-st-' . $__st . '"/></svg>' . $__h($__word) . '</span>'
                : '';
            
            // The head's pages: printed once, used by the open group and again in the rail flyout.
            ob_start();
            foreach ($__items as $__item): $__on = ($activePage === $__item['key']); ?>
                <li><a class="it-pg" href="<?php echo $__h($__item['href']); ?>"<?php echo $__on ? ' aria-current="page"' : ''; ?>><span class="it-pl"><?php echo $__h($__item['label']); ?></span><?php if (!empty($__item['badge']) && (int)$__item['badge'] > 0): ?><span class="it-chip"><?php echo (int)$__item['badge']; ?></span><?php endif; ?></a></li>
            <?php endforeach;
            $__pages = ob_get_clean();
        ?>
            <li class="it-grp<?php echo $__sel ? ' is-sel' : ''; ?><?php echo $__here ? ' is-here' : ''; ?><?php echo $__slug === 'charlie' ? ' it-kan' : ''; ?>" data-head="<?php echo $__h($__slug); ?>" data-workspace="<?php echo $__h($__ws); ?>">
                <button type="button" class="it-grow" aria-expanded="<?php echo $__isOpen ? 'true' : 'false'; ?>" aria-controls="it-acc-<?php echo $__h($__slug); ?>" aria-label="<?php echo $__h($__aria); ?>">
                    <?php /* Round 7 (Tim's sketch, 2026-11-10): name on the left, the portrait on the spine at the right; the state is
                             the ring and glyph (the word stays in the aria-label and on the card); Kaname carries the owner's count. */ ?>
                    <span class="it-gt"><span class="it-l1"><b><?php echo $__h($__name); ?></b><span class="it-tag">AI</span><?php if ($__slug === 'charlie' && (int)($__hd['needs_you'] ?? 0) > 0): ?><span class="it-chip it-chip-need"><?php echo $__h($__word); ?></span><?php endif; ?></span><?php if ($__slug === 'charlie'): ?><span class="it-l2"><?php echo $__h($__role); ?></span><?php endif; ?></span>
                    <span class="<?php echo $__ring; ?>"><img src="/crm/img/heads/<?php echo $__h($__slug); ?>.jpg" width="36" height="36" alt="" loading="lazy"><?php if ($__st !== ''): ?><svg class="it-bd it-bd-<?php echo $__st; ?>" width="16" height="16" aria-hidden="true"><use href="#it-st-<?php echo $__st; ?>"/></svg><?php endif; ?></span>
                </button>
                <div class="it-acc" id="it-acc-<?php echo $__h($__slug); ?>"<?php echo $__isOpen ? '' : ' hidden'; ?>><div class="it-acci"><ul class="it-subs"><?php echo $__pages; ?></ul></div></div>
                <div class="it-hp<?php echo $__sel ? ' is-open' : ''; ?>" data-head="<?php echo $__h($__slug); ?>" role="group" aria-label="<?php echo $__h($__name . ', AI, ' . $__role . '. ' . ($__word !== '' ? $__word . ': ' : '') . $__line); ?>">
                    <span class="<?php echo $__ring; ?> it-pt-lg"><img class="it-hp-face" src="/crm/img/heads/<?php echo $__h($__slug); ?>.jpg" width="44" height="44" alt="" loading="lazy"></span>
                    <span class="it-hpn"><b><?php echo $__h($__name); ?></b><span class="it-tag">AI</span></span>
                    <span class="it-hpd"><?php echo $__h($__role); ?></span>
                    <?php echo $__stHtml; ?>
                    <?php if ($__line !== ''): ?><p class="it-hpl"><?php echo $__h($__line); ?></p><?php endif; ?>
                </div>
                <div class="it-fly" data-head="<?php echo $__h($__slug); ?>" role="group" aria-label="<?php echo $__h($__name . ', ' . $__role); ?>">
                    <div class="it-fh">
                        <span class="<?php echo $__ring; ?>"><img src="/crm/img/heads/<?php echo $__h($__slug); ?>.jpg" width="36" height="36" alt="" loading="lazy"></span>
                        <span class="it-gt"><span class="it-l1"><b><?php echo $__h($__name); ?></b><span class="it-tag">AI</span></span><span class="it-l2"><?php echo $__h($__role); ?></span></span>
                        <?php echo $__stHtml; ?>
                    </div>
                    <ul class="it-subs"><?php echo $__pages; ?></ul>
                </div>
            </li>
        <?php endforeach; ?>
        </ul></nav>
        <div class="it-prof">
            <a class="it-pcard" href="/crm/settings.php" aria-label="<?php echo $__h($__itBiz . ', ' . $__itUser . ', ' . $__itRole . '. Business settings'); ?>"><span class="it-disc"><?php echo $__h($__itInit); ?></span><span class="it-pt2"><b><?php echo $__h($__itBiz); ?></b><span><?php echo $__h($__itUser . ' · ' . $__itRole); ?></span></span><svg class="it-ch it-ic" width="16" height="16" aria-hidden="true"><use href="#it-i-updown"/></svg></a>
        </div>
    </div>
</nav>
<?php return; endif;
?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="/crm/dashboard_appstack.php">
            <span class="align-middle" style="font-size:1.25rem; letter-spacing:-0.5px;"><?php echo htmlspecialchars(function_exists('_idFromName') ? _idFromName() : ''); ?></span>
        </a>

        <ul class="sidebar-nav">

            <?php foreach ($navItems as $item):

                // Section header
                if (isset($item['type']) && $item['type'] === 'header'): ?>
                    <li class="sidebar-header<?php echo !empty($item['head']) ? ' mw-sb-headed' : ''; ?>"><?php echo htmlspecialchars($item['label']); ?>
                        <?php if (!empty($item['head'])): $__h = $item['head']; ?>
                        <span class="mw-sb-head" title="<?php echo htmlspecialchars(ucfirst($__h)); ?> heads this department"><img src="/crm/img/heads/<?php echo htmlspecialchars($__h); ?>.jpg" alt="" width="20" height="20" loading="lazy"><?php echo htmlspecialchars(ucfirst($__h)); ?></span>
                        <?php endif; ?>
                    </li>
                <?php continue; endif;

                // Permission check
                if (isset($item['perm']) && function_exists('userHasPermission') && !userHasPermission($item['perm'])) continue;
            ?>
            <li class="sidebar-item<?php echo ($activePage === $item['key']) ? ' active' : ''; ?>">
                <a class="sidebar-link" href="<?php echo $item['href']; ?>">
                    <i class="align-middle" data-feather="<?php echo $item['icon']; ?>"></i>
                    <span class="align-middle"><?php echo $item['label']; ?></span>
                    <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                    <span class="mw-sidebar-badge mw-msg-sidebar-badge"><?php echo (int)$item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>

            <li class="sidebar-header">Admin</li>

            <?php if (!function_exists('userHasPermission') || userHasPermission('settings.edit')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'audit-trail') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/audit-trail_appstack.php">
                    <i class="align-middle" data-feather="shield"></i>
                    <span class="align-middle">Audit Trail</span>
                </a>
            </li>
            <li class="sidebar-item<?php echo ($activePage === 'import') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/import_appstack.php">
                    <i class="align-middle" data-feather="upload"></i>
                    <span class="align-middle">Import Data</span>
                </a>
            </li>
            <li class="sidebar-item<?php echo ($activePage === 'privacy') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/privacy_appstack.php">
                    <i class="align-middle" data-feather="lock"></i>
                    <span class="align-middle">Privacy &amp; Data</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('database.manage')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'sites') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/sites_appstack.php">
                    <i class="align-middle" data-feather="globe"></i>
                    <span class="align-middle">Tenant Sites</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('users.manage')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'users') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/users_appstack.php">
                    <i class="align-middle" data-feather="user-plus"></i>
                    <span class="align-middle">Users &amp; Roles</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (!function_exists('userHasPermission') || userHasPermission('settings.edit')): ?>
            <li class="sidebar-item<?php echo ($activePage === 'settings') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/settings.php">
                    <i class="align-middle" data-feather="settings"></i>
                    <span class="align-middle">Settings</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <li class="sidebar-item<?php echo ($activePage === 'system-health') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/ada_appstack.php">
                    <i class="align-middle" data-feather="activity"></i>
                    <span class="align-middle">System Health</span>
                </a>
            </li>
            <li class="sidebar-item<?php echo ($activePage === 'agents') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/agents_appstack.php">
                    <i class="align-middle" data-feather="users"></i>
                    <span class="align-middle">Agent Studio</span>
                </a>
            </li>
            <li class="sidebar-item<?php echo ($activePage === 'system-log') ? ' active' : ''; ?>">
                <a class="sidebar-link" href="/crm/system-log_appstack.php">
                    <i class="align-middle" data-feather="alert-circle"></i>
                    <span class="align-middle">System Log</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Install App (PWA) — hidden when already installed or on desktop -->
            <li class="sidebar-item" id="mw-pwa-sidebar-item">
                <a class="sidebar-link" href="#" id="mw-pwa-sidebar-link"
                   onclick="event.preventDefault();if(window.mwDeferredInstall){window.mwDeferredInstall.prompt();}else{alert('Tap your browser menu → Add to Home Screen');}">
                    <i class="align-middle" data-feather="download"></i>
                    <span class="align-middle">Install App</span>
                </a>
            </li>

        </ul>
    </div>
</nav>
