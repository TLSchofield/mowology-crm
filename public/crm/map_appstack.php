<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Territory Map', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// Get properties with jobs/quotes for the map
$properties = [];
$quoteRequests = [];

try {
    $properties = $db->query("
    SELECT DISTINCT
        p.id, p.address, p.city, p.province, p.latitude, p.longitude,
        COUNT(DISTINCT jv.id) as active_jobs,
        COUNT(DISTINCT q.id) as active_quotes
    FROM properties p
    LEFT JOIN job_plans jp ON p.id = jp.property_id AND jp.status = 'active'
    LEFT JOIN job_visits jv ON jp.id = jv.plan_id AND jv.status IN ('scheduled', 'in_progress')
    LEFT JOIN quotes q ON p.id = q.property_id AND q.status IN ('sent', 'accepted')
    WHERE (jv.id IS NOT NULL OR q.id IS NOT NULL) AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
    GROUP BY p.id
    ORDER BY COALESCE(active_jobs, 0) + COALESCE(active_quotes, 0) DESC
    LIMIT 50
")->fetchAll();

    // Get quote requests with property info (may not have coordinates)
    $quoteRequests = $db->query("
        SELECT
            qr.id, qr.urgency, qr.status, qr.created_at,
            c.first_name, c.last_name,
            p.id as property_id, p.address, p.city, p.latitude, p.longitude,
            GROUP_CONCAT(DISTINCT qr.service_types) as services
        FROM quote_requests qr
        LEFT JOIN contacts c ON qr.contact_id = c.id
        LEFT JOIN properties p ON qr.property_id = p.id
        WHERE qr.status IN ('new', 'reviewing')
        GROUP BY qr.id
        ORDER BY
            CASE qr.urgency WHEN 'asap' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END,
            qr.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load map data. Please refresh the page.');
    $properties = [];
    $quoteRequests = [];
}

$pageTitle = 'Territory Map';
$activePage = 'map';
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- Session Alert Display -->
          <?php if (isset($_SESSION['alert'])):
              $alert = $_SESSION['alert'];
              $alertClass = [
                  'error' => 'alert-danger',
                  'warning' => 'alert-warning',
                  'success' => 'alert-success',
                  'info' => 'alert-info'
              ][$alert['type']] ?? 'alert-info';
          ?>
              <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                  <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['alert']); ?>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Territory Map</h1>
            <div class="btn-group" role="group">
              <button type="button" class="btn btn-sm btn-outline-secondary active" onclick="toggleLayer('jobs')">
                <i data-feather="briefcase" style="width: 16px; height: 16px; display: inline;"></i> Jobs
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary active" onclick="toggleLayer('quotes')">
                <i data-feather="file-text" style="width: 16px; height: 16px; display: inline;"></i> Quotes
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary active" onclick="toggleLayer('requests')">
                <i data-feather="inbox" style="width: 16px; height: 16px; display: inline;"></i> Requests
              </button>
            </div>
          </div>

          <div class="card">
            <div class="card-body p-0" style="position: relative; height: 600px; overflow: hidden;">
              <div id="mapContainer" style="width: 100%; height: 100%; background: #f5f7fa;"></div>
              <!-- Map Legend -->
              <div class="mw-map-legend">
                <div class="mw-legend-title">Legend</div>
                <div class="mw-legend-item">
                  <span class="mw-legend-pin mw-pin-job"></span>
                  <span>Active Job</span>
                </div>
                <div class="mw-legend-item">
                  <span class="mw-legend-pin mw-pin-quote"></span>
                  <span>Active Quote</span>
                </div>
                <div class="mw-legend-item">
                  <span class="mw-legend-pin mw-pin-request-asap"></span>
                  <span>Request - ASAP</span>
                </div>
                <div class="mw-legend-item">
                  <span class="mw-legend-pin mw-pin-request-soon"></span>
                  <span>Request - Soon</span>
                </div>
                <div class="mw-legend-item">
                  <span class="mw-legend-pin mw-pin-request-inquiring"></span>
                  <span>Request - Inquiring</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Properties List -->
          <div class="row mt-4">
            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">
                    <i data-feather="map-pin" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>
                    Active Properties
                    <span class="badge badge-primary ml-2"><?php echo count($properties); ?></span>
                  </h5>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                  <?php if (empty($properties)): ?>
                    <div class="text-center text-muted py-4">
                      <p>No properties with active jobs or quotes</p>
                    </div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($properties as $prop): ?>
                        <a href="#" class="list-group-item list-group-item-action" onclick="focusProperty(<?php echo (int)$prop['id']; ?>); return false;">
                          <div class="d-flex justify-content-between align-items-start">
                            <div>
                              <h6 class="mb-1"><?php echo h($prop['address']); ?></h6>
                              <small class="text-muted"><?php echo h($prop['city'] . ', ' . $prop['province']); ?></small>
                            </div>
                            <div class="text-right">
                              <?php if ($prop['active_jobs'] > 0): ?>
                                <span class="badge badge-success" title="Active jobs">
                                  <i data-feather="briefcase" style="width: 12px; height: 12px; display: inline;"></i> <?php echo $prop['active_jobs']; ?>
                                </span>
                              <?php endif; ?>
                              <?php if ($prop['active_quotes'] > 0): ?>
                                <span class="badge badge-info" title="Active quotes">
                                  <i data-feather="file-text" style="width: 12px; height: 12px; display: inline;"></i> <?php echo $prop['active_quotes']; ?>
                                </span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">
                    <i data-feather="inbox" style="width: 16px; height: 16px; display: inline; margin-right: 6px;"></i>
                    Pending Quote Requests
                    <span class="badge badge-primary ml-2"><?php echo count($quoteRequests); ?></span>
                  </h5>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                  <?php if (empty($quoteRequests)): ?>
                    <div class="text-center text-muted py-4">
                      <p>No pending quote requests</p>
                    </div>
                  <?php else: ?>
                    <div class="list-group list-group-flush">
                      <?php foreach ($quoteRequests as $qr): ?>
                        <?php
                          $qrName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? ''));
                          if (empty($qrName)) $qrName = 'Unknown Contact';
                          $urgencyClass = 'mw-urgency-' . ($qr['urgency'] ?? 'inquiring');
                          $urgencyLabel = ucfirst($qr['urgency'] ?? 'inquiring');
                        ?>
                        <a href="products/quote-requests.php?id=<?php echo (int)$qr['id']; ?>" class="list-group-item list-group-item-action">
                          <div class="d-flex justify-content-between align-items-start">
                            <div>
                              <h6 class="mb-1"><?php echo h($qrName); ?></h6>
                              <small class="text-muted">
                                <?php if ($qr['address']): ?>
                                  <?php echo h($qr['address']); ?><?php if ($qr['city']): ?>, <?php echo h($qr['city']); ?><?php endif; ?>
                                <?php else: ?>
                                  No address specified
                                <?php endif; ?>
                              </small>
                            </div>
                            <span class="mw-urgency-badge <?php echo $urgencyClass; ?>">
                              <?php echo $urgencyLabel; ?>
                            </span>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <script>
            // Map data (passed from PHP)
            const propertiesData = <?php echo json_encode($properties); ?>;
            const quoteRequestsData = <?php echo json_encode($quoteRequests); ?>;

            // Layer visibility
            const layerVisibility = {
              jobs: true,
              quotes: true,
              requests: true
            };

            // Map instance and markers
            let gmap = null;
            const markers = {
              properties: [],
              requests: []
            };

            // Initialize Google Map
            function initMap() {
              const mapContainer = document.getElementById('mapContainer');

              // Default center (Vancouver)
              let mapCenter = { lat: 49.2827, lng: -123.1207 };
              let bounds = new google.maps.LatLngBounds();
              let hasCoordinates = false;

              // Calculate bounds from all data with coordinates
              propertiesData.forEach(prop => {
                if (prop.latitude && prop.longitude) {
                  bounds.extend({ lat: parseFloat(prop.latitude), lng: parseFloat(prop.longitude) });
                  hasCoordinates = true;
                }
              });

              quoteRequestsData.forEach(qr => {
                if (qr.latitude && qr.longitude) {
                  bounds.extend({ lat: parseFloat(qr.latitude), lng: parseFloat(qr.longitude) });
                  hasCoordinates = true;
                }
              });

              // Initialize map
              gmap = new google.maps.Map(mapContainer, {
                zoom: 15,
                center: mapCenter,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                styles: [
                  {
                    elementType: 'geometry',
                    stylers: [{ color: '#f5f5f5' }]
                  },
                  {
                    elementType: 'labels.text.stroke',
                    stylers: [{ color: '#ffffff' }]
                  },
                  {
                    elementType: 'labels.text.fill',
                    stylers: [{ color: '#616161' }]
                  }
                ]
              });

              // Fit bounds if we have coordinates
              if (hasCoordinates) {
                setTimeout(() => {
                  gmap.fitBounds(bounds);
                  gmap.panToBounds(bounds);
                }, 100);
              }

              // Render markers
              renderPropertyMarkers();
              renderRequestMarkers();
            }

            function renderPropertyMarkers() {
              // Clear existing property markers
              markers.properties.forEach(m => m.setMap(null));
              markers.properties = [];

              if (!layerVisibility.jobs && !layerVisibility.quotes) return;

              propertiesData.forEach(prop => {
                if (!prop.latitude || !prop.longitude) return;

                const position = {
                  lat: parseFloat(prop.latitude),
                  lng: parseFloat(prop.longitude)
                };

                // Create custom icon
                const icon = {
                  path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                  fillColor: '#2D8659',
                  fillOpacity: 0.9,
                  scale: 1.5,
                  strokeColor: '#fff',
                  strokeWeight: 2,
                  anchor: new google.maps.Point(12, 24)
                };

                const marker = new google.maps.Marker({
                  position: position,
                  map: gmap,
                  title: prop.address,
                  icon: icon,
                  data: prop,
                  type: 'property'
                });

                // Info window
                const infoContent = `
                  <div style="padding: 10px; min-width: 200px;">
                    <h6 style="margin: 0 0 8px 0; color: #2D8659;">${escapeHtml(prop.address)}</h6>
                    <p style="margin: 0 0 6px 0; font-size: 12px; color: #666;">
                      ${escapeHtml(prop.city)}, ${escapeHtml(prop.province)} ${prop.postal_code || ''}
                    </p>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee; font-size: 12px;">
                      ${prop.active_jobs > 0 ? `<p style="margin: 4px 0; color: #059669;"><strong>Jobs:</strong> ${prop.active_jobs}</p>` : ''}
                      ${prop.active_quotes > 0 ? `<p style="margin: 4px 0; color: #3B82F6;"><strong>Quotes:</strong> ${prop.active_quotes}</p>` : ''}
                    </div>
                  </div>
                `;

                const infoWindow = new google.maps.InfoWindow({
                  content: infoContent
                });

                marker.addListener('click', () => {
                  // Close all info windows
                  markers.properties.forEach(m => {
                    if (m.infoWindow) m.infoWindow.close();
                  });
                  markers.requests.forEach(m => {
                    if (m.infoWindow) m.infoWindow.close();
                  });

                  infoWindow.open(gmap, marker);
                });

                marker.infoWindow = infoWindow;
                markers.properties.push(marker);
              });
            }

            function renderRequestMarkers() {
              // Clear existing request markers
              markers.requests.forEach(m => m.setMap(null));
              markers.requests = [];

              if (!layerVisibility.requests) return;

              quoteRequestsData.forEach(qr => {
                if (!qr.latitude || !qr.longitude) return; // Only show if geocoded

                const position = {
                  lat: parseFloat(qr.latitude),
                  lng: parseFloat(qr.longitude)
                };

                // Color by urgency
                let color = '#3B82F6'; // blue default
                if (qr.urgency === 'asap') color = '#e85d04'; // orange
                else if (qr.urgency === 'soon') color = '#F59E0B'; // amber

                const icon = {
                  path: 'M12 0C7.58 0 4 3.58 4 8c0 5.25 8 16 8 16s8-10.75 8-16c0-4.42-3.58-8-8-8zm0 11c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3z',
                  fillColor: color,
                  fillOpacity: 0.85,
                  scale: 1.2,
                  strokeColor: '#fff',
                  strokeWeight: 2,
                  anchor: new google.maps.Point(12, 24)
                };

                const marker = new google.maps.Marker({
                  position: position,
                  map: gmap,
                  title: (qr.first_name || '') + ' ' + (qr.last_name || ''),
                  icon: icon,
                  data: qr,
                  type: 'request'
                });

                // Info window
                const contactName = ((qr.first_name || '') + ' ' + (qr.last_name || '')).trim() || 'Unknown';
                const infoContent = `
                  <div style="padding: 10px; min-width: 220px;">
                    <h6 style="margin: 0 0 8px 0; color: ${color};">${escapeHtml(contactName)}</h6>
                    <p style="margin: 0 0 4px 0; font-size: 11px;">
                      <span class="mw-urgency-badge" style="display: inline-block; padding: 3px 8px; border-radius: 3px; background: ${color}; color: white; font-size: 10px; font-weight: bold;">
                        ${qr.urgency.toUpperCase() || 'INQUIRING'}
                      </span>
                    </p>
                    <p style="margin: 4px 0; font-size: 12px; color: #666;">
                      ${escapeHtml(qr.address || 'No address')}${qr.city ? ', ' + escapeHtml(qr.city) : ''}
                    </p>
                    <p style="margin: 6px 0 0 0; font-size: 11px; color: #888;">
                      Services: ${escapeHtml((qr.services || '').substring(0, 50))}${(qr.services || '').length > 50 ? '...' : ''}
                    </p>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">
                      <a href="/crm/products/quote-requests.php?id=${qr.id}" style="color: ${color}; text-decoration: none; font-size: 12px; font-weight: bold;">
                        View Details &rarr;
                      </a>
                    </div>
                  </div>
                `;

                const infoWindow = new google.maps.InfoWindow({
                  content: infoContent
                });

                marker.addListener('click', () => {
                  // Close all info windows
                  markers.properties.forEach(m => {
                    if (m.infoWindow) m.infoWindow.close();
                  });
                  markers.requests.forEach(m => {
                    if (m.infoWindow) m.infoWindow.close();
                  });

                  infoWindow.open(gmap, marker);
                });

                marker.addListener('dblclick', () => {
                  window.location.href = '/crm/products/quote-requests.php?id=' + qr.id;
                });

                marker.infoWindow = infoWindow;
                markers.requests.push(marker);
              });
            }

            function toggleLayer(layer) {
              layerVisibility[layer] = !layerVisibility[layer];
              const btn = event.target.closest('button');
              btn.classList.toggle('active');

              // Re-render markers based on visibility
              if (layer === 'jobs' || layer === 'quotes') {
                renderPropertyMarkers();
              } else if (layer === 'requests') {
                renderRequestMarkers();
              }
            }

            function focusProperty(propertyId) {
              const prop = propertiesData.find(p => p.id === propertyId);
              if (prop && prop.latitude && prop.longitude) {
                const position = { lat: parseFloat(prop.latitude), lng: parseFloat(prop.longitude) };
                gmap.setCenter(position);
                gmap.setZoom(18);

                // Find and click the marker
                const marker = markers.properties.find(m => m.data.id === propertyId);
                if (marker) google.maps.event.trigger(marker, 'click');
              }
            }

            function escapeHtml(text) {
              const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
              };
              return text.replace(/[&<>"']/g, m => map[m]);
            }

            // Initialize on page load - wait for Google Maps API to be ready
            document.addEventListener('DOMContentLoaded', () => {
              // Add Google Maps script if not already loaded
              if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=drawing,geometry';
                script.onload = initMap;
                document.head.appendChild(script);
              } else {
                initMap();
              }
            });
          </script>

<?php include 'includes/appstack_footer.php'; ?>
