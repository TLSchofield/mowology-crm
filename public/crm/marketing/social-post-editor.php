<?php
/**
 * Social Post Editor — Create and Edit social posts.
 *
 * Query params:
 *   id=N           Edit existing post
 *   template=N     Pre-fill from template
 *   scheduled=DT   Pre-set scheduled datetime
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

$editId     = (int)($_GET['id']       ?? 0);
$templateId = (int)($_GET['template'] ?? 0);
$preSchedule = htmlspecialchars($_GET['scheduled'] ?? '');

$pageTitle  = $editId ? 'Edit Post' : 'New Post';
$activePage = 'social';
$canApprove = userHasPermission('marketing.approve');

$extraHead = '
<link rel="stylesheet" href="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://unpkg.com/cropperjs@1.6.2/dist/cropper.min.js"></script>
';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0"><?php echo $editId ? 'Edit Post' : 'New Post'; ?></h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span>
                      <?php echo $editId ? 'Edit #' . $editId : 'Create'; ?>
                  </p>
              </div>
              <a href="/crm/marketing/social-calendar.php" class="btn btn-outline-secondary">
                  Back to Calendar
              </a>
          </div>

          <div class="row">
              <!-- LEFT: Editor -->
              <div class="col-lg-7">

                  <!-- Caption -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Caption</h5>
                          <div class="d-flex gap-2">
                              <!-- Template picker -->
                              <select class="form-control form-control-sm" id="templatePicker" style="width:auto;" onchange="loadTemplate(this.value)">
                                  <option value="">— Use a template —</option>
                              </select>
                              <!-- Variable inserter -->
                              <div class="dropdown">
                                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                      Insert Variable
                                  </button>
                                  <div class="dropdown-menu">
                                      <a class="dropdown-item" href="#" onclick="insertVar('{neighborhood}');return false;">{neighborhood}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{city}');return false;">{city}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{service}');return false;">{service}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{date}');return false;">{date}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{crew_name}');return false;">{crew_name}</a>
                                  </div>
                              </div>
                          </div>
                      </div>
                      <div class="card-body">
                          <div class="form-group mb-2">
                              <input type="text" class="form-control" id="postTitle" placeholder="Internal title (not published)">
                          </div>
                          <div class="position-relative">
                              <textarea class="form-control" id="postCaption" rows="8"
                                  placeholder="Write your caption here. Use {neighborhood}, {city}, {service} to personalise each post."></textarea>
                              <div class="mw-soc-char-count" id="charCount">0 / 1500 (GBP)</div>
                          </div>
                      </div>
                  </div>

                  <!-- Hashtags -->
                  <div class="card mb-3">
                      <div class="card-header">
                          <h5 class="mb-0">Hashtags</h5>
                      </div>
                      <div class="card-body">
                          <textarea class="form-control" id="postHashtags" rows="2"
                              placeholder="#VancouverLandscaping #Mowology #LawnCare"></textarea>
                          <div class="d-flex align-items-center justify-content-between mt-2">
                              <small class="text-muted" id="hashtagHint">Space-separated. Will be appended to caption when publishing.</small>
                              <div class="custom-control custom-switch ml-3" style="white-space:nowrap">
                                  <input type="checkbox" class="custom-control-input" id="hashtagsInComment">
                                  <label class="custom-control-label small" for="hashtagsInComment">
                                      Post in first comment <span class="badge badge-pill badge-secondary" style="font-size:10px">Instagram only</span>
                                  </label>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Targeting -->
                  <div class="card mb-3">
                      <div class="card-header"><h5 class="mb-0">Context &amp; Targeting</h5></div>
                      <div class="card-body">
                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Neighbourhood</label>
                                      <input type="text" class="form-control" id="postNeighborhood" placeholder="e.g., Kits, South Granville">
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>City</label>
                                      <input type="text" class="form-control" id="postCity" value="Vancouver">
                                  </div>
                              </div>
                          </div>
                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Service Type</label>
                                      <select class="form-control" id="postServiceType">
                                          <option value="">— Select service —</option>
                                          <option value="lawn_maintenance">Lawn Maintenance</option>
                                          <option value="fertilizer">Fertilization</option>
                                          <option value="aeration">Aeration</option>
                                          <option value="power_rake">Power Raking</option>
                                          <option value="hedge_trimming">Hedge Trimming</option>
                                          <option value="spring_cleanup">Spring Cleanup</option>
                                          <option value="fall_cleanup">Fall Cleanup</option>
                                          <option value="general">General Landscaping</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Call-to-Action</label>
                                      <select class="form-control" id="postCta">
                                          <option value="">— None —</option>
                                          <option value="BOOK">Book Now</option>
                                          <option value="LEARN_MORE">Learn More</option>
                                          <option value="CALL">Call Us</option>
                                          <option value="SIGN_UP">Sign Up</option>
                                      </select>
                                  </div>
                              </div>
                          </div>
                          <div class="form-group mb-0">
                              <label>CTA URL</label>
                              <input type="url" class="form-control" id="postCtaUrl"
                                  placeholder="https://mowology.ca/quote?service=lawn&src=gbp">
                              <small class="text-muted">UTM params will be appended automatically when publishing.</small>
                          </div>
                      </div>
                  </div>

                  <!-- Platforms -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1" style="vertical-align:-1px"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                              Publish To
                          </h5>
                          <span class="badge badge-pill badge-secondary" id="platformCount" style="display:none"></span>
                      </div>
                      <div class="card-body">
                          <!-- Partial-publish warning (shown when status = published/publishing with failures) -->
                          <div id="partialBanner" style="display:none"></div>
                          <div id="platformToggles" style="min-height:80px;">
                              <div class="mw-soc-loading">Loading connected accounts...</div>
                          </div>
                      </div>
                  </div>

                  <!-- Schedule -->
                  <div class="card mb-3">
                      <div class="card-header d-flex align-items-center">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--mw-green)" stroke-width="2" class="mr-2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                          <h5 class="mb-0">Schedule</h5>
                      </div>
                      <div class="card-body">
                          <div class="row">
                              <div class="col-md-7">
                                  <div class="form-group mb-0">
                                      <label class="small font-weight-bold text-muted text-uppercase" style="letter-spacing:.04em;font-size:.68rem;">Publish Date &amp; Time</label>
                                      <div class="mw-soc-schedule-wrap">
                                          <span class="mw-soc-schedule-wrap-icon">
                                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                          </span>
                                          <input type="datetime-local" class="form-control" id="postSchedule"
                                              value="<?php echo $preSchedule; ?>">
                                      </div>
                                      <div class="d-flex align-items-center justify-content-between mt-1">
                                          <small class="text-muted">Leave blank to save as a draft.</small>
                                          <button class="btn btn-sm" id="btnSuggestedTime" style="display:none;background:var(--mw-lime);color:#1a3a2a;border:none;font-size:.72rem;padding:2px 8px;" onclick="applySuggestedTime()" title="AI-suggested optimal posting time">
                                              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                              <span id="suggestedTimeLabel">Suggested</span>
                                          </button>
                                      </div>
                                      <!-- Schedule busy-day strip: 7-day week view centered on selected date -->
                                      <div class="mw-soc-pulse-strip mt-2" id="schedBusyStrip" style="display:none;"></div>
                                      <div style="font-size:.68rem;color:#6c757d;margin-top:2px;" id="schedBusyHint"></div>
                                  </div>
                              </div>
                              <div class="col-md-5 mt-3 mt-md-0">
                                  <label class="small font-weight-bold text-muted text-uppercase d-block" style="letter-spacing:.04em;font-size:.68rem;">Quick presets</label>
                                  <div class="mw-soc-time-presets">
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(9,0)">9am Today</button>
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(9,1)">9am Tomorrow</button>
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(12,0)">Noon Today</button>
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(17,0)">5pm Today</button>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>

              </div>

              <!-- RIGHT: Media + Preview -->
              <div class="col-lg-5">

                  <!-- Media Library Picker -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Attach Photos</h5>
                          <span class="text-muted small" id="mediaSelectedCount">0 of 10 selected</span>
                      </div>
                      <div class="card-body">
                          <!-- Selected thumbnails strip -->
                          <div id="selectedThumbStrip" class="d-flex flex-wrap gap-2 mb-3" style="min-height:0"></div>
                          <!-- Action buttons -->
                          <div class="d-flex gap-2">
                              <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1"
                                      onclick="openMediaPicker(function(item){ addMediaItem(item); })">
                                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                  Browse Media Library
                              </button>
                          </div>
                          <p class="text-muted x-small mt-2 mb-0">Select up to 10 photos. Use the library to upload new images too.</p>
                          <div id="selectedMediaIds" style="display:none;"></div>
                      </div>
                  </div>

                  <?php include dirname(__DIR__) . '/cms/block-forms/media-picker-modal.php'; ?>

                  <!-- Posting Card -->
                  <div class="card mb-3" id="cardSection" style="display:none">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Posting Card</h5>
                          <span class="badge badge-pill" style="background:var(--mw-lime);color:#1a3a2a" id="cardTypeBadge"></span>
                      </div>
                      <div class="card-body p-0">
                          <!-- Card preview image -->
                          <div id="cardPreviewWrap" style="background:#0D3B2E;min-height:200px;display:flex;align-items:center;justify-content:center;position:relative">
                              <img id="cardPreviewImg" src="" alt="Posting card preview"
                                   style="display:none;width:100%;height:auto;max-height:400px;object-fit:contain">
                              <div id="cardPreviewPlaceholder" style="color:#7FD858;text-align:center;padding:40px 20px">
                                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                  <p class="mt-2 mb-0 small">No card yet</p>
                              </div>
                              <div id="cardLoadingOverlay" style="display:none;position:absolute;inset:0;background:rgba(13,59,46,.8);display:none;align-items:center;justify-content:center">
                                  <div class="spinner-border text-light" role="status"></div>
                              </div>
                          </div>
                          <!-- Card type selector -->
                          <div class="p-3">
                              <div class="btn-group btn-group-sm w-100 mb-2" id="cardTypeButtons">
                                  <button class="btn btn-outline-secondary" onclick="setCardTemplate('before_after')" data-type="before_after" title="Best when you have both before + after photos">Before / After</button>
                                  <button class="btn btn-outline-secondary" onclick="setCardTemplate('hero_after')" data-type="hero_after" title="Full-bleed single photo">Hero After</button>
                              </div>
                              <div class="d-flex gap-2">
                                  <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="regenerateCard()">
                                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                      Regenerate Card
                                  </button>
                                  <a id="cardDownloadLink" href="#" download class="btn btn-sm btn-outline-secondary" style="display:none">
                                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                      Download
                                  </a>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Post Preview -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Preview</h5>
                          <div class="btn-group btn-group-sm" id="previewTabs">
                              <button class="btn btn-outline-secondary active" data-platform="gbp" onclick="switchPreview('gbp',this)">GBP</button>
                              <button class="btn btn-outline-secondary" data-platform="instagram" onclick="switchPreview('instagram',this)">Instagram</button>
                              <button class="btn btn-outline-secondary" data-platform="facebook" onclick="switchPreview('facebook',this)">Facebook</button>
                          </div>
                      </div>
                      <div class="card-body p-0">
                          <div class="mw-soc-preview" id="postPreview">
                              <div class="mw-soc-preview-placeholder">
                                  <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                  <p>Start typing to see a preview</p>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Status + Action Buttons -->
                  <div class="card mw-soc-action-card">
                      <div class="card-body">
                          <div class="mw-soc-status-row mb-3" id="currentStatusRow"></div>
                          <!-- Inline error display — sits above buttons so it's always visible -->
                          <div id="actionError" style="display:none;background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:.82rem;color:#721c24;"></div>
                          <!-- Normal edit actions (hidden for published/publishing posts) -->
                          <div id="normalActions">
                              <div class="d-grid gap-2">
                                  <?php if ($canApprove): ?>
                                  <button class="btn btn-success btn-lg" onclick="schedulePost()" id="btnSchedule">
                                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                      Approve &amp; Schedule
                                  </button>
                                  <?php endif; ?>
                                  <button class="btn btn-outline-success" onclick="savePost('pending_approval')" id="btnSubmit">
                                      Submit for Approval
                                  </button>
                                  <button class="btn btn-outline-secondary" id="btnDraft" onclick="savePost('draft')">
                                      Save Draft
                                  </button>
                                  <?php if ($editId && $canApprove): ?>
                                  <button class="btn btn-outline-danger" onclick="cancelPost()" id="btnCancel">
                                      Cancel Post
                                  </button>
                                  <?php endif; ?>
                              </div>
                          </div>

                          <!-- Retry actions (shown for published/publishing posts with failures) -->
                          <div id="retryActions" style="display:none;">
                              <?php if ($canApprove): ?>
                              <button class="btn btn-warning btn-lg btn-block w-100" onclick="retryPost()" id="btnRetry">
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                  Retry Failed Platforms
                              </button>
                              <?php else: ?>
                              <div class="alert alert-info mb-0 text-center" style="font-size:.82rem;">
                                  Some platforms failed to publish. An admin can retry.
                              </div>
                              <?php endif; ?>
                          </div>

                          <?php if ($editId && $canApprove): ?>
                          <hr style="border-color:#e9ecef;margin:14px 0 10px;">
                          <button class="btn btn-outline-secondary btn-block w-100" onclick="openCampaignModal()" id="btnCampaign" style="font-size:.8rem;">
                              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                              Create Campaign
                          </button>
                          <?php endif; ?>
                      </div>
                  </div>

              </div>
          </div>

<!-- ── Smart Campaign Modal ──────────────────────────────────────── -->
<div class="modal fade" id="campaignModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #e9ecef;padding:16px 20px;">
                <div>
                    <h5 class="modal-title mb-0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                        Create Campaign from This Post
                    </h5>
                    <p class="text-muted mb-0" style="font-size:.8rem;margin-top:3px;">Auto-fill your calendar with optimal posting times based on service type &amp; season</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" style="padding:18px 20px 16px;">

                <div class="d-flex flex-wrap align-items-start" style="gap:20px;margin-bottom:14px;">
                    <!-- Cadence -->
                    <div style="flex:1;min-width:180px;">
                        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;margin-bottom:6px;display:block;">Cadence</label>
                        <div class="mw-soc-cadence-pills" id="cadencePills">
                            <button class="mw-soc-cadence-pill active" data-cadence="seasonal" data-hint="More posts during peak season, fewer in winter — smart for most services.">🌿 Seasonal</button>
                            <button class="mw-soc-cadence-pill" data-cadence="fixed" data-days="14" data-hint="One post every 2 weeks, evenly spaced all year.">Fortnightly</button>
                            <button class="mw-soc-cadence-pill" data-cadence="fixed" data-days="7" data-hint="One post per week — high frequency, best for year-round services.">Weekly</button>
                        </div>
                        <div class="mw-soc-cadence-hint" id="campCadenceHint">More posts during peak season, fewer in winter — smart for most services.</div>
                    </div>
                    <!-- Look ahead -->
                    <div>
                        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;margin-bottom:6px;display:block;">Period</label>
                        <div class="mw-soc-cadence-pills" id="monthPills">
                            <button class="mw-soc-cadence-pill" data-months="3">3 mo</button>
                            <button class="mw-soc-cadence-pill active" data-months="6">6 mo</button>
                            <button class="mw-soc-cadence-pill" data-months="12">1 yr</button>
                        </div>
                    </div>
                </div>

                <div id="campaignPreviewWrap">
                    <div class="mw-soc-loading" style="font-size:.8rem;">Calculating optimal slots…</div>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #e9ecef;padding:12px 20px;">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btnConfirmCampaign" onclick="confirmCampaign()" disabled>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="btnConfirmCampaignLabel">Create Campaign</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Crop Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="cropModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #e9ecef;padding:14px 20px;">
                <div>
                    <h5 class="modal-title mb-0">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1" style="vertical-align:-2px"><polyline points="6 2 3 2 3 22 21 22 21 19"/><rect x="6" y="6" width="12" height="12"/></svg>
                        Crop Photo
                    </h5>
                    <p class="text-muted mb-0" style="font-size:.75rem;margin-top:2px;">Drag the crop box to choose what to keep. Use preset ratios for platform compatibility.</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" style="padding:16px 20px;">
                <!-- Cropper image container -->
                <div class="mw-crop-image-wrap">
                    <img id="cropImg" src="" alt="Crop source">
                </div>
                <!-- Aspect ratio controls -->
                <div class="mw-crop-ratio-btns" id="cropRatioBtns">
                    <button class="mw-crop-ratio-btn" data-ratio="NaN"   onclick="setCropRatio(NaN,   this)">Free</button>
                    <button class="mw-crop-ratio-btn active" data-ratio="1"     onclick="setCropRatio(1,     this)">1:1 Square</button>
                    <button class="mw-crop-ratio-btn" data-ratio="0.8"   onclick="setCropRatio(0.8,   this)">4:5 Portrait</button>
                    <button class="mw-crop-ratio-btn" data-ratio="1.778" onclick="setCropRatio(1.778, this)">16:9 Wide</button>
                    <button class="mw-crop-ratio-btn" data-ratio="1.91"  onclick="setCropRatio(1.91,  this)">1.91:1</button>
                </div>
                <!-- Live ratio status -->
                <div class="mw-crop-ratio-status mw-crop-ratio-info" id="cropRatioStatus">
                    Select a ratio or drag the crop box
                </div>
                <!-- Platform hint -->
                <div style="font-size:.72rem;color:#6c757d;margin-top:8px;">
                    <strong>Instagram</strong> requires 0.8:1–1.91:1 &nbsp;·&nbsp;
                    <strong>Facebook &amp; GBP</strong> accept any ratio &nbsp;·&nbsp;
                    <strong>1:1</strong> is universally safe
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #e9ecef;padding:10px 20px;">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btnSaveCrop" onclick="saveCrop()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Crop
                </button>
            </div>
        </div>
    </div>
</div>

          <script>
          (function() {
              'use strict';

              var csrf       = '<?php echo generateCSRFToken(); ?>';
              var editId     = <?php echo $editId; ?>;
              var templateId = <?php echo $templateId; ?>;
              var canApprove = <?php echo json_encode($canApprove); ?>;
              var selectedMedia = [];
              var selectedAccounts = []; // [{platform, account_id}]
              var previewPlatform = 'gbp';
              var inFlight = false; // double-submit guard
              var inFlightTimer = null;
              var charLimits = { gbp: 1500, instagram: 2200, facebook: 63206 };

              function setInFlight(val) {
                  inFlight = val;
                  if (inFlightTimer) { clearTimeout(inFlightTimer); inFlightTimer = null; }
                  if (val) {
                      // Safety: auto-reset after 12 seconds so a silent failure never locks the UI
                      inFlightTimer = setTimeout(function() {
                          inFlight = false;
                          setActionButtons(false);
                          showActionError('Request timed out — please try again.');
                      }, 12000);
                  }
              }

              // ── Inline error display ──────────────────────────────────
              function showActionError(msg) {
                  var el = document.getElementById('actionError');
                  if (!el) { console.error(msg); return; }
                  el.textContent = msg;
                  el.style.display = '';
                  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
              }
              function clearActionError() {
                  var el = document.getElementById('actionError');
                  if (el) { el.style.display = 'none'; el.textContent = ''; }
              }

              // Schedule busy-day strip
              var calCache = {};
              function pad(n) { return n < 10 ? '0' + n : '' + n; }

              function loadScheduleBusyStrip(dateStr) {
                  var stripEl = document.getElementById('schedBusyStrip');
                  if (!stripEl) return;

                  // Use the selected date, or today if none picked yet
                  var d        = (dateStr && dateStr.length >= 10) ? new Date(dateStr + (dateStr.length === 10 ? 'T12:00:00' : '')) : new Date();
                  var year     = d.getFullYear();
                  var month    = d.getMonth() + 1;
                  var key      = year + '-' + pad(month);
                  var selDateStr = (dateStr && dateStr.length >= 10) ? dateStr.substring(0, 10) : '';

                  function renderStrip(cal) {
                      var dayOfWeek = d.getDay(); // 0=Sun
                      var weekDays  = [];
                      for (var i = 0; i < 7; i++) {
                          var wd = new Date(d.getTime());
                          wd.setDate(d.getDate() - dayOfWeek + i);
                          weekDays.push(wd);
                      }
                      var letters = ['S','M','T','W','T','F','S'];
                      var todayStr = (function() {
                          var t = new Date();
                          return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
                      })();
                      var html = '';
                      weekDays.forEach(function(wd) {
                          var ds     = wd.getFullYear() + '-' + pad(wd.getMonth() + 1) + '-' + pad(wd.getDate());
                          var posts  = (cal[ds] || []).filter(function(p) { return p.id !== editId; });
                          var pub    = posts.filter(function(p) { return p.status === 'published'; }).length;
                          var sch    = posts.filter(function(p) { return p.status === 'scheduled' || p.status === 'approved'; }).length;
                          var cls    = pub > 0 ? 'mw-soc-pulse-dot-published'
                                     : sch > 0 ? 'mw-soc-pulse-dot-scheduled'
                                     : 'mw-soc-pulse-dot-empty';
                          var isSel  = selDateStr && (ds === selDateStr);
                          var isToday = (ds === todayStr);
                          var count  = pub + sch;
                          var dayStyle = isSel ? 'opacity:1;font-weight:700;' : (isToday ? 'opacity:.85;' : 'opacity:.55;');
                          var dotStyle = isSel ? 'box-shadow:0 0 0 2px #fff,0 0 0 3px var(--mw-green);' : '';
                          html += '<div class="mw-soc-pulse-day" style="' + dayStyle + '">'
                               +  '<span class="mw-soc-pulse-lbl">' + letters[wd.getDay()] + '</span>'
                               +  '<span class="mw-soc-pulse-dot ' + cls + '" style="' + dotStyle + '" title="' + ds + (count ? ' — ' + count + ' post' + (count > 1 ? 's' : '') : '') + '"></span>'
                               +  '<span style="font-size:.65rem;color:#6c757d;">' + (count > 0 ? count : '') + '</span>'
                               + '</div>';
                      });
                      stripEl.innerHTML = html;
                      stripEl.style.display = 'flex';
                  }

                  if (calCache[key]) {
                      renderStrip(calCache[key]);
                  } else {
                      fetch('/crm/api/social/posts.php?action=calendar&year=' + year + '&month=' + month)
                          .then(function(r) { return r.json(); })
                          .then(function(data) {
                              if (data.success) {
                                  calCache[key] = data.calendar || {};
                                  renderStrip(calCache[key]);
                              }
                          });
                  }
              }

              // Required by media-picker-modal.php
              window.csrfToken = csrf;

              // ── Init ──────────────────────────────────────────────
              loadTemplateOptions();
              loadAccounts();

              if (editId) {
                  loadPost(editId);
              } else if (templateId) {
                  loadTemplateById(templateId);
              }

              // Wire schedule input → busy-day strip + past-time warning
              var schedInput = document.getElementById('postSchedule');
              function checkScheduleTime(val) {
                  if (!schedInput) return;
                  if (val && new Date(val).getTime() <= Date.now()) {
                      schedInput.style.borderColor = '#dc3545';
                      schedInput.title = 'This time is in the past — pick a future time to schedule.';
                  } else {
                      schedInput.style.borderColor = '';
                      schedInput.title = '';
                  }
              }
              if (schedInput) {
                  schedInput.addEventListener('change', function() {
                      loadScheduleBusyStrip(this.value);
                      checkScheduleTime(this.value);
                  });
                  // Always show on load — defaults to current week if no date selected
                  loadScheduleBusyStrip(schedInput.value || '');
                  checkScheduleTime(schedInput.value || '');
              }

              // ── Load existing post ─────────────────────────────────
              function loadPost(id) {
                  fetch('/crm/api/social/posts.php?action=get&id=' + id)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) { alert('Could not load post: ' + data.error); return; }
                          var p = data.post;
                          document.getElementById('postTitle').value       = p.title || '';
                          document.getElementById('postCaption').value     = p.caption || '';
                          document.getElementById('postHashtags').value    = p.hashtags || '';
                          document.getElementById('hashtagsInComment').checked = !!parseInt(p.hashtags_in_comment || 0);
                          updateHashtagHint();
                          document.getElementById('postNeighborhood').value = p.neighborhood || '';
                          document.getElementById('postCity').value        = p.city || 'Vancouver';
                          document.getElementById('postServiceType').value = p.service_type || '';
                          document.getElementById('postCta').value         = p.cta_action || '';
                          document.getElementById('postCtaUrl').value      = p.cta_url || '';
                          if (p.scheduled_at) {
                              document.getElementById('postSchedule').value = p.scheduled_at.replace(' ', 'T').substring(0, 16);
                              loadScheduleBusyStrip(document.getElementById('postSchedule').value);
                          }

                          // Pre-select media — populate both ID list and meta for thumbnails
                          selectedMedia = [];
                          selectedMediaMeta = [];
                          (p.media || []).forEach(function(m) {
                              var url  = m.url || m.file_path || '';
                              var meta = {
                                  id:     m.media_id,
                                  url:    url,
                                  alt:    m.alt_text || '',
                                  width:  parseInt(m.image_width  || 0, 10),
                                  height: parseInt(m.image_height || 0, 10),
                              };
                              selectedMedia.push(m.media_id);
                              selectedMediaMeta.push(meta);

                              // If DB didn't have dimensions, detect from the image
                              if (!meta.width || !meta.height) {
                                  (function(mt) {
                                      var img = new Image();
                                      img.onload = function() {
                                          mt.width  = this.naturalWidth;
                                          mt.height = this.naturalHeight;
                                          updateMediaSelection();
                                      };
                                      img.src = mt.url;
                                      if (img.complete && img.naturalWidth > 0) {
                                          mt.width  = img.naturalWidth;
                                          mt.height = img.naturalHeight;
                                      }
                                  }(meta));
                              }
                          });
                          updateMediaSelection();

                          // Pre-select platforms + detect partial publish
                          selectedAccounts = (p.platforms || []).map(function(pp) {
                              return {platform: pp.platform, account_id: pp.account_id};
                          });

                          var isPublishedOrPublishing = (p.status === 'published' || p.status === 'publishing');
                          var failedPlatforms = (p.platforms || []).filter(function(pp) {
                              return pp.status !== 'published';
                          });
                          var hasFailures = isPublishedOrPublishing && failedPlatforms.length > 0;

                          // Build statusMap for platform rows (for published/publishing state)
                          var sMap = null;
                          if (isPublishedOrPublishing) {
                              sMap = {};
                              (p.platforms || []).forEach(function(pp) {
                                  sMap[pp.account_id] = { status: pp.status, fail_reason: pp.fail_reason || '' };
                              });
                          }

                          var doRenderRows = function() {
                              if (isPublishedOrPublishing) {
                                  renderPlatformRows(sMap, true); // readonly + status chips
                              } else {
                                  renderPlatformRows(null, false);
                              }
                          };

                          if (allAccounts.length) {
                              doRenderRows();
                          } else {
                              // accounts not loaded yet — defer
                              deferredPlatformRender = doRenderRows;
                          }

                          // Show partial-publish banner
                          var banner = document.getElementById('partialBanner');
                          if (banner) {
                              if (hasFailures) {
                                  var failedNames = failedPlatforms.map(function(pp) { return pp.platform; }).join(', ');
                                  banner.innerHTML = '<div class="mw-soc-partial-banner">'
                                      + '<div class="mw-soc-partial-banner-icon">&#9888;</div>'
                                      + '<div class="mw-soc-partial-banner-body">'
                                      + '<div class="mw-soc-partial-banner-title">Partial publish — some platforms failed</div>'
                                      + '<div class="mw-soc-partial-banner-msg">This post published to some platforms successfully. Failed: <strong>' + esc(failedNames) + '</strong>. Use the retry button to re-queue the failed platforms.</div>'
                                      + '</div>'
                                      + '</div>';
                                  banner.style.display = '';
                              } else {
                                  banner.style.display = 'none';
                              }
                          }

                          // Switch action buttons: retry vs normal
                          var normalEl = document.getElementById('normalActions');
                          var retryEl  = document.getElementById('retryActions');
                          if (isPublishedOrPublishing) {
                              if (normalEl) normalEl.style.display = 'none';
                              if (hasFailures && retryEl) retryEl.style.display = '';
                          }

                          // Show current status
                          var statusLabels = {
                              draft: 'Draft', pending_approval: 'Awaiting Approval',
                              approved: 'Approved', scheduled: 'Scheduled',
                              published: 'Published', publishing: 'Publishing…', failed: 'Failed', cancelled: 'Cancelled'
                          };
                          var statusRow = document.getElementById('currentStatusRow');
                          if (p.status) {
                              statusRow.innerHTML = '<span class="text-muted small">Status:</span> '
                                  + '<span class="mw-soc-badge mw-soc-badge-' + p.status + '">' + esc(statusLabels[p.status] || p.status) + '</span>';
                              if (p.last_fail_reason) {
                                  statusRow.innerHTML += '<div class="text-danger small mt-1" style="font-size:.72rem;">'
                                      + '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                                      + esc(p.last_fail_reason) + '</div>';
                              }
                          }

                          updateCharCount();
                          updatePreview();

                          // Show card section if post has a card or is auto-generated
                          if (p.card_derivative_id || p.auto_generated) {
                              loadCardPreview(id, p.card_template_type || 'hero_after');
                          }

                          // Show suggested time button
                          if (p.best_time_suggestion) {
                              var btn = document.getElementById('btnSuggestedTime');
                              var lbl = document.getElementById('suggestedTimeLabel');
                              if (btn && lbl) {
                                  lbl.textContent = p.best_time_suggestion;
                                  btn.style.display = '';
                                  btn.dataset.suggestion = p.best_time_suggestion;
                              }
                          }
                      });
              }

              // ── Card preview functions ─────────────────────────────

              var currentCardPostId = null;

              function loadCardPreview(postId, templateType) {
                  currentCardPostId = postId;
                  var section = document.getElementById('cardSection');
                  if (section) section.style.display = '';

                  // Update type badge
                  var badge = document.getElementById('cardTypeBadge');
                  var typeLabels = { before_after: 'Before / After', hero_after: 'Hero After', multi_grid: 'Multi Grid' };
                  if (badge) badge.textContent = typeLabels[templateType] || templateType;

                  // Highlight active button
                  document.querySelectorAll('#cardTypeButtons button').forEach(function(btn) {
                      btn.classList.toggle('active', btn.dataset.type === templateType);
                      btn.classList.toggle('btn-primary', btn.dataset.type === templateType);
                      btn.classList.toggle('btn-outline-secondary', btn.dataset.type !== templateType);
                  });

                  var img     = document.getElementById('cardPreviewImg');
                  var wrap    = document.getElementById('cardPreviewWrap');
                  var overlay = document.getElementById('cardLoadingOverlay');
                  var placeholder = document.getElementById('cardPreviewPlaceholder');
                  if (overlay) { overlay.style.display = 'flex'; }

                  fetch('/crm/api/social/card.php?action=preview&post_id=' + postId)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (overlay) overlay.style.display = 'none';
                          if (data.success && data.file_path) {
                              if (img) {
                                  img.src = data.file_path + '?t=' + Date.now(); // cache bust
                                  img.style.display = '';
                              }
                              if (placeholder) placeholder.style.display = 'none';
                              var dlLink = document.getElementById('cardDownloadLink');
                              if (dlLink) { dlLink.href = data.file_path; dlLink.style.display = ''; }
                          } else {
                              if (placeholder) placeholder.style.display = '';
                          }
                      })
                      .catch(function() {
                          if (overlay) overlay.style.display = 'none';
                      });
              }

              window.regenerateCard = function() {
                  if (!currentCardPostId) return;
                  var activeBtn = document.querySelector('#cardTypeButtons button.active');
                  var templateType = activeBtn ? activeBtn.dataset.type : 'hero_after';
                  var overlay = document.getElementById('cardLoadingOverlay');
                  if (overlay) overlay.style.display = 'flex';

                  fetch('/crm/api/social/card.php', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ action: 'generate', post_id: currentCardPostId, template_type: templateType, csrf_token: csrf })
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (overlay) overlay.style.display = 'none';
                      if (data.success) {
                          loadCardPreview(currentCardPostId, templateType);
                      } else {
                          alert('Card generation failed: ' + (data.error || 'Unknown error'));
                      }
                  })
                  .catch(function(e) {
                      if (overlay) overlay.style.display = 'none';
                      alert('Card generation request failed.');
                  });
              };

              window.setCardTemplate = function(templateType) {
                  if (!currentCardPostId) {
                      // No post loaded yet — just update button state
                      document.querySelectorAll('#cardTypeButtons button').forEach(function(btn) {
                          btn.classList.toggle('active', btn.dataset.type === templateType);
                          btn.classList.toggle('btn-primary', btn.dataset.type === templateType);
                          btn.classList.toggle('btn-outline-secondary', btn.dataset.type !== templateType);
                      });
                      return;
                  }
                  var overlay = document.getElementById('cardLoadingOverlay');
                  if (overlay) overlay.style.display = 'flex';

                  fetch('/crm/api/social/card.php', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ action: 'set-template', post_id: currentCardPostId, template_type: templateType, csrf_token: csrf })
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (overlay) overlay.style.display = 'none';
                      if (data.success) {
                          loadCardPreview(currentCardPostId, templateType);
                      } else {
                          alert('Failed to change template: ' + (data.error || 'Unknown error'));
                      }
                  })
                  .catch(function() {
                      if (overlay) overlay.style.display = 'none';
                  });
              };

              window.applySuggestedTime = function() {
                  var btn = document.getElementById('btnSuggestedTime');
                  if (!btn || !btn.dataset.suggestion) return;
                  // Parse "Tue 10:00 AM" → next occurrence of that day+time
                  var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                  var suggestion = btn.dataset.suggestion; // e.g. "Tue 10:00 AM"
                  var parts = suggestion.split(' ');
                  if (parts.length < 3) return;
                  var targetDay = dayNames.indexOf(parts[0]);
                  var timeParts = parts[1].split(':');
                  var ampm = parts[2];
                  var hours = parseInt(timeParts[0], 10);
                  if (ampm === 'PM' && hours < 12) hours += 12;
                  if (ampm === 'AM' && hours === 12) hours = 0;
                  var minutes = parseInt(timeParts[1] || '0', 10);

                  var now = new Date();
                  var d = new Date(now);
                  d.setHours(hours, minutes, 0, 0);
                  // Advance to next target day
                  var daysUntil = (targetDay - d.getDay() + 7) % 7;
                  if (daysUntil === 0 && d <= now) daysUntil = 7; // same day but past time
                  d.setDate(d.getDate() + daysUntil);

                  // Format as datetime-local value (YYYY-MM-DDTHH:MM)
                  var yyyy = d.getFullYear();
                  var mm   = String(d.getMonth() + 1).padStart(2, '0');
                  var dd   = String(d.getDate()).padStart(2, '0');
                  var hh   = String(d.getHours()).padStart(2, '0');
                  var mi   = String(d.getMinutes()).padStart(2, '0');
                  var suggestedVal = yyyy + '-' + mm + '-' + dd + 'T' + hh + ':' + mi;
                  document.getElementById('postSchedule').value = suggestedVal;
                  loadScheduleBusyStrip(suggestedVal);
              };

              // ── Load template options ──────────────────────────────
              function loadTemplateOptions() {
                  fetch('/crm/api/social/accounts.php?action=templates')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          var sel = document.getElementById('templatePicker');
                          (data.templates || []).forEach(function(t) {
                              var opt = document.createElement('option');
                              opt.value = t.id;
                              opt.textContent = t.name + ' (' + (t.category || '') + ')';
                              opt.dataset.caption  = t.caption_template;
                              opt.dataset.hashtags = t.hashtag_preset || '';
                              opt.dataset.cta      = t.cta_preset || '';
                              sel.appendChild(opt);
                          });
                          // Pre-select if templateId passed
                          if (templateId) {
                              sel.value = templateId;
                              loadTemplateById(templateId, data.templates);
                          }
                      });
              }

              function loadTemplateById(id, templates) {
                  if (templates) {
                      var t = templates.find(function(x) { return x.id == id; });
                      if (t) applyTemplate(t);
                  } else {
                      fetch('/crm/api/social/accounts.php?action=templates')
                          .then(function(r) { return r.json(); })
                          .then(function(data) {
                              var t = (data.templates || []).find(function(x) { return x.id == id; });
                              if (t) applyTemplate(t);
                          });
                  }
              }

              window.loadTemplate = function(id) {
                  if (!id) return;
                  var sel = document.getElementById('templatePicker');
                  var opt = sel.querySelector('option[value="' + id + '"]');
                  if (opt) {
                      document.getElementById('postCaption').value  = opt.dataset.caption || '';
                      document.getElementById('postHashtags').value = opt.dataset.hashtags || '';
                      document.getElementById('postCta').value      = opt.dataset.cta || '';
                  }
                  updateCharCount();
                  updatePreview();
              };

              function applyTemplate(t) {
                  document.getElementById('postCaption').value   = t.caption_template || '';
                  document.getElementById('postHashtags').value  = t.hashtag_preset || '';
                  document.getElementById('postCta').value       = t.cta_preset || '';
                  document.getElementById('postCtaUrl').value    = t.cta_url_preset || '';
                  updateCharCount();
                  updatePreview();
              }

              // ── Load connected accounts ────────────────────────────
              var allAccounts = []; // cache for use by renderPlatformRows
              // Deferred platform render state (set by loadPost if accounts not yet loaded)
              var deferredPlatformRender = null; // fn to call once allAccounts is populated

              function loadAccounts() {
                  fetch('/crm/api/social/accounts.php?action=list')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var container = document.getElementById('platformToggles');
                          if (!data.success || !data.accounts.length) {
                              container.innerHTML = '<div class="alert alert-warning mb-0">'
                                  + 'No platforms connected. <a href="/crm/marketing/social-accounts.php">Connect an account →</a>'
                                  + '</div>';
                              return;
                          }
                          allAccounts = data.accounts;
                          // If loadPost already ran and left a deferred render, call it now
                          if (deferredPlatformRender) {
                              deferredPlatformRender();
                              deferredPlatformRender = null;
                          } else {
                              renderPlatformRows(null, false); // initial render without status overlay
                          }
                      });
              }

              /**
               * Render platform rows.
               * statusMap: optional {account_id: {status, fail_reason}} from loaded post platforms
               * readonly: if true, hide checkboxes and show status chips only
               */
              function renderPlatformRows(statusMap, readonly) {
                  var container = document.getElementById('platformToggles');
                  if (!allAccounts.length) return;

                  var platformNames = {
                      gbp:       'Google Business Profile',
                      facebook:  'Facebook Page',
                      instagram: 'Instagram Business',
                      linkedin:  'LinkedIn'
                  };

                  var platformIconsSvg = {
                      gbp:       '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                      facebook:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',
                      instagram: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
                      linkedin:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>',
                  };

                  var statusLabels = {
                      published:  'Published',
                      failed:     'Failed',
                      pending:    'Pending',
                      processing: 'Processing',
                  };
                  var statusCls = {
                      published:  'mw-soc-pub-chip-published',
                      failed:     'mw-soc-pub-chip-failed',
                      pending:    'mw-soc-pub-chip-pending',
                      processing: 'mw-soc-pub-chip-processing',
                  };

                  var html = '';
                  allAccounts.forEach(function(a) {
                      var checked  = selectedAccounts.some(function(x) { return x.account_id == a.id; });
                      var platName = platformNames[a.platform] || a.platform;
                      var dispName = esc(a.location_name_display || a.account_name);
                      var iconSvg  = platformIconsSvg[a.platform] || '';
                      var rowCls   = 'mw-soc-platform-row' + (readonly ? ' mw-row-readonly' : '') + (checked && !readonly ? ' mw-row-selected' : '');

                      var badgeHtml = '';
                      if (statusMap && statusMap[a.id]) {
                          var st   = statusMap[a.id].status || 'pending';
                          var fr   = statusMap[a.id].fail_reason || '';
                          var chip = '<span class="mw-soc-pub-chip ' + (statusCls[st] || 'mw-soc-pub-chip-pending') + '">'
                                   + (st === 'published' ? '&#10003; ' : st === 'failed' ? '&#10007; ' : '&#9679; ')
                                   + esc(statusLabels[st] || st) + '</span>';
                          var failNote = '';
                          if (fr && st === 'failed') {
                              var shortFr = fr.length > 60 ? fr.substring(0, 60) + '…' : fr;
                              failNote = '<div style="font-size:.7rem;color:#721c24;margin-top:4px;" title="' + esc(fr) + '">' + esc(shortFr) + '</div>';
                          }
                          badgeHtml = '<div class="mw-soc-platform-row-badge">' + chip + failNote + '</div>';
                      } else if (!readonly) {
                          // stopPropagation prevents the row's onclick from double-toggling the checkbox:
                          // browser toggles checked state BEFORE onclick fires, so without stopProp the
                          // row handler would flip it back to its original value.
                          badgeHtml = '<input type="checkbox" class="mw-soc-platform-chk"'
                                    + ' data-platform="' + esc(a.platform) + '" data-account="' + a.id + '"'
                                    + (checked ? ' checked' : '')
                                    + ' onclick="event.stopPropagation()"'
                                    + ' onchange="onPlatformChkChange(this,this.closest(\'.mw-soc-platform-row\'))">';
                      }

                      html += '<div class="' + rowCls + '"'
                            + (!readonly ? ' onclick="togglePlatformRow(this,' + a.id + ',\'' + esc(a.platform) + '\')"' : '')
                            + '>';
                      html += '<div class="mw-soc-platform-row-icon mw-soc-platform-row-icon-' + esc(a.platform) + '">' + iconSvg + '</div>';
                      html += '<div class="mw-soc-platform-row-info">';
                      html += '  <div class="mw-soc-platform-row-name">' + dispName + '</div>';
                      html += '  <div class="mw-soc-platform-row-sub">' + esc(platName) + '</div>';
                      html += '</div>';
                      html += badgeHtml;
                      html += '</div>';
                  });
                  container.innerHTML = html;

                  // Update platform count badge in card header
                  var countBadge = document.getElementById('platformCount');
                  if (countBadge) {
                      if (!readonly) {
                          var selCount = selectedAccounts.length;
                          if (selCount > 0) {
                              countBadge.textContent = selCount + ' selected';
                              countBadge.style.display = '';
                          } else {
                              countBadge.style.display = 'none';
                          }
                      } else {
                          countBadge.style.display = 'none';
                      }
                  }
              }

              window.togglePlatformRow = function(row, accountId, platform) {
                  // Don't toggle if readonly (published post view)
                  if (row.classList.contains('mw-row-readonly')) return;
                  var chk = row.querySelector('.mw-soc-platform-chk');
                  if (!chk) return;
                  // Checkbox has onclick="stopPropagation()" so this handler only fires
                  // when clicking the row background (not the checkbox itself). Safe to toggle.
                  chk.checked = !chk.checked;
                  onPlatformChkChange(chk, row);
              };

              window.onPlatformChkChange = function(chk, row) {
                  if (row) {
                      row.classList.toggle('mw-row-selected', chk.checked);
                  }
                  updateSelectedAccounts();
              };

              window.updateSelectedAccounts = function() {
                  selectedAccounts = [];
                  document.querySelectorAll('.mw-soc-platform-chk:checked').forEach(function(chk) {
                      selectedAccounts.push({
                          platform:   chk.dataset.platform,
                          account_id: parseInt(chk.dataset.account),
                      });
                  });
                  // Update count badge
                  var countBadge = document.getElementById('platformCount');
                  if (countBadge) {
                      var n = selectedAccounts.length;
                      if (n > 0) {
                          countBadge.textContent = n + ' selected';
                          countBadge.style.display = '';
                      } else {
                          countBadge.style.display = 'none';
                      }
                  }
                  // Refresh thumbnails — Instagram warning badge may need to appear/disappear
                  updateMediaSelection();
              };

              // ── Media library selection ────────────────────────────
              // selectedMediaMeta stores {id, url, alt, width, height} for thumbnail display
              var selectedMediaMeta = [];

              // Instagram aspect ratio bounds
              var IG_RATIO_MIN = 0.8;
              var IG_RATIO_MAX = 1.91;

              function isInstagramSelected() {
                  return selectedAccounts.some(function(a) { return a.platform === 'instagram'; });
              }

              function ratioOkForInstagram(w, h) {
                  if (!w || !h) return true; // unknown — don't warn
                  var r = w / h;
                  return r >= IG_RATIO_MIN && r <= IG_RATIO_MAX;
              }

              window.addMediaItem = function(item) {
                  var id = parseInt(item.id);
                  if (!id) return;
                  if (selectedMedia.indexOf(id) !== -1) return; // already selected
                  if (selectedMedia.length >= 10) { alert('Maximum 10 photos per post'); return; }
                  var meta = { id: id, url: item.file_path, alt: item.alt_text || '', width: 0, height: 0 };
                  selectedMedia.push(id);
                  selectedMediaMeta.push(meta);
                  updateMediaSelection();
                  updatePreview();

                  // Detect natural dimensions — handle both cached and uncached images
                  var img = new Image();
                  img.onload = function() {
                      meta.width  = this.naturalWidth;
                      meta.height = this.naturalHeight;
                      updateMediaSelection();
                  };
                  img.src = item.file_path;
                  // If already cached, onload won't fire — read dimensions immediately
                  if (img.complete && img.naturalWidth > 0) {
                      meta.width  = img.naturalWidth;
                      meta.height = img.naturalHeight;
                      updateMediaSelection();
                  }
              };

              window.removeMediaItem = function(id) {
                  var idx = selectedMedia.indexOf(id);
                  if (idx !== -1) selectedMedia.splice(idx, 1);
                  selectedMediaMeta = selectedMediaMeta.filter(function(m) { return m.id !== id; });
                  updateMediaSelection();
                  updatePreview();
              };

              function updateMediaSelection() {
                  document.getElementById('mediaSelectedCount').textContent = selectedMedia.length + ' of 10 selected';
                  var strip = document.getElementById('selectedThumbStrip');
                  if (!strip) return;
                  if (!selectedMediaMeta.length) { strip.innerHTML = ''; return; }

                  var igSelected = isInstagramSelected();
                  strip.innerHTML = selectedMediaMeta.map(function(m) {
                      var needsWarn = igSelected && !ratioOkForInstagram(m.width, m.height);
                      var ratioTip  = (m.width && m.height) ? (m.width + '×' + m.height + ' — ratio ' + (m.width / m.height).toFixed(2) + ':1') : 'Loading dimensions…';
                      var warn = needsWarn
                          ? '<span class="mw-soc-thumb-warn" title="Aspect ratio (' + esc(ratioTip) + ') is outside Instagram\'s 0.8:1–1.91:1 range. Click ✂ to crop.">!</span>'
                          : '';
                      return '<div class="mw-soc-thumb-wrap">'
                          + '<img src="' + esc(m.url) + '" alt="' + esc(m.alt) + '" class="mw-soc-thumb-img">'
                          + warn
                          + '<button type="button" class="mw-soc-thumb-remove" onclick="removeMediaItem(' + m.id + ')" title="Remove">×</button>'
                          + '<button type="button" class="mw-soc-thumb-crop" onclick="openCropModal(' + m.id + ')" title="' + esc(ratioTip) + '">✂ Crop</button>'
                          + '</div>';
                  }).join('');
              }

              // ── Crop tool ─────────────────────────────────────────
              var cropperInstance = null;
              var cropSourceMediaId = null; // the original media_id we're cropping from

              window.openCropModal = function(mediaId) {
                  var meta = selectedMediaMeta.find(function(m) { return m.id === mediaId; });
                  if (!meta) return;
                  cropSourceMediaId = mediaId;

                  // Destroy any existing Cropper instance first
                  if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }

                  var imgEl    = document.getElementById('cropImg');
                  var statusEl = document.getElementById('cropRatioStatus');

                  statusEl.className   = 'mw-crop-ratio-status mw-crop-ratio-info';
                  statusEl.textContent = 'Loading image…';

                  // Reset ratio buttons to 1:1
                  document.querySelectorAll('.mw-crop-ratio-btn').forEach(function(b) {
                      b.classList.toggle('active', b.dataset.ratio === '1');
                  });

                  // Two conditions must BOTH be true before Cropper can init:
                  //   imgLoaded  — image has natural dimensions
                  //   modalShown — Bootstrap animation finished (container has real pixel size)
                  var imgLoaded  = false;
                  var modalShown = false;
                  var initDone   = false;

                  function tryInit() {
                      if (!imgLoaded || !modalShown || initDone) return;
                      initDone = true;
                      if (cropperInstance) { cropperInstance.destroy(); }
                      cropperInstance = new Cropper(imgEl, {
                          aspectRatio:  1,       // 1:1 default — safe for all platforms
                          viewMode:     1,
                          dragMode:     'move',
                          autoCropArea: 0.85,
                          responsive:   true,
                          guides:       true,
                          center:       true,
                          background:   false,
                          crop: function(e) {
                              updateCropStatus(e.detail.width, e.detail.height);
                          },
                      });
                  }

                  // Attach onload BEFORE setting src — avoids the race where src loads
                  // synchronously from cache before the handler is registered
                  imgEl.onload = function() {
                      imgLoaded = true;
                      tryInit();
                  };

                  // Cache-bust so the browser makes a real request and fires onload
                  // (prevents the case where a cached image fires onload before the handler above)
                  imgEl.src = '';
                  imgEl.src = meta.url + (meta.url.indexOf('?') === -1 ? '?' : '&') + '_cb=' + Date.now();

                  // Fallback: if browser marks it complete synchronously (e.g. memory cache)
                  if (imgEl.complete && imgEl.naturalWidth > 0) {
                      imgLoaded = true;
                  }

                  // Show modal; init Cropper only after animation ends (container has real size)
                  $('#cropModal').one('shown.bs.modal', function() {
                      modalShown = true;
                      tryInit();
                  });
                  $('#cropModal').modal('show');
              };

              function updateCropStatus(w, h) {
                  var el = document.getElementById('cropRatioStatus');
                  if (!w || !h) { el.className = 'mw-crop-ratio-status mw-crop-ratio-info'; el.textContent = 'Adjust the crop box'; return; }
                  var ratio = w / h;
                  var igOk  = ratio >= IG_RATIO_MIN && ratio <= IG_RATIO_MAX;
                  var rStr  = ratio.toFixed(2) + ':1';
                  if (igOk) {
                      el.className = 'mw-crop-ratio-status mw-crop-ratio-ok';
                      el.innerHTML = '&#10003; ' + rStr + ' — safe for all platforms including Instagram';
                  } else {
                      el.className = 'mw-crop-ratio-status mw-crop-ratio-warn';
                      el.innerHTML = '&#10007; ' + rStr + ' — outside Instagram\'s 0.8:1–1.91:1 range';
                  }
              }

              window.setCropRatio = function(ratio, btn) {
                  if (!cropperInstance) return;
                  document.querySelectorAll('.mw-crop-ratio-btn').forEach(function(b) { b.classList.remove('active'); });
                  if (btn) btn.classList.add('active');
                  cropperInstance.setAspectRatio(isNaN(ratio) ? NaN : ratio);
              };

              window.saveCrop = function() {
                  if (!cropperInstance) return;
                  var btn = document.getElementById('btnSaveCrop');
                  var origHtml = btn.innerHTML;
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Saving…';

                  // Get cropped canvas — max 2000px on either side to keep filesize reasonable
                  var canvas = cropperInstance.getCroppedCanvas({ maxWidth: 2000, maxHeight: 2000, imageSmoothingQuality: 'high' });
                  canvas.toBlob(function(blob) {
                      if (!blob) {
                          btn.disabled = false; btn.innerHTML = origHtml;
                          alert('Could not generate crop — try a different browser.');
                          return;
                      }

                      var fd = new FormData();
                      fd.append('crop_image', blob, 'crop.jpg');
                      fd.append('original_media_id', cropSourceMediaId || '');
                      fd.append('csrf_token', csrf);

                      fetch('/crm/api/social/crop.php', { method: 'POST', body: fd })
                          .then(function(r) { return r.json(); })
                          .then(function(data) {
                              btn.disabled = false; btn.innerHTML = origHtml;
                              if (!data.success) { alert('Crop save failed: ' + (data.error || 'Unknown error')); return; }

                              // Replace the original in selectedMedia + selectedMediaMeta
                              var origIdx = selectedMedia.indexOf(cropSourceMediaId);
                              if (origIdx !== -1) {
                                  selectedMedia[origIdx] = data.media_id;
                              }
                              var metaIdx = selectedMediaMeta.findIndex(function(m) { return m.id === cropSourceMediaId; });
                              if (metaIdx !== -1) {
                                  selectedMediaMeta[metaIdx] = {
                                      id:     data.media_id,
                                      url:    data.url,
                                      alt:    selectedMediaMeta[metaIdx].alt || '',
                                      width:  data.width,
                                      height: data.height,
                                  };
                              }

                              updateMediaSelection();
                              updatePreview();
                              $('#cropModal').modal('hide');
                          })
                          .catch(function() {
                              btn.disabled = false; btn.innerHTML = origHtml;
                              alert('Network error — crop could not be saved.');
                          });
                  }, 'image/jpeg', 0.92);
              };

              // Destroy Cropper when modal closes to free memory
              $('#cropModal').on('hidden.bs.modal', function() {
                  if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
              });

              // ── Preview ────────────────────────────────────────────
              var platformIcons = {
                  gbp:       '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                  facebook:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',
                  instagram: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
              };

              window.switchPreview = function(platform, btn) {
                  previewPlatform = platform;
                  document.querySelectorAll('#previewTabs .btn').forEach(function(b) { b.classList.remove('active'); });
                  btn.classList.add('active');
                  updatePreview();
              };

              function updatePreview() {
                  var caption  = document.getElementById('postCaption').value;
                  var hashtags = document.getElementById('postHashtags').value;
                  var cta      = document.getElementById('postCta').value;
                  var preview  = document.getElementById('postPreview');

                  if (!caption.trim()) {
                      preview.innerHTML = '<div class="mw-soc-preview-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg><p>Start typing to see a preview</p></div>';
                      return;
                  }

                  var fullText = caption + (hashtags ? '\n\n' + hashtags : '');

                  // Build first image preview
                  var imgHtml = '';
                  var firstMedia = document.querySelector('.mw-soc-media-item.selected img');
                  if (firstMedia) {
                      imgHtml = '<div class="mw-soc-preview-image"><img src="' + esc(firstMedia.src) + '" alt=""></div>';
                  }

                  var ctaLabels = {BOOK: 'Book Now', LEARN_MORE: 'Learn More', CALL: 'Call Us', SIGN_UP: 'Sign Up'};
                  var ctaHtml = cta ? '<div class="mw-soc-preview-cta">' + esc(ctaLabels[cta] || cta) + '</div>' : '';

                  if (previewPlatform === 'gbp') {
                      preview.innerHTML = '<div class="mw-soc-prev-gbp">'
                          + '<div class="mw-soc-prev-gbp-header"><span class="mw-soc-prev-biz">Mowology</span><span class="text-muted small">Google Business</span></div>'
                          + imgHtml
                          + '<div class="mw-soc-prev-body">' + nl2br(esc(fullText)) + '</div>'
                          + ctaHtml
                          + '</div>';
                  } else if (previewPlatform === 'instagram') {
                      preview.innerHTML = '<div class="mw-soc-prev-ig">'
                          + '<div class="mw-soc-prev-ig-header">'
                          + platformIcons.instagram
                          + ' <strong>mowology</strong></div>'
                          + imgHtml
                          + '<div class="mw-soc-prev-ig-likes">♥ 0 likes</div>'
                          + '<div class="mw-soc-prev-body"><strong>mowology</strong> ' + nl2br(esc(fullText)) + '</div>'
                          + '</div>';
                  } else {
                      preview.innerHTML = '<div class="mw-soc-prev-fb">'
                          + '<div class="mw-soc-prev-fb-header"><strong>Mowology Landscaping</strong>'
                          + '<div class="text-muted small">Just now · ' + platformIcons.facebook + '</div></div>'
                          + '<div class="mw-soc-prev-body">' + nl2br(esc(fullText)) + '</div>'
                          + imgHtml
                          + ctaHtml
                          + '</div>';
                  }
              }

              // ── Char counter ───────────────────────────────────────
              function updateCharCount() {
                  var len    = (document.getElementById('postCaption').value || '').length;
                  var limit  = charLimits[previewPlatform] || 1500;
                  var el     = document.getElementById('charCount');
                  var label  = { gbp: 'GBP', instagram: 'Instagram', facebook: 'Facebook' }[previewPlatform] || previewPlatform;
                  el.textContent = len + ' / ' + limit + ' (' + label + ')';
                  el.style.color = len > limit ? '#dc3545' : '';
              }

              document.getElementById('postCaption').addEventListener('input', function() {
                  updateCharCount();
                  updatePreview();
              });
              document.getElementById('postHashtags').addEventListener('input', updatePreview);

              function updateHashtagHint() {
                  var inComment = document.getElementById('hashtagsInComment').checked;
                  document.getElementById('hashtagHint').textContent = inComment
                      ? 'Hashtags will be posted as the first comment on Instagram (caption stays clean).'
                      : 'Space-separated. Will be appended to caption when publishing.';
              }
              document.getElementById('hashtagsInComment').addEventListener('change', function() {
                  updateHashtagHint();
                  updatePreview();
              });

              // ── Schedule presets ───────────────────────────────────
              window.setPreset = function(hour, daysAhead) {
                  var d = new Date();
                  d.setDate(d.getDate() + daysAhead);
                  d.setHours(hour, 0, 0, 0);
                  var pad = function(n) { return n < 10 ? '0' + n : n; };
                  var val = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                          + 'T' + pad(hour) + ':00';
                  document.getElementById('postSchedule').value = val;
                  loadScheduleBusyStrip(val);
              };

              // ── Insert variable ────────────────────────────────────
              window.insertVar = function(v) {
                  var ta = document.getElementById('postCaption');
                  var start = ta.selectionStart;
                  ta.value = ta.value.substring(0, start) + v + ta.value.substring(ta.selectionEnd);
                  ta.focus();
                  ta.selectionStart = ta.selectionEnd = start + v.length;
                  updateCharCount();
                  updatePreview();
              };

              // ── Save post ──────────────────────────────────────────
              function buildPayload(status) {
                  return {
                      csrf_token:        csrf,
                      id:                editId,
                      title:             document.getElementById('postTitle').value,
                      caption:           document.getElementById('postCaption').value,
                      hashtags:              document.getElementById('postHashtags').value,
                      hashtags_in_comment:   document.getElementById('hashtagsInComment').checked ? 1 : 0,
                      neighborhood:      document.getElementById('postNeighborhood').value,
                      city:              document.getElementById('postCity').value,
                      service_type:      document.getElementById('postServiceType').value,
                      cta_action:        document.getElementById('postCta').value,
                      cta_url:           document.getElementById('postCtaUrl').value,
                      scheduled_at:      document.getElementById('postSchedule').value || null,
                      status:            status,
                      media_ids:         selectedMedia,
                      platform_accounts: selectedAccounts,
                  };
              }

              function setActionButtons(disabled, label) {
                  var ids = ['btnSchedule', 'btnSubmit', 'btnDraft'];
                  ids.forEach(function(id) {
                      var el = document.getElementById(id);
                      if (!el) return;
                      el.disabled = disabled;
                      if (id === 'btnSchedule' && label) {
                          el.setAttribute('data-orig', el.getAttribute('data-orig') || el.textContent.trim());
                          el.innerHTML = disabled
                              ? '<span class="spinner-border spinner-border-sm mr-1"></span>' + label
                              : el.getAttribute('data-orig') || el.textContent.trim();
                      }
                  });
              }

              window.savePost = function(status) {
                  if (inFlight) return;
                  clearActionError();
                  var payload = buildPayload(status);
                  if (!payload.caption.trim()) { showActionError('Caption is required'); return; }
                  // Drafts don't need a destination — only submissions/scheduled posts do
                  if (status !== 'draft' && !selectedAccounts.length) { showActionError('Select at least one platform to publish to'); return; }

                  setInFlight(true);
                  setActionButtons(true, 'Saving…');

                  fetch('/crm/api/social/posts.php?action=save', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(payload)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          var msg = status === 'pending_approval' ? 'Submitted for approval!' : 'Draft saved!';
                          window.location.href = '/crm/marketing/social.php?msg=' + encodeURIComponent(msg);
                      } else {
                          setInFlight(false);
                          setActionButtons(false);
                          showActionError('Save error: ' + (data.error || 'Unknown error'));
                      }
                  }).catch(function() {
                      setInFlight(false);
                      setActionButtons(false);
                      showActionError('Network error — please try again.');
                  });
              };

              window.schedulePost = function() {
                  if (inFlight) return;
                  clearActionError();
                  var scheduledAt = document.getElementById('postSchedule').value;
                  if (!scheduledAt) { showActionError('Set a date and time first'); return; }

                  // Client-side future check — catch stale times before the round-trip
                  if (new Date(scheduledAt).getTime() <= Date.now() + 60000) {
                      showActionError('Scheduled time must be at least 1 minute in the future. Pick a later time.');
                      return;
                  }

                  var payload = buildPayload('approved');
                  if (!payload.caption.trim()) { showActionError('Caption is required'); return; }
                  if (!selectedAccounts.length) { showActionError('Select at least one platform'); return; }

                  setInFlight(true);
                  setActionButtons(true, 'Scheduling…');

                  // First save, then schedule
                  fetch('/crm/api/social/posts.php?action=save', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(payload)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (!data.success) {
                          setInFlight(false);
                          setActionButtons(false);
                          showActionError('Save error: ' + (data.error || 'Unknown error'));
                          return;
                      }

                      var pid = data.id;
                      return fetch('/crm/api/social/posts.php?action=schedule', {
                          method: 'POST',
                          headers: {'Content-Type': 'application/json'},
                          body: JSON.stringify({id: pid, scheduled_at: scheduledAt, csrf_token: csrf})
                      }).then(function(r) { return r.json(); }).then(function(sData) {
                          if (sData.success) {
                              window.location.href = '/crm/marketing/social.php?msg=' + encodeURIComponent('Post scheduled!');
                          } else {
                              setInFlight(false);
                              setActionButtons(false);
                              showActionError('Schedule error: ' + (sData.error || 'Unknown error'));
                          }
                      });
                  }).catch(function() {
                      setInFlight(false);
                      setActionButtons(false);
                      showActionError('Network error — please try again.');
                  });
              };

              window.cancelPost = function() {
                  if (!confirm('Cancel this post? It will no longer publish.')) return;
                  fetch('/crm/api/social/posts.php?action=delete', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: editId, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) window.location.href = '/crm/marketing/social.php';
                  });
              };

              window.retryPost = function() {
                  if (inFlight) return;
                  var btn = document.getElementById('btnRetry');
                  if (!btn) return;

                  setInFlight(true);
                  var origHtml = btn.innerHTML;
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Queueing retry…';

                  fetch('/crm/api/social/posts.php?action=retry', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: editId, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      setInFlight(false);
                      if (data.success) {
                          window.location.href = '/crm/marketing/social.php?msg='
                              + encodeURIComponent('Retry queued — failed platforms will publish within a few minutes.');
                      } else {
                          btn.disabled = false;
                          btn.innerHTML = origHtml;
                          showActionError('Retry failed: ' + (data.error || 'Unknown error'));
                      }
                  }).catch(function() {
                      setInFlight(false);
                      btn.disabled = false;
                      btn.innerHTML = origHtml;
                      showActionError('Network error — please try again.');
                  });
              };

              // ── Campaign modal ─────────────────────────────────────
              var campaignCadence     = 'seasonal';
              var campaignCadenceDays = 14;
              var campaignMonths      = 6;
              var campaignSlots       = [];

              window.openCampaignModal = function() {
                  $('#campaignModal').modal('show');
                  loadCampaignPreview();
              };

              function setCadencePill(btn) {
                  document.querySelectorAll('#cadencePills .mw-soc-cadence-pill').forEach(function(b) { b.classList.remove('active'); });
                  btn.classList.add('active');
                  campaignCadence     = btn.getAttribute('data-cadence');
                  campaignCadenceDays = parseInt(btn.getAttribute('data-days') || '14', 10);
                  var hint = document.getElementById('campCadenceHint');
                  if (hint) { hint.textContent = btn.getAttribute('data-hint') || ''; }
                  loadCampaignPreview();
              }
              function setMonthPill(btn) {
                  document.querySelectorAll('#monthPills .mw-soc-cadence-pill').forEach(function(b) { b.classList.remove('active'); });
                  btn.classList.add('active');
                  campaignMonths = parseInt(btn.getAttribute('data-months') || '6', 10);
                  loadCampaignPreview();
              }

              document.querySelectorAll('#cadencePills .mw-soc-cadence-pill').forEach(function(b) {
                  b.addEventListener('click', function() { setCadencePill(this); });
              });
              document.querySelectorAll('#monthPills .mw-soc-cadence-pill').forEach(function(b) {
                  b.addEventListener('click', function() { setMonthPill(this); });
              });

              function loadCampaignPreview() {
                  var wrap    = document.getElementById('campaignPreviewWrap');
                  var btnConf = document.getElementById('btnConfirmCampaign');
                  wrap.innerHTML = '<div class="mw-soc-loading">Calculating optimal slots…</div>';
                  btnConf.disabled = true;

                  var url = '/crm/api/social/schedules.php?action=preview'
                          + '&post_id=' + editId
                          + '&cadence=' + campaignCadence
                          + '&cadence_days=' + campaignCadenceDays
                          + '&months=' + campaignMonths;

                  fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                      if (!data.success) {
                          wrap.innerHTML = '<div class="text-danger p-3">' + esc(data.error || 'Error loading preview') + '</div>';
                          return;
                      }
                      campaignSlots = data.slots || [];
                      renderCampaignPreview(data);
                      document.getElementById('btnConfirmCampaignLabel').textContent = 'Create Campaign (' + campaignSlots.length + ' posts)';
                      btnConf.disabled = campaignSlots.length === 0;
                  }).catch(function() {
                      wrap.innerHTML = '<div class="text-danger p-3">Network error loading preview.</div>';
                  });
              }

              var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
              var shortMo    = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
              var dowNames   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

              function renderCampaignPreview(data) {
                  var wrap = document.getElementById('campaignPreviewWrap');
                  if (!data.slots || !data.slots.length) {
                      wrap.innerHTML = '<div class="text-muted py-3 text-center" style="font-size:.82rem;">No available slots found — try a shorter period or different cadence.</div>';
                      return;
                  }

                  // Group by YYYY-MM
                  var byMonth  = {};
                  var moOrder  = [];
                  data.slots.forEach(function(s) {
                      var ym = s.scheduled_at.substring(0, 7);
                      if (!byMonth[ym]) { byMonth[ym] = []; moOrder.push(ym); }
                      byMonth[ym].push(s);
                  });

                  var html = '<div style="font-size:.75rem;color:#6c757d;margin-bottom:10px;">'
                           + '📅 <strong style="color:#212529;">' + data.total + ' posts</strong> across '
                           + moOrder.length + ' months'
                           + (data.service_type ? ' &nbsp;·&nbsp; ' + esc(data.service_type) : '')
                           + ' &nbsp;·&nbsp; <span style="color:#adb5bd;">slots avoid existing posts</span>'
                           + '</div>';

                  html += '<div class="mw-soc-campaign-preview">';
                  moOrder.forEach(function(ym) {
                      var mo    = parseInt(ym.substring(5, 7), 10);
                      var yr    = ym.substring(0, 4);
                      var slots = byMonth[ym];
                      html += '<div class="mw-soc-camp-month">';
                      html += '<div class="mw-soc-camp-month-hd">'
                            + shortMo[mo - 1] + ' ' + yr
                            + ' <span class="mw-soc-camp-month-badge">' + slots.length + '</span>'
                            + '</div>';
                      html += '<div class="mw-soc-camp-slots">';
                      slots.forEach(function(s) {
                          var dt   = new Date(s.scheduled_at.replace(' ', 'T'));
                          var dow  = dowNames[dt.getDay()];
                          var hr   = dt.getHours();
                          var ampm = hr >= 12 ? 'pm' : 'am';
                          var h12  = hr % 12 === 0 ? 12 : hr % 12;
                          html += '<span class="mw-soc-camp-chip">'
                                + '<span class="mw-soc-camp-chip-dow">' + dow + '</span>'
                                + ' ' + dt.getDate()
                                + ' <span class="mw-soc-camp-chip-time">· ' + h12 + ampm + '</span>'
                                + '</span>';
                      });
                      html += '</div></div>';
                  });
                  html += '</div>';
                  wrap.innerHTML = html;
              }

              window.confirmCampaign = function() {
                  var btn = document.getElementById('btnConfirmCampaign');
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Creating…';

                  fetch('/crm/api/social/schedules.php?action=create', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({
                          post_id:      editId,
                          cadence:      campaignCadence,
                          cadence_days: campaignCadenceDays,
                          months:       campaignMonths,
                          csrf_token:   csrf,
                      })
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          $('#campaignModal').modal('hide');
                          window.location.href = '/crm/marketing/social.php?msg='
                              + encodeURIComponent('Campaign created! ' + data.posts_created + ' posts scheduled.');
                      } else {
                          btn.disabled = false;
                          btn.innerHTML = 'Create Campaign';
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  }).catch(function() {
                      btn.disabled = false;
                      btn.innerHTML = 'Create Campaign';
                      alert('Network error — please try again.');
                  });
              };

              // ── Helpers ────────────────────────────────────────────
              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }
              function nl2br(str) {
                  return str.replace(/\n/g, '<br>');
              }

              // Initial char count
              updateCharCount();
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
