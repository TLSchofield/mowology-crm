<?php
/**
 * Campaign Opportunities Dashboard
 *
 * Surfaces gaps where the campaign engine could fire but hasn't:
 *  - Completed visits with no follow-up campaign
 *  - Before/after photos not yet turned into social posts
 *  - Paid invoices with no referral ask
 *  - Marketable contacts inactive 90+ days with no recent send
 *  - Quote requests stale for 7+ days
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$db = getDB();

// ── Run all five opportunity queries ──────────────────────────────────────

function opp_query(PDO $db, string $sql): array {
    try {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[opportunities] ' . $e->getMessage());
        return [];
    }
}

$uncampaignedVisits = opp_query($db, "
    SELECT
        jv.id            AS visit_id,
        jv.completed_at,
        jp.contact_id,
        COALESCE(c.first_name, '') AS first_name,
        COALESCE(c.last_name,  '') AS last_name,
        COALESCE(c.email, '')      AS email,
        COALESCE(p.city, '')       AS city,
        COALESCE(jp.service_type, '') AS service_type
    FROM job_visits jv
    INNER JOIN job_plans jp ON jp.id = jv.plan_id
    INNER JOIN contacts  c  ON c.id  = jp.contact_id
    LEFT  JOIN properties p  ON p.id  = jp.property_id
    WHERE jv.status      = 'completed'
      AND jv.completed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      AND jp.contact_id NOT IN (
            SELECT contact_id FROM automation_logs
            WHERE trigger_type IN ('job_completed', 'invoice_paid', 'end_visit')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      )
    ORDER BY jv.completed_at DESC
    LIMIT 20
");

$unpostedPhotos = opp_query($db, "
    SELECT
        vp.id            AS photo_id,
        vp.visit_id,
        vp.photo_type,
        vp.created_at    AS uploaded_at,
        jp.contact_id,
        COALESCE(c.first_name, '') AS first_name,
        COALESCE(c.last_name,  '') AS last_name,
        COALESCE(p.city, '')       AS city,
        COALESCE(jp.service_type, '') AS service_type
    FROM visit_photos vp
    INNER JOIN job_visits jv ON jv.id = vp.visit_id
    INNER JOIN job_plans  jp ON jp.id = jv.plan_id
    INNER JOIN contacts   c  ON c.id  = jp.contact_id
    LEFT  JOIN properties p  ON p.id  = jp.property_id
    WHERE vp.photo_type IN ('before', 'after', 'during')
      AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND vp.visit_id NOT IN (
            SELECT visit_id FROM social_posts WHERE visit_id IS NOT NULL
      )
    ORDER BY vp.created_at DESC
    LIMIT 20
");

$unpromptedReferrals = opp_query($db, "
    SELECT
        i.id             AS invoice_id,
        i.invoice_number,
        i.total,
        i.paid_at,
        i.contact_id,
        COALESCE(c.first_name, '') AS first_name,
        COALESCE(c.last_name,  '') AS last_name,
        COALESCE(c.email, '')      AS email
    FROM invoices i
    INNER JOIN contacts c ON c.id = i.contact_id
    WHERE i.status  = 'paid'
      AND i.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND i.contact_id NOT IN (
            SELECT contact_id FROM referral_links
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      )
    ORDER BY i.paid_at DESC
    LIMIT 20
");

$inactiveContacts = opp_query($db, "
    SELECT
        c.id,
        c.first_name,
        c.last_name,
        c.email,
        c.last_service_date,
        DATEDIFF(NOW(), c.last_service_date) AS days_inactive
    FROM contacts c
    WHERE c.receive_marketing = 1
      AND c.is_active         = 1
      AND c.email IS NOT NULL AND c.email != ''
      AND (
            c.last_service_date IS NULL
            OR c.last_service_date < DATE_SUB(NOW(), INTERVAL 90 DAY)
      )
      AND c.id NOT IN (
            SELECT contact_id FROM campaign_sends
            WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
      )
    ORDER BY c.last_service_date ASC
    LIMIT 30
");

$staleLeads = opp_query($db, "
    SELECT
        qr.id,
        qr.created_at,
        qr.service_types,
        qr.status,
        qr.urgency,
        DATEDIFF(NOW(), qr.created_at) AS days_old,
        COALESCE(c.first_name, '') AS first_name,
        COALESCE(c.last_name,  '') AS last_name,
        COALESCE(c.email, '')      AS email
    FROM quote_requests qr
    LEFT JOIN contacts c ON c.id = qr.contact_id
    WHERE qr.status IN ('new', 'reviewing')
      AND qr.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY qr.created_at ASC
    LIMIT 20
");

$totalOpportunities = count($uncampaignedVisits)
    + count($unpostedPhotos)
    + count($unpromptedReferrals)
    + count($inactiveContacts)
    + count($staleLeads);

$pageTitle  = 'Campaign Opportunities';
$activePage = 'opportunities';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-1">Campaign Opportunities</h1>
                  <p class="text-muted mb-0 small">
                      Gaps where the automation engine can act — but hasn't yet.
                  </p>
              </div>
              <div class="d-flex gap-2 align-items-center">
                  <?php if ($totalOpportunities > 0): ?>
                  <span class="badge badge-pill" style="background:var(--mw-orange);font-size:.85rem;padding:.4em .8em;">
                      <?= $totalOpportunities ?> open
                  </span>
                  <?php endif; ?>
                  <a href="/crm/marketing/campaigns.php" class="btn btn-outline-secondary btn-sm">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                      Campaigns
                  </a>
                  <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>" class="btn btn-sm" style="background:var(--mw-green);color:#fff;">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                      Refresh
                  </a>
              </div>
          </div>

          <?php if ($totalOpportunities === 0): ?>
          <!-- All clear -->
          <div class="card border-0 shadow-sm">
              <div class="card-body text-center py-5">
                  <div class="mb-3" style="color:var(--mw-green);">
                      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  </div>
                  <h4 class="mb-1">All caught up</h4>
                  <p class="text-muted mb-0">No open opportunities right now. Check back after more jobs are completed or invoices are paid.</p>
              </div>
          </div>
          <?php else: ?>

          <!-- ── Panel 1: Uncampaigned Visit Completions ───────────────────────── -->
          <?php if (!empty($uncampaignedVisits)): ?>
          <div class="card border-0 shadow-sm mb-4">
              <div class="card-header d-flex align-items-center" style="background:var(--mw-light);border-bottom:2px solid var(--mw-green);">
                  <div class="mr-3" style="color:var(--mw-green);">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                  </div>
                  <div class="flex-grow-1">
                      <h5 class="mb-0 font-weight-bold">Uncampaigned Visits
                          <span class="badge badge-pill ml-2" style="background:var(--mw-green);font-size:.8rem;"><?= count($uncampaignedVisits) ?></span>
                      </h5>
                      <p class="text-muted mb-0 small">Jobs completed in the last 14 days with no follow-up campaign triggered.</p>
                  </div>
                  <a href="/crm/marketing/campaigns.php" class="btn btn-sm btn-outline-secondary">Create Campaign</a>
              </div>
              <div class="table-responsive">
                  <table class="table table-hover mb-0 small">
                      <thead class="thead-light">
                          <tr>
                              <th>Client</th>
                              <th>City</th>
                              <th>Service</th>
                              <th>Completed</th>
                              <th>Days ago</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($uncampaignedVisits as $v): ?>
                          <tr>
                              <td>
                                  <a href="/crm/clients_appstack.php?id=<?= (int)$v['contact_id'] ?>" class="font-weight-500">
                                      <?= htmlspecialchars(trim($v['first_name'] . ' ' . $v['last_name'])) ?: '—' ?>
                                  </a>
                                  <?php if ($v['email']): ?><br><span class="text-muted"><?= htmlspecialchars($v['email']) ?></span><?php endif; ?>
                              </td>
                              <td><?= htmlspecialchars($v['city']) ?: '—' ?></td>
                              <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $v['service_type']))) ?: '—' ?></td>
                              <td><?= $v['completed_at'] ? date('M j', strtotime($v['completed_at'])) : '—' ?></td>
                              <td>
                                  <?php $daysAgo = $v['completed_at'] ? (int)((time() - strtotime($v['completed_at'])) / 86400) : '?'; ?>
                                  <span class="badge badge-<?= $daysAgo > 7 ? 'danger' : 'warning' ?>">
                                      <?= $daysAgo ?>d
                                  </span>
                              </td>
                          </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          </div>
          <?php endif; ?>

          <!-- ── Panel 2: Unposted Photos ─────────────────────────────────────── -->
          <?php if (!empty($unpostedPhotos)): ?>
          <div class="card border-0 shadow-sm mb-4">
              <div class="card-header d-flex align-items-center" style="background:var(--mw-light);border-bottom:2px solid var(--mw-green);">
                  <div class="mr-3" style="color:var(--mw-green);">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  </div>
                  <div class="flex-grow-1">
                      <h5 class="mb-0 font-weight-bold">Unposted Before/After Photos
                          <span class="badge badge-pill ml-2" style="background:var(--mw-green);font-size:.8rem;"><?= count($unpostedPhotos) ?></span>
                      </h5>
                      <p class="text-muted mb-0 small">Photos uploaded in the last 7 days that haven't been turned into a social post.</p>
                  </div>
                  <a href="/crm/marketing/social.php" class="btn btn-sm btn-outline-secondary">Create Post</a>
              </div>
              <div class="table-responsive">
                  <table class="table table-hover mb-0 small">
                      <thead class="thead-light">
                          <tr>
                              <th>Client</th>
                              <th>City</th>
                              <th>Service</th>
                              <th>Type</th>
                              <th>Uploaded</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($unpostedPhotos as $ph): ?>
                          <tr>
                              <td>
                                  <a href="/crm/clients_appstack.php?id=<?= (int)$ph['contact_id'] ?>" class="font-weight-500">
                                      <?= htmlspecialchars(trim($ph['first_name'] . ' ' . $ph['last_name'])) ?: '—' ?>
                                  </a>
                              </td>
                              <td><?= htmlspecialchars($ph['city']) ?: '—' ?></td>
                              <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $ph['service_type']))) ?: '—' ?></td>
                              <td>
                                  <span class="badge badge-<?= $ph['photo_type'] === 'before' ? 'secondary' : ($ph['photo_type'] === 'after' ? 'success' : 'info') ?>">
                                      <?= htmlspecialchars($ph['photo_type']) ?>
                                  </span>
                              </td>
                              <td><?= $ph['uploaded_at'] ? date('M j', strtotime($ph['uploaded_at'])) : '—' ?></td>
                          </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          </div>
          <?php endif; ?>

          <!-- ── Panel 3: Unprompted Referrals ────────────────────────────────── -->
          <?php if (!empty($unpromptedReferrals)): ?>
          <div class="card border-0 shadow-sm mb-4">
              <div class="card-header d-flex align-items-center" style="background:var(--mw-light);border-bottom:2px solid var(--mw-green);">
                  <div class="mr-3" style="color:var(--mw-green);">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                  </div>
                  <div class="flex-grow-1">
                      <h5 class="mb-0 font-weight-bold">Paid — No Referral Ask
                          <span class="badge badge-pill ml-2" style="background:var(--mw-green);font-size:.8rem;"><?= count($unpromptedReferrals) ?></span>
                      </h5>
                      <p class="text-muted mb-0 small">Invoices paid in the last 30 days where no referral link was sent to the client.</p>
                  </div>
                  <a href="/crm/marketing/referrals.php" class="btn btn-sm btn-outline-secondary">Referrals</a>
              </div>
              <div class="table-responsive">
                  <table class="table table-hover mb-0 small">
                      <thead class="thead-light">
                          <tr>
                              <th>Client</th>
                              <th>Invoice</th>
                              <th>Amount</th>
                              <th>Paid</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($unpromptedReferrals as $inv): ?>
                          <tr>
                              <td>
                                  <a href="/crm/clients_appstack.php?id=<?= (int)$inv['contact_id'] ?>" class="font-weight-500">
                                      <?= htmlspecialchars(trim($inv['first_name'] . ' ' . $inv['last_name'])) ?: '—' ?>
                                  </a>
                                  <?php if ($inv['email']): ?><br><span class="text-muted"><?= htmlspecialchars($inv['email']) ?></span><?php endif; ?>
                              </td>
                              <td>
                                  <a href="/crm/invoices/view.php?id=<?= (int)$inv['invoice_id'] ?>">
                                      <?= htmlspecialchars($inv['invoice_number']) ?>
                                  </a>
                              </td>
                              <td>$<?= number_format((float)$inv['total'], 2) ?></td>
                              <td><?= $inv['paid_at'] ? date('M j', strtotime($inv['paid_at'])) : '—' ?></td>
                          </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          </div>
          <?php endif; ?>

          <!-- ── Panel 4: Inactive Marketable Contacts ─────────────────────────── -->
          <?php if (!empty($inactiveContacts)): ?>
          <div class="card border-0 shadow-sm mb-4">
              <div class="card-header d-flex align-items-center" style="background:var(--mw-light);border-bottom:2px solid var(--mw-green);">
                  <div class="mr-3" style="color:var(--mw-green);">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  </div>
                  <div class="flex-grow-1">
                      <h5 class="mb-0 font-weight-bold">Inactive Marketable Contacts
                          <span class="badge badge-pill ml-2" style="background:var(--mw-green);font-size:.8rem;"><?= count($inactiveContacts) ?></span>
                      </h5>
                      <p class="text-muted mb-0 small">Opted-in contacts with no service in 90+ days and no campaign send in the last 90 days.</p>
                  </div>
                  <a href="/crm/marketing/campaigns.php" class="btn btn-sm btn-outline-secondary">Create Campaign</a>
              </div>
              <div class="table-responsive">
                  <table class="table table-hover mb-0 small">
                      <thead class="thead-light">
                          <tr>
                              <th>Client</th>
                              <th>Email</th>
                              <th>Last Service</th>
                              <th>Inactive</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($inactiveContacts as $c): ?>
                          <tr>
                              <td>
                                  <a href="/crm/clients_appstack.php?id=<?= (int)$c['id'] ?>" class="font-weight-500">
                                      <?= htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])) ?: '—' ?>
                                  </a>
                              </td>
                              <td><?= htmlspecialchars($c['email']) ?: '—' ?></td>
                              <td><?= $c['last_service_date'] ? date('M j, Y', strtotime($c['last_service_date'])) : 'Never' ?></td>
                              <td>
                                  <?php $di = $c['days_inactive'] !== null ? (int)$c['days_inactive'] : 9999; ?>
                                  <span class="badge badge-<?= $di > 180 ? 'danger' : ($di > 120 ? 'warning' : 'secondary') ?>">
                                      <?= $di > 900 ? 'Never' : $di . 'd' ?>
                                  </span>
                              </td>
                          </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          </div>
          <?php endif; ?>

          <!-- ── Panel 5: Stale Quote Requests ─────────────────────────────────── -->
          <?php if (!empty($staleLeads)): ?>
          <div class="card border-0 shadow-sm mb-4">
              <div class="card-header d-flex align-items-center" style="background:var(--mw-light);border-bottom:2px solid var(--mw-orange);">
                  <div class="mr-3" style="color:var(--mw-orange);">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  </div>
                  <div class="flex-grow-1">
                      <h5 class="mb-0 font-weight-bold">Stale Quote Requests
                          <span class="badge badge-pill ml-2" style="background:var(--mw-orange);font-size:.8rem;"><?= count($staleLeads) ?></span>
                      </h5>
                      <p class="text-muted mb-0 small">Leads still in "new" or "reviewing" after 7+ days — take action or decline.</p>
                  </div>
                  <a href="/crm/quotes/list.php" class="btn btn-sm btn-outline-secondary">View Leads</a>
              </div>
              <div class="table-responsive">
                  <table class="table table-hover mb-0 small">
                      <thead class="thead-light">
                          <tr>
                              <th>Lead</th>
                              <th>Services</th>
                              <th>Urgency</th>
                              <th>Status</th>
                              <th>Age</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($staleLeads as $qr): ?>
                          <tr>
                              <td>
                                  <a href="/crm/quotes/list.php?lead_id=<?= (int)$qr['id'] ?>" class="font-weight-500">
                                      <?= htmlspecialchars(trim($qr['first_name'] . ' ' . $qr['last_name'])) ?: 'Unknown' ?>
                                  </a>
                                  <?php if ($qr['email']): ?><br><span class="text-muted"><?= htmlspecialchars($qr['email']) ?></span><?php endif; ?>
                              </td>
                              <td><?= htmlspecialchars($qr['service_types']) ?: '—' ?></td>
                              <td>
                                  <?php $urgencyColors = ['asap' => 'danger', 'soon' => 'warning', 'inquiring' => 'secondary']; ?>
                                  <span class="badge badge-<?= $urgencyColors[$qr['urgency']] ?? 'secondary' ?>">
                                      <?= htmlspecialchars($qr['urgency']) ?>
                                  </span>
                              </td>
                              <td>
                                  <span class="badge badge-<?= $qr['status'] === 'new' ? 'primary' : 'info' ?>">
                                      <?= htmlspecialchars($qr['status']) ?>
                                  </span>
                              </td>
                              <td>
                                  <span class="badge badge-<?= (int)$qr['days_old'] > 14 ? 'danger' : 'warning' ?>">
                                      <?= (int)$qr['days_old'] ?>d
                                  </span>
                              </td>
                          </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          </div>
          <?php endif; ?>

          <?php endif; // end $totalOpportunities > 0 ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
