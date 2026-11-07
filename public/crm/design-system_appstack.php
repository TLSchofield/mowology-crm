<?php
/**
 * Design System — the Ittai v1.2 baseline and §13 "The Kaname centre line", laid out for a lead designer to review
 * (Tim, 2026-11-06). Read-only. Tokens come from assets/design-system/tokens.json (a copy of
 * docs/design/ittai-v1.2/tokens.json — production ships only public/; tests/Unit/Docs/DesignSystemPageTest keeps the two
 * identical). Screenshots are from the iPhone simulator's demo modes: invented data, no client names.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

$pageTitle = 'Design System';
$activePage = 'settings';
// The --it-* tokens: appstack_head loads ittai-tokens.css only with the "new design" cookie; this page always needs them.
$extraHead = '<link href="/crm/css/ittai-tokens.css?v=' . (int)@filemtime(__DIR__ . '/css/ittai-tokens.css') . '" rel="stylesheet">';

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tokens = json_decode((string)@file_get_contents(__DIR__ . '/assets/design-system/tokens.json'), true) ?: [];
$colors = $tokens['color']['tokens'] ?? [];
$themes = array_values(array_filter($tokens['color']['themes'] ?? [], fn($t) => is_array($t) && isset($t['id'], $t['name'])));
$cssLen = fn($v, $d) => preg_match('/^[0-9.]+(px|rem|em)?$/', (string)$v) ? (string)$v : $d;   // type sizes from tokens.json
$typeGroups = $tokens['type']['groups'] ?? [];
$scales = [
    'Spacing' => $tokens['spacing']['tokens'] ?? [],
    'Radius'  => $tokens['radius']['tokens'] ?? [],
    'Stroke'  => $tokens['stroke']['tokens'] ?? [],
    'Size'    => $tokens['size']['tokens'] ?? [],
];
$img = '/crm/assets/design-system/';

// §13, rule by rule: what it says, why, and where it can be seen.
$rules = [
    ['The line', 'A 1 pt line in the line token runs down the middle of a head\'s decision screen into the active tab\'s bead. It reads as "Kaname at work": every decision leads back to him.', 'All Penny cards; the Schedule timeline.'],
    ['The line belongs to the active tab', 'It sits on the axis of the tab you are on and enters its bead at 12 o\'clock: centred on Kaname, down the left on Schedule. Not centred by rule — connected by rule.', 'Schedule: the chosen day, the stops, the Schedule bead.'],
    ['Only for sequence or pairs', 'A timeline or a comparison. Plain lists stay hairline rows. One line per screen.', 'Receipts and Search have no line.'],
    ['Comparisons sit on it', 'Two things compared take its left and right; values hug the line (left column right-aligned, right column left-aligned).', 'Cheque vs invoice; two receipts.'],
    ['Agreement is a ringed tick on the line', 'A page-coloured gap cuts the line, a 1 pt ring, a filled check in brand indigo. A row that differs has no tick; the differing value gets an attention wash.', 'Amount, name, date rows.'],
    ['Text never sits on the line', 'Words that span the width sit on page colour so the line breaks round them; a column\'s own words stay in its column (the bank memo in the cheque\'s, "Or INV-…" in the invoice\'s).', 'Cheque card memo row.'],
    ['One column grid per panel', 'Facts, photos and buttons share the panel\'s edges and one 46 pt gap; no row has its own side padding or width cap.', 'Two- and three-receipt cards.'],
    ['Panels are framed in the same weight', '1 pt line, radius 14, page fill. The line passes behind the frame.', 'Every Penny panel; Your business.'],
    ['A section starts with its icon on the line', 'A filled brand circle with a white glyph, in the tick\'s gap and ring, 56 pt — in place of a words heading (the words move to the VoiceOver label). Bank for cheques, copies for duplicates, building for the business.', 'Top of each section.'],
    ['Then the numbers', 'A carousel of groups hangs on the axis under the section icon, current one enlarged on the line.', 'Duplicate groups 1, 2, 3…'],
    ['Three or more: the best one in the middle', 'The receipt kept (in the books / most data) takes the middle column; the axis splits round it as one frame whose sides carry the ticks, and rejoins below. Side receipts are not framed.', 'Three-receipt card.'],
    ['Two square buttons', 'The panel\'s two halves either side of the gap, equal size whatever their text: primary filled, the other outlined and page-filled so the line passes behind it.', 'Record / Not now; Merge / Different.'],
    ['Two become one', 'When a decision joins things — a merge, a cheque recorded on its invoice — the card shows one thing centred on the axis and one way on, centred.', 'Merged receipt + Unmerge; paid invoice + Next.'],
    ['Setup headings go once learned', '"Getting to know your business" shows only while the head is still learning; once confirmed only the framed result stays.', 'Penny: Your business.'],
    ['No self-congratulation', 'Lines like "I\'ve been right 14 of 14 times" add nothing and cross the axis. Removed.', 'Duplicates.'],
    ['Desktop', 'The same panel lies sideways from 768 px: the comparison left, the two squares right.', 'Web Penny card (cheques).'],
];

$examples = [
    ['ios-two.jpg', 'Two receipts', 'Copies icon on the axis, the numbers under it, values hugging the line, ticks on it, photos and buttons on the same grid.'],
    ['ios-three.jpg', 'Three receipts', 'The kept receipt in the middle; the axis splits round it, its sides carry the ticks; side receipts unframed.'],
    ['ios-merged.jpg', 'Two become one — merged', 'The kept receipt alone, centred; one Unmerge, centred.'],
    ['ios-cheque.jpg', 'Likely cheque', 'Bank icon; verdict on page colour; the memo in the cheque\'s column; Record / Not now. The line runs on to the duplicates\' icon.'],
    ['ios-chequedone.jpg', 'Two become one — recorded', 'The paid invoice with its cheque inside it; one Next, centred.'],
    ['ios-schedule.jpg', 'Schedule', 'The chosen day snaps onto the Schedule bead\'s axis; the line runs through the stops into the bead.'],
];

$donts = [
    ['Text across the line', 'The memo ran full width over the axis.', 'Put a column\'s words in its column; page-colour anything that must span.'],
    ['Frames between the ticks', 'A frame round every receipt left the ticks floating in the gaps.', 'Ticks sit on a line: frame only the middle one, whose sides are the line.'],
    ['A words heading', '"Same receipt" as a title above the comparison.', 'The section icon on the axis; the words go to VoiceOver.'],
    ['Rows off the grid', 'Buttons capped at 150 pt with 16 pt gaps under a 46 pt table gap.', 'One grid: same edges, same gap, every row.'],
    ['Two after joining', 'A merged pair still shown side by side with a "Merged" tile.', 'One thing centred, one way on.'],
    ['A line that overshoots', 'A cheque\'s line ran 400 pt on through the next card.', 'Stop at the next section\'s icon; only the last runs on to the bead.'],
];

$where = [
    ['Line, ticks, frames, square buttons', 'PennyCardView.swift (columnGap 46, singleColumn 150, sectionIcon 56)', 'Web cheque panel only'],
    ['Likely cheque / recorded cheque', 'PennyChequeCard.swift (PennyChequeCard, PennyChequeDoneCard)', 'bookkeeper-card.js (no recorded state yet)'],
    ['Section icon on Your business', 'TeamView.swift onboardingSection; ItPennyOnboarding.swift', '—'],
    ['Schedule axis + day carousel', 'DayListView.swift (railAxis), WeekStripView.swift (DayCarouselView), MainTabView.swift (ItTabSlot)', 'Phone only; iPad keeps the week grid'],
    ['Matching behind the cheques', 'InvoiceReconciliationService::likelyCheques()', 'Same server for both'],
];

$questions = [
    'The web Penny card shows duplicates only after every cheque is answered, and is not on the line yet. Bring it across, or keep the web denser?',
    'The cheque card still has its verdict sentence and confidence line on page colour. Keep (it says which invoice), or reduce to the icon like the duplicates?',
    'iPad: the day carousel is phone-only. Should a wide screen keep the week grid, or carry the line too?',
    'Four or more duplicate receipts: there is no middle column; the axis falls in a gap. Acceptable, or cap the view at three?',
    'Should the section icons (bank, copies, building) join the frozen icon set formally?',
];
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-ds it-design">

  <header class="mw-ds-cover">
    <img class="mw-ds-symbol" src="<?= $img ?>ittai-symbol.svg" alt="Ittai symbol" width="64" height="64">
    <div>
      <p class="mw-ds-eyebrow">Ittai Design System</p>
      <h1 class="mw-ds-title">v1.2 baseline &middot; §13 The Kaname centre line</h1>
      <p class="mw-ds-lede">For review by the lead designer. v1.2 is the frozen baseline (17 Oct 2026). §13 was added on 6 Nov 2026 from Tim's direction on the iPhone app (builds 1.3.97–1.3.104). Source of truth: <code>docs/design/ittai-v1.2/spec/SPEC-v1.2.md</code> and <code>tokens.json</code>; brand book: <a href="https://claude.ai/artifact/YDZaYnmRc8CAbqtz8oC5uk" target="_blank" rel="noopener">artifact</a>.</p>
    </div>
    <span class="mw-ds-status"><i data-feather="eye"></i> For review</span>
  </header>

  <nav class="mw-ds-toc" aria-label="Sections">
    <a href="#principles">1 Principles</a><a href="#tokens">2 Tokens</a><a href="#type">3 Type</a><a href="#scales">4 Scales</a>
    <a href="#centre-line">5 The centre line</a><a href="#anatomy">6 Anatomy</a><a href="#examples">7 Examples</a>
    <a href="#donts">8 Do / don't</a><a href="#where">9 Where it lives</a><a href="#questions">10 Open questions</a>
  </nav>

  <section id="principles" class="mw-ds-section">
    <h2>1 &nbsp;Principles (v1.2)</h2>
    <div class="mw-ds-grid3">
      <div class="mw-ds-card"><h3>Tokens only</h3><p>Every colour is a token; no literals. Never hand-write a tenant colour.</p></div>
      <div class="mw-ds-card"><h3>Inter, three weights</h3><p>400 / 500 / 600, sentence case, nothing under 12 px.</p></div>
      <div class="mw-ds-card"><h3>Rows before cards</h3><p>One hairline list is the default shape; cards only for distinct objects.</p></div>
      <div class="mw-ds-card"><h3>One dominant action</h3><p>A filled primary only where it is urgent; everything else quiet.</p></div>
      <div class="mw-ds-card"><h3>Status is icon + word</h3><p>Never colour alone. Two vocabularies, one icon set.</p></div>
      <div class="mw-ds-card"><h3>Touch and contrast</h3><p>44 px desktop, 48 px phone, 56 px main action; AAA 7:1 text in light, dark and field.</p></div>
    </div>
  </section>

  <section id="tokens" class="mw-ds-section">
    <h2>2 &nbsp;Colour tokens</h2>
    <p class="mw-ds-note">Values printed from <code>tokens.json</code>. Modes: <?= $e(implode(' · ', array_column($themes, 'name'))) ?>.</p>
    <div class="table-responsive">
      <table class="table mw-ds-table">
        <thead><tr><th>Token</th><?php foreach ($themes as $t): ?><th><?= $e($t['name']) ?></th><?php endforeach; ?><th>Use</th></tr></thead>
        <tbody>
        <?php foreach ($colors as $c): $v = $c['value'] ?? []; ?>
          <tr>
            <td><code>--it-<?= $e($c['name']) ?></code></td>
            <?php foreach ($themes as $t): $hex = is_array($v) ? ($v[$t['id']] ?? '') : (string)$v; ?>
              <td>
                <?php if (preg_match('/^#[0-9A-Fa-f]{3,8}$/', $hex)): ?>
                  <?php /* The swatch shows the token's own value from tokens.json — the source, not a literal. */ ?>
                  <span class="mw-ds-swatch" style="background: <?= $e($hex) ?>"></span>
                <?php endif; ?>
                <code><?= $e($hex) ?></code>
              </td>
            <?php endforeach; ?>
            <td class="mw-ds-use"><?= $e($c['usage'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section id="type" class="mw-ds-section">
    <h2>3 &nbsp;Type</h2>
    <?php foreach ($typeGroups as $g): ?>
      <h3 class="mw-ds-sub"><?= $e($g['name'] ?? '') ?></h3>
      <div class="mw-ds-type">
        <?php foreach ($g['styles'] ?? [] as $st): ?>
          <div class="mw-ds-type-row">
            <div class="mw-ds-type-meta"><code><?= $e($st['name'] ?? '') ?></code><span><?= $e(($st['fontSize'] ?? '') . ' / ' . ($st['lineHeight'] ?? '') . ' · ' . ($st['fontWeight'] ?? '')) ?></span></div>
            <div class="mw-ds-type-sample" style="font-size: <?= $e($cssLen($st['fontSize'] ?? '', '16px')) ?>; line-height: <?= $e($cssLen($st['lineHeight'] ?? '', 'normal')) ?>; font-weight: <?= (int)($st['fontWeight'] ?? 400) ?>"><?= $e($st['sample'] ?? '') ?></div>
            <div class="mw-ds-use"><?= $e($st['usage'] ?? '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <section id="scales" class="mw-ds-section">
    <h2>4 &nbsp;Spacing, radius, stroke, size</h2>
    <div class="mw-ds-grid2">
      <?php foreach ($scales as $label => $rows): ?>
        <div class="mw-ds-card">
          <h3><?= $e($label) ?></h3>
          <table class="table table-sm mw-ds-table mb-0">
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr><td><code><?= $e($r['name'] ?? '') ?></code></td><td><?= $e($r['value'] ?? '') ?></td><td class="mw-ds-use"><?= $e($r['usage'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="centre-line" class="mw-ds-section">
    <h2>5 &nbsp;§13 The Kaname centre line</h2>
    <p class="mw-ds-note">A head's decision screen hangs on one thin vertical line that runs into the active tab's button. Sixteen rules; each names where it can be seen.</p>
    <ol class="mw-ds-rules">
      <?php foreach ($rules as [$title, $text, $seen]): ?>
        <li><h3><?= $e($title) ?></h3><p><?= $e($text) ?></p><p class="mw-ds-seen"><i data-feather="eye"></i> <?= $e($seen) ?></p></li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section id="anatomy" class="mw-ds-section">
    <h2>6 &nbsp;Anatomy</h2>
    <div class="mw-ds-anatomy">
      <svg viewBox="0 0 420 560" role="img" aria-labelledby="ds-anat-title" class="mw-ds-svg">
        <title id="ds-anat-title">Anatomy of a comparison panel on the centre line</title>
        <line class="mw-ds-line" x1="210" y1="0" x2="210" y2="560"/>
        <circle class="mw-ds-gap" cx="210" cy="58" r="34"/><circle class="mw-ds-ring" cx="210" cy="58" r="31"/><circle class="mw-ds-brand" cx="210" cy="58" r="25"/>
        <rect class="mw-ds-glyph" x="199" y="47" width="16" height="20" rx="2"/><rect class="mw-ds-glyph-back" x="204" y="43" width="16" height="20" rx="2"/>
        <rect class="mw-ds-frame" x="20" y="120" width="380" height="400" rx="14"/>
        <g class="mw-ds-text"><text x="190" y="160" text-anchor="end">#126</text><text x="230" y="160">#254 · kept</text></g>
        <?php foreach ([200, 245, 290] as $y): ?>
          <line class="mw-ds-row" x1="36" y1="<?= $y + 18 ?>" x2="384" y2="<?= $y + 18 ?>"/>
          <g class="mw-ds-text"><text x="186" y="<?= $y + 4 ?>" text-anchor="end">$20.00</text><text x="234" y="<?= $y + 4 ?>">$20.00</text></g>
          <circle class="mw-ds-gap" cx="210" cy="<?= $y ?>" r="14"/><circle class="mw-ds-ring" cx="210" cy="<?= $y ?>" r="12.5"/><circle class="mw-ds-brand" cx="210" cy="<?= $y ?>" r="9"/>
          <polyline class="mw-ds-check" points="205,<?= $y ?> 209,<?= $y + 4 ?> 216,<?= $y - 4 ?>"/>
        <?php endforeach; ?>
        <rect class="mw-ds-primary" x="36" y="360" width="151" height="140" rx="16"/>
        <rect class="mw-ds-outline" x="233" y="360" width="151" height="140" rx="16"/>
        <g class="mw-ds-text mw-ds-on"><text x="111" y="436" text-anchor="middle">Merge</text></g>
        <g class="mw-ds-text mw-ds-tenant"><text x="308" y="436" text-anchor="middle">Different</text></g>
        <g class="mw-ds-dim">
          <text x="250" y="62">section icon 56 pt</text>
          <text x="232" y="330">tick: ring 25 · gap 27, on the line</text>
          <text x="232" y="350">column gap 46</text>
          <text x="40" y="540">frame 1 pt · radius 14</text>
          <text x="226" y="540">line 1 pt</text>
        </g>
      </svg>
      <dl class="mw-ds-measures">
        <dt>Line</dt><dd>1 pt, <code>--it-line</code>; from the section icon's centre down into the active tab's bead.</dd>
        <dt>Tick</dt><dd>27 pt page-coloured gap, 25 pt ring 1 pt, filled check in <code>--it-brand</code>.</dd>
        <dt>Section icon</dt><dd>56 pt ring, 62 pt gap, 46 pt brand disc, white glyph at 36% of the ring.</dd>
        <dt>Grid</dt><dd>Panel padding 8 pt; one 46 pt gap for facts, photos and buttons.</dd>
        <dt>Frame</dt><dd>1 pt <code>--it-line</code>, radius 14, page fill.</dd>
        <dt>Square buttons</dt><dd>Half the panel each (≈150 pt on a 393 pt phone), radius 16; outlined ones page-filled.</dd>
        <dt>Single column</dt><dd>150 pt, centred on the axis: the merged receipt, Unmerge, Next.</dd>
      </dl>
    </div>
  </section>

  <section id="examples" class="mw-ds-section">
    <h2>7 &nbsp;Examples</h2>
    <p class="mw-ds-note">iPhone simulator, demo data (invented receipts and clients), build 1.3.104.</p>
    <div class="mw-ds-shots">
      <?php foreach ($examples as [$file, $title, $text]): ?>
        <figure class="mw-ds-shot">
          <a href="<?= $img . $e($file) ?>" target="_blank" rel="noopener"><img src="<?= $img . $e($file) ?>" alt="<?= $e($title) ?>" loading="lazy"></a>
          <figcaption><strong><?= $e($title) ?></strong><span><?= $e($text) ?></span></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="donts" class="mw-ds-section">
    <h2>8 &nbsp;Do / don't</h2>
    <p class="mw-ds-note">Corrections made on 6 Nov 2026, each now a rule.</p>
    <div class="mw-ds-grid2">
      <?php foreach ($donts as [$title, $dont, $do]): ?>
        <div class="mw-ds-card mw-ds-dd">
          <h3><?= $e($title) ?></h3>
          <p class="mw-ds-dont"><i data-feather="x-octagon"></i> <span><strong>Don't</strong> <?= $e($dont) ?></span></p>
          <p class="mw-ds-do"><i data-feather="check-circle"></i> <span><strong>Do</strong> <?= $e($do) ?></span></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="where" class="mw-ds-section">
    <h2>9 &nbsp;Where it lives</h2>
    <div class="table-responsive">
      <table class="table mw-ds-table">
        <thead><tr><th>Piece</th><th>iPhone</th><th>Web / Android</th></tr></thead>
        <tbody><?php foreach ($where as [$a, $b, $c]): ?><tr><td><?= $e($a) ?></td><td><code><?= $e($b) ?></code></td><td><?= $e($c) ?></td></tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </section>

  <section id="questions" class="mw-ds-section">
    <h2>10 &nbsp;Open questions for review</h2>
    <ol class="mw-ds-questions"><?php foreach ($questions as $q): ?><li><?= $e($q) ?></li><?php endforeach; ?></ol>
  </section>

</div>

<?php include 'includes/appstack_footer.php'; ?>
