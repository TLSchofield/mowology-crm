<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

$pageTitle  = 'Photos at another property';
$activePage = 'photos';  // sidebar has no item for this page; it sits under Photos
$csrfToken  = generateCSRFToken();
$extraHead  = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Photos taken at another property</h1>
        <p class="mw-page-subtitle">Each group is one visit whose photos were taken somewhere else (usually the visit was left open at the last stop). Nothing moves until you approve it, and every move can be undone.</p>
    </div>
</div>

<div id="mwRefileMsg" class="mw-refile-msg" style="display:none"></div>
<div id="mwRefileGroups"><p class="text-muted">Loading…</p></div>

<h4 class="mt-4">Recent moves</h4>
<div id="mwRefileRecent"><p class="text-muted">None.</p></div>

<script>
(function () {
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var API = 'api/photo-refile.php';
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;'); }
  function msg(t) { var m = document.getElementById('mwRefileMsg'); m.textContent = t; m.style.display = t ? '' : 'none'; }
  function post(body) {
    body.csrf_token = CSRF;
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function render(data) {
    var box = document.getElementById('mwRefileGroups');
    if (data.needs_migration) { box.innerHTML = '<p class="text-muted">The photo move log is not set up yet (migration 1459). It applies itself on the next database update.</p>'; return; }
    if (!data.groups.length) { box.innerHTML = '<p class="text-muted">No photos look like they are at the wrong property.</p>'; }
    else box.innerHTML = data.groups.map(function (g, gi) {
      var ids = g.photos.map(function (p) { return p.media_id; });
      var thumbs = g.photos.map(function (p) { return '<img class="mw-refile-thumb" loading="lazy" src="' + esc(p.thumb) + '" alt="">'; }).join('');
      var act;
      if (g.blocked) act = '<span class="text-muted">A photo here is in a portfolio before/after pair, so it cannot be moved from here.</span>';
      else if (!g.target_property) act = '<span class="text-muted">No single property border holds these photos, so there is no suggested move.</span>';
      else if (!g.target_visits.length) act = '<span class="text-muted">Taken at ' + esc(g.target_property.name) + ', which has no visit on ' + esc(g.visit_date) + '.</span>';
      else {
        var opts = g.target_visits.map(function (v) { return '<option value="' + v.id + '">Visit ' + esc(v.label) + '</option>'; }).join('');
        act = 'Move to <strong>' + esc(g.target_property.name) + '</strong> <select id="mwTo' + gi + '" class="form-control form-control-sm d-inline-block w-auto">' + opts + '</select> ' +
              '<button class="btn btn-sm btn-primary" data-act="move" data-g="' + gi + '">Move ' + ids.length + ' photo' + (ids.length > 1 ? 's' : '') + '</button> ';
      }
      return '<div class="mw-refile-group card"><div class="card-body">' +
        '<div class="mw-refile-head"><strong>Visit #' + g.visit_id + '</strong> · ' + esc(g.visit_date) + ' · filed to <strong>' + esc(g.filed_property.name) + '</strong> · taken about ' +
        Math.round(g.photos[0].distance_m / 100) / 10 + ' km away</div>' +
        '<div class="mw-refile-thumbs">' + thumbs + '</div>' +
        '<div class="mw-refile-actions">' + act + '<button class="btn btn-sm btn-outline-secondary" data-act="dismiss" data-g="' + gi + '">These are fine</button></div>' +
        '</div></div>';
    }).join('');
    box.onclick = function (e) {
      var b = e.target.closest('button[data-act]'); if (!b) return;
      var g = data.groups[+b.dataset.g], ids = g.photos.map(function (p) { return p.media_id; });
      b.disabled = true;
      var req = b.dataset.act === 'move'
        ? post({ action: 'move', from_visit: g.visit_id, to_visit: +document.getElementById('mwTo' + b.dataset.g).value, media_ids: ids })
        : post({ action: 'dismiss', media_ids: ids });
      req.then(function (r) {
        if (!r || r.error || r.success === false) { msg('That did not save' + (r && r.error ? ': ' + r.error : '.')); b.disabled = false; return; }
        var refused = r.refused ? Object.keys(r.refused).length : 0;
        msg(b.dataset.act === 'move' ? ('Moved ' + (r.moved || 0) + ' photo(s)' + (refused ? '; ' + refused + ' refused (' + Object.values(r.refused)[0] + ')' : '') + '.') : 'Dismissed.');
        load();
      }).catch(function () { msg('Could not save that.'); b.disabled = false; });
    };
    var rec = document.getElementById('mwRefileRecent');
    rec.innerHTML = data.recent_moves.length ? data.recent_moves.map(function (m) {
      return '<div class="mw-refile-recent">Photo ' + m.media_id + ': visit #' + m.from_visit_id + ' → #' + m.to_visit_id + ' · ' + esc(m.moved_at) +
        ' <button class="btn btn-sm btn-outline-secondary" data-undo="' + m.id + '">Undo</button></div>';
    }).join('') : '<p class="text-muted">None.</p>';
    rec.onclick = function (e) {
      var b = e.target.closest('button[data-undo]'); if (!b) return; b.disabled = true;
      post({ action: 'undo', log_id: +b.dataset.undo }).then(function (r) { msg(r.success ? 'Put back.' : 'Could not undo (it has been moved again).'); load(); });
    };
  }
  function load() { fetch(API + '?mode=list&days=0', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(render).catch(function () { msg('Could not load the list.'); }); }
  load();
})();
</script>

<?php include 'includes/appstack_footer.php'; ?>
