<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
// config.php no longer needed — GOOGLE_MAPS_API_KEY is defined in secrets.php via auth chain

// Load MeasurementService for shared save logic
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
if (defined('APP_ROOT')) {
    require_once APP_ROOT . '/Services/MeasurementService.php';
}

requireLogin();
$user = getCurrentUser();
requirePermission('products.view');
$db = getDB();

// Check if property_id is passed
$propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : null;
$property = null;
$contact = null;
$existingMeasurements = [];

if ($propertyId) {
    // Load property data
    $stmt = $db->prepare("
        SELECT p.*,
               CONCAT(c.first_name, ' ', c.last_name) as contact_name,
               c.phone as contact_phone,
               c.email as contact_email
        FROM properties p
        LEFT JOIN contacts c ON p.site_contact_id = c.id
        WHERE p.id = ?
    ");
    $stmt->execute([$propertyId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    // Load existing measurements for this property
    if ($property) {
        $stmt = $db->prepare("
            SELECT m.*, u.full_name as measured_by_name
            FROM property_measurements m
            LEFT JOIN users u ON m.measured_by = u.id
            WHERE m.property_id = ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$propertyId]);
        $existingMeasurements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle AJAX save request (using shared MeasurementService)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'save_measurements') {
        $propId = (int)($_POST['property_id'] ?? 0);
        $measurements = json_decode($_POST['measurements'] ?? '[]', true);

        if (!$propId || empty($measurements)) {
            echo json_encode(['success' => false, 'error' => 'Missing property ID or measurements']);
            exit;
        }

        if (function_exists('saveMeasurementsForProperty')) {
            $result = saveMeasurementsForProperty($propId, $measurements, $user['id']);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'error' => 'MeasurementService not available']);
        }
        exit;
    }
}

// Load measurement groups for dynamic dropdowns
$measurementGroups = [];
try {
    $measurementGroups = $db->query("
        SELECT id, group_key, group_label, measurement_types, unit, sort_order
        FROM measurement_groups
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table may not exist yet — fallback handled in JS
}

// Build flat list of measurement types from groups
$areaTypeOptions = [];
foreach ($measurementGroups as $g) {
    $types = array_filter(array_map('trim', explode(',', $g['measurement_types'])));
    foreach ($types as $t) {
        $areaTypeOptions[] = ['value' => $t, 'label' => ucfirst(str_replace('_', ' ', $t)), 'group' => $g['group_label']];
    }
}

$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';

$pageTitle = 'Area Measurement';
$activePage = 'products';
$extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey) . '&libraries=drawing,geometry&callback=initMap" async defer></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="index.php" class="mw-back-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
              Back to Products & Services
          </a>

          <h1 class="h3 mb-3">Area Measurement Tool</h1>

          <?php if ($property): ?>
          <div class="card mb-3">
              <div class="card-body py-2">
                  <div class="d-flex align-items-center justify-content-between">
                      <div>
                          <strong><?php echo htmlspecialchars($property['address']); ?></strong>
                          <span class="text-muted ml-2"><?php echo htmlspecialchars($property['city'] . ', ' . ($property['province'] ?? 'BC')); ?></span>
                          <?php if ($property['contact_name']): ?>
                              <span class="text-muted ml-2">| Contact: <?php echo htmlspecialchars(trim($property['contact_name'])); ?></span>
                          <?php endif; ?>
                      </div>
                  </div>
                  <input type="hidden" id="propertyId" value="<?php echo $propertyId; ?>">
              </div>
          </div>
          <?php endif; ?>

          <div class="row">
              <!-- Map Column -->
              <div class="col-lg-8 mb-3">
                  <div class="card">
                      <div class="card-header d-flex align-items-center justify-content-between py-2">
                          <h5 class="card-title mb-0">Map</h5>
                          <div class="d-flex align-items-center">
                              <label class="mb-0 mr-2 small font-weight-bold">Map Type:</label>
                              <select id="mapTypeSelector" class="form-control form-control-sm" style="width: auto;">
                                  <option value="roadmap">Roadmap</option>
                                  <option value="satellite" selected>Satellite</option>
                                  <option value="hybrid">Hybrid</option>
                                  <option value="terrain">Terrain</option>
                              </select>
                          </div>
                      </div>
                      <div class="card-body p-0">
                          <div id="map" class="mw-measure-map-container" style="height: 500px;"></div>
                      </div>
                  </div>
              </div>

              <!-- Sidebar Tools Column -->
              <div class="col-lg-4">
                  <!-- Address Search -->
                  <div class="card mb-3">
                      <div class="card-header py-2">
                          <h5 class="card-title mb-0">Property Location</h5>
                      </div>
                      <div class="card-body">
                          <div class="form-group mb-2">
                              <label class="small font-weight-bold">Enter Address</label>
                              <input type="text" id="addressInput" class="form-control" placeholder="123 Main St, Vancouver, BC"
                                     value="<?php echo $property ? htmlspecialchars($property['address'] . ', ' . $property['city'] . ', ' . ($property['province'] ?? 'BC')) : ''; ?>">
                          </div>
                          <button class="btn btn-primary btn-block" onclick="searchAddress()">Find Location</button>
                          <?php if ($property && $property['latitude'] && $property['longitude']): ?>
                              <input type="hidden" id="propertyLat" value="<?php echo $property['latitude']; ?>">
                              <input type="hidden" id="propertyLng" value="<?php echo $property['longitude']; ?>">
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Drawing Tools -->
                  <div class="card mb-3">
                      <div class="card-header py-2">
                          <h5 class="card-title mb-0">Drawing Tools</h5>
                      </div>
                      <div class="card-body">
                          <div class="alert alert-info py-2 small mb-2">
                              Click on the map to draw the area outline. Double-click to finish.
                          </div>
                          <div class="mw-measure-tools">
                              <button class="btn btn-outline-secondary btn-sm" id="drawPolygonBtn" onclick="startDrawing('polygon')">Draw Area</button>
                              <button class="btn btn-outline-secondary btn-sm" id="drawRectangleBtn" onclick="startDrawing('rectangle')">Rectangle</button>
                              <button class="btn btn-outline-secondary btn-sm" id="drawPolylineBtn" onclick="startDrawing('polyline')">Draw Line</button>
                              <button class="btn btn-outline-secondary btn-sm" onclick="clearCurrentDrawing()">Clear Drawing</button>
                              <button class="btn btn-outline-danger btn-sm" onclick="clearAllAreas()">Clear All</button>
                          </div>
                      </div>
                  </div>

                  <!-- Current Measurement -->
                  <div class="card mb-3" id="currentMeasurement" style="display: none;">
                      <div class="card-header py-2">
                          <h5 class="card-title mb-0">Current Measurement</h5>
                      </div>
                      <div class="card-body">
                          <div class="mw-measurement-display mb-3">
                              <div class="mw-measurement-row">
                                  <span class="mw-measurement-label">Area (sq ft)</span>
                                  <span class="mw-measurement-value mw-measurement-large" id="currentSqFt">0</span>
                              </div>
                              <div class="mw-measurement-row">
                                  <span class="mw-measurement-label">Area (sq m)</span>
                                  <span class="mw-measurement-value" id="currentSqM">0</span>
                              </div>
                              <div class="mw-measurement-row">
                                  <span class="mw-measurement-label">Acres</span>
                                  <span class="mw-measurement-value" id="currentAcres">0</span>
                              </div>
                              <div class="mw-measurement-row">
                                  <span class="mw-measurement-label">Perimeter</span>
                                  <span class="mw-measurement-value" id="currentPerimeter">0 ft</span>
                              </div>
                          </div>

                          <div class="form-group mb-2">
                              <label class="small font-weight-bold">Area Name/Label</label>
                              <input type="text" id="areaName" class="form-control" list="areaNameSuggestions" placeholder="e.g., Front Lawn, Backyard">
                              <datalist id="areaNameSuggestions">
                                  <option value="Front Lawn">
                                  <option value="Back Lawn">
                                  <option value="Side Left">
                                  <option value="Side Right">
                                  <option value="Boulevard Front">
                                  <option value="Boulevard Side">
                                  <option value="Lane">
                                  <option value="Garden Bed Front">
                                  <option value="Garden Bed Back">
                                  <option value="Driveway">
                                  <option value="Walkway">
                                  <option value="Patio">
                              </datalist>
                          </div>

                          <div class="form-group mb-3">
                              <div class="d-flex justify-content-between align-items-center">
                                  <label class="small font-weight-bold mb-0">Area Type</label>
                                  <button type="button" class="btn btn-link btn-sm p-0" onclick="openGroupManager()" title="Manage area groups" style="font-size: 0.75rem;">
                                      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                      Manage Groups
                                  </button>
                              </div>
                              <select id="areaType" class="form-control mt-1">
                                  <?php if (!empty($areaTypeOptions)): ?>
                                      <?php foreach ($areaTypeOptions as $opt): ?>
                                          <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                      <?php endforeach; ?>
                                  <?php else: ?>
                                      <option value="lawn">Lawn</option>
                                      <option value="garden">Garden</option>
                                      <option value="driveway">Driveway</option>
                                      <option value="walkway">Walkway</option>
                                      <option value="patio">Patio</option>
                                      <option value="parking">Parking</option>
                                      <option value="hedge">Hedge</option>
                                      <option value="other">Other</option>
                                  <?php endif; ?>
                              </select>
                          </div>

                          <button class="btn btn-primary btn-block" onclick="saveArea()">Save This Area</button>
                      </div>
                  </div>

                  <!-- Saved Areas -->
                  <div class="card mb-3">
                      <div class="card-header py-2">
                          <h5 class="card-title mb-0">Measured Areas (<span id="areaCount">0</span>)</h5>
                      </div>
                      <div class="card-body">
                          <?php if (!empty($existingMeasurements)): ?>
                              <div class="alert alert-success py-2 small mb-2">
                                  <strong>Existing measurements loaded!</strong><br>
                                  <small>Last updated: <?php echo date('M j, Y g:i A', strtotime($existingMeasurements[0]['updated_at'])); ?></small>
                              </div>
                          <?php endif; ?>
                          <div id="areasList" style="max-height: 300px; overflow-y: auto;">
                              <p class="text-muted text-center small py-4">
                                  No areas measured yet
                              </p>
                          </div>

                          <div class="mw-measurement-display mt-3">
                              <div class="mw-measurement-row">
                                  <span class="mw-measurement-label"><strong>Total Area</strong></span>
                                  <span class="mw-measurement-value mw-measurement-large" id="totalArea">0 sq ft</span>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Quote Calculator -->
                  <div class="card mb-3">
                      <div class="card-header py-2">
                          <h5 class="card-title mb-0">Estimate Pricing</h5>
                      </div>
                      <div class="card-body">
                          <div class="form-group mb-2">
                              <label class="small font-weight-bold">Service Type</label>
                              <select id="serviceType" class="form-control" onchange="calculatePricing()">
                                  <option value="lawn-mowing">Weekly Lawn Mowing</option>
                                  <option value="fertilization">Fertilization</option>
                                  <option value="aeration">Aeration</option>
                                  <option value="overseeding">Overseeding</option>
                                  <option value="mulch">Mulch Installation</option>
                                  <option value="salt-application">Salt Application (per visit)</option>
                                  <option value="snow-removal">Snow Removal</option>
                                  <option value="custom">Custom Service</option>
                              </select>
                          </div>

                          <div class="form-group mb-2" id="customRateInput" style="display: none;">
                              <label class="small font-weight-bold">Rate per sq ft</label>
                              <input type="number" id="customRate" class="form-control" step="0.01" placeholder="0.00" onchange="calculatePricing()">
                          </div>

                          <div id="pricingDisplay" style="display: none;">
                              <div class="mw-measurement-display">
                                  <div class="mw-measurement-row">
                                      <span class="mw-measurement-label">Area:</span>
                                      <span class="mw-measurement-value" id="pricingArea">0 sq ft</span>
                                  </div>
                                  <div class="mw-measurement-row">
                                      <span class="mw-measurement-label">Rate:</span>
                                      <span class="mw-measurement-value" id="pricingRate">$0.00/sq ft</span>
                                  </div>
                                  <div class="mw-measurement-row">
                                      <span class="mw-measurement-label">Subtotal:</span>
                                      <span class="mw-measurement-value" id="pricingSubtotal">$0.00</span>
                                  </div>
                                  <div class="mw-measurement-row">
                                      <span class="mw-measurement-label">GST (5%):</span>
                                      <span class="mw-measurement-value" id="pricingGST">$0.00</span>
                                  </div>
                                  <div class="mw-measurement-row" style="border-top: 2px solid var(--mw-green); padding-top: 0.75rem; margin-top: 0.25rem;">
                                      <span class="mw-measurement-label"><strong>Total Price:</strong></span>
                                      <span class="mw-measurement-value mw-measurement-large" id="pricingTotal">$0.00</span>
                                  </div>
                              </div>
                          </div>

                          <button class="btn btn-primary btn-block mt-3" onclick="addToQuote()">
                              Add to Quote
                          </button>
                      </div>
                  </div>

                  <!-- Export Options -->
                  <div class="card mb-3">
                      <div class="card-header py-2">
                          <h5 class="card-title mb-0">Export & Save</h5>
                      </div>
                      <div class="card-body">
                          <?php if ($property): ?>
                              <button class="btn btn-success btn-block mb-2" onclick="saveToProperty()">
                                  Save Measurements to Property
                              </button>
                              <div id="saveStatus" class="mb-2" style="display: none; padding: 0.75rem; border-radius: 6px; font-size: 14px;"></div>
                          <?php endif; ?>
                          <div class="row">
                              <div class="col-6 mb-2">
                                  <button class="btn btn-outline-secondary btn-block btn-sm" onclick="exportToJSON()">Export JSON</button>
                              </div>
                              <div class="col-6 mb-2">
                                  <button class="btn btn-outline-secondary btn-block btn-sm" onclick="exportScreenshot()">Screenshot</button>
                              </div>
                              <div class="col-6">
                                  <button class="btn btn-outline-secondary btn-block btn-sm" onclick="printMeasurements()">Print</button>
                              </div>
                              <div class="col-6">
                                  <button class="btn btn-outline-secondary btn-block btn-sm" onclick="exportToPDF()">PDF Report</button>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

    <script>
        let map;
        let drawingManager;
        let geocoder;
        let currentShape = null;
        let savedAreas = [];
        let areaCounter = 0;

        // Service pricing rates (per sq ft)
        const servicePrices = {
            'lawn-mowing': 0.015,      // $0.015/sq ft
            'fertilization': 0.008,     // $0.008/sq ft
            'aeration': 0.012,          // $0.012/sq ft
            'overseeding': 0.010,       // $0.010/sq ft
            'mulch': 0.25,              // $0.25/sq ft
            'salt-application': 0.008,  // $0.008/sq ft
            'snow-removal': 0.020       // $0.020/sq ft
        };

        // Existing measurements from database
        const existingMeasurements = <?php echo json_encode($existingMeasurements); ?>;

        function initMap() {
            // Check if we have property coordinates
            const propLat = document.getElementById('propertyLat');
            const propLng = document.getElementById('propertyLng');

            let initialCenter = { lat: 49.2827, lng: -123.1207 }; // Vancouver default
            let initialZoom = 18;

            if (propLat && propLng) {
                initialCenter = {
                    lat: parseFloat(propLat.value),
                    lng: parseFloat(propLng.value)
                };
                initialZoom = 20; // Closer zoom for property
            }

            // Initialize map
            map = new google.maps.Map(document.getElementById('map'), {
                center: initialCenter,
                zoom: initialZoom,
                mapTypeId: 'satellite',
                mapTypeControl: false,
                streetViewControl: true,
                fullscreenControl: true
            });

            // Initialize geocoder
            geocoder = new google.maps.Geocoder();

            // Initialize drawing manager
            drawingManager = new google.maps.drawing.DrawingManager({
                drawingMode: null,
                drawingControl: false,
                polygonOptions: {
                    fillColor: '#4a7c2c',
                    fillOpacity: 0.4,
                    strokeWeight: 2,
                    strokeColor: '#2d5016',
                    editable: true,
                    draggable: true
                },
                rectangleOptions: {
                    fillColor: '#4a7c2c',
                    fillOpacity: 0.4,
                    strokeWeight: 2,
                    strokeColor: '#2d5016',
                    editable: true,
                    draggable: true
                },
                polylineOptions: {
                    strokeColor: '#e85d04',
                    strokeWeight: 3,
                    editable: true,
                    draggable: true
                }
            });

            drawingManager.setMap(map);

            // Listen for shape completion
            google.maps.event.addListener(drawingManager, 'overlaycomplete', function(event) {
                if (currentShape) {
                    currentShape.setMap(null);
                }

                currentShape = event.overlay;
                currentShapeType = event.type; // polygon, rectangle, or polyline
                drawingManager.setDrawingMode(null);

                // Update button states
                document.querySelectorAll('.mw-measure-tools .btn').forEach(btn => {
                    btn.classList.remove('mw-tool-active');
                });

                // Calculate and display measurements
                updateMeasurements();

                // Listen for shape edits
                if (event.type === 'polygon') {
                    google.maps.event.addListener(currentShape.getPath(), 'set_at', updateMeasurements);
                    google.maps.event.addListener(currentShape.getPath(), 'insert_at', updateMeasurements);
                } else if (event.type === 'rectangle') {
                    google.maps.event.addListener(currentShape, 'bounds_changed', updateMeasurements);
                } else if (event.type === 'polyline') {
                    google.maps.event.addListener(currentShape.getPath(), 'set_at', updateMeasurements);
                    google.maps.event.addListener(currentShape.getPath(), 'insert_at', updateMeasurements);
                }
            });

            // Map type selector
            document.getElementById('mapTypeSelector').addEventListener('change', function() {
                map.setMapTypeId(this.value);
            });

            // Add property marker if we have coordinates
            if (propLat && propLng) {
                new google.maps.Marker({
                    map: map,
                    position: initialCenter,
                    title: 'Property Location',
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 10,
                        fillColor: '#4a7c2c',
                        fillOpacity: 1,
                        strokeColor: '#fff',
                        strokeWeight: 2
                    }
                });
            }
            // If we have address but no coords, auto-search
            else if (document.getElementById('addressInput').value) {
                setTimeout(searchAddress, 500);
            }

            // Load existing measurements if available
            if (existingMeasurements && existingMeasurements.length > 0) {
                loadExistingMeasurements();
            }
        }

        function loadExistingMeasurements() {
            existingMeasurements.forEach((m, index) => {
                // Create area object without shape (we don't store polygon coords yet)
                const area = {
                    id: ++areaCounter,
                    name: m.measurement_name,
                    type: m.measurement_type,
                    sqFt: parseFloat(m.area_sqft),
                    shape: null, // No shape to display yet
                    color: getRandomColor(),
                    fromDb: true
                };

                savedAreas.push(area);
            });

            updateAreasList();
            calculatePricing();
        }

        function searchAddress() {
            const address = document.getElementById('addressInput').value;
            if (!address) return;

            geocoder.geocode({ address: address }, function(results, status) {
                if (status === 'OK') {
                    map.setCenter(results[0].geometry.location);
                    map.setZoom(19);

                    // Add marker
                    new google.maps.Marker({
                        map: map,
                        position: results[0].geometry.location,
                        title: 'Property Location'
                    });
                } else {
                    alert('Address not found: ' + status);
                }
            });
        }

        function startDrawing(type) {
            // Clear previous drawing mode
            drawingManager.setDrawingMode(null);

            // Remove active class from all buttons
            document.querySelectorAll('.mw-measure-tools .btn').forEach(btn => {
                btn.classList.remove('mw-tool-active');
            });

            // Set new drawing mode and activate button
            if (type === 'polygon') {
                drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
                document.getElementById('drawPolygonBtn').classList.add('mw-tool-active');
            } else if (type === 'rectangle') {
                drawingManager.setDrawingMode(google.maps.drawing.OverlayType.RECTANGLE);
                document.getElementById('drawRectangleBtn').classList.add('mw-tool-active');
            } else if (type === 'polyline') {
                drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYLINE);
                document.getElementById('drawPolylineBtn').classList.add('mw-tool-active');
            }
        }

        // Track current shape type for polyline vs polygon/rectangle
        var currentShapeType = 'polygon';

        function clearCurrentDrawing() {
            if (currentShape) {
                currentShape.setMap(null);
                currentShape = null;
                document.getElementById('currentMeasurement').style.display = 'none';
            }
        }

        function updateMeasurements() {
            if (!currentShape) return;

            let area = 0;
            let perimeter = 0;
            let linearLength = 0;

            if (currentShapeType === 'polyline') {
                // Polyline — compute length only, no area
                const path = currentShape.getPath();
                linearLength = google.maps.geometry.spherical.computeLength(path);
                area = 0;
                perimeter = 0;
            } else if (currentShape.getPath) {
                // Polygon
                const path = currentShape.getPath();
                area = google.maps.geometry.spherical.computeArea(path);

                // Calculate perimeter
                for (let i = 0; i < path.getLength(); i++) {
                    const start = path.getAt(i);
                    const end = path.getAt((i + 1) % path.getLength());
                    perimeter += google.maps.geometry.spherical.computeDistanceBetween(start, end);
                }
            } else {
                // Rectangle
                const bounds = currentShape.getBounds();
                const ne = bounds.getNorthEast();
                const sw = bounds.getSouthWest();
                const nw = new google.maps.LatLng(ne.lat(), sw.lng());
                const se = new google.maps.LatLng(sw.lat(), ne.lng());

                // Calculate area
                const path = [nw, ne, se, sw];
                area = google.maps.geometry.spherical.computeArea(path);

                // Calculate perimeter
                const width = google.maps.geometry.spherical.computeDistanceBetween(nw, ne);
                const height = google.maps.geometry.spherical.computeDistanceBetween(ne, se);
                perimeter = 2 * (width + height);
            }

            // Convert to various units
            const sqMeters = area;
            const sqFeet = sqMeters * 10.764;
            const acres = sqMeters * 0.000247105;
            const perimeterFeet = perimeter * 3.28084;
            const linearFeet = linearLength * 3.28084;

            // Display measurements
            if (currentShapeType === 'polyline') {
                document.getElementById('currentSqFt').textContent = '—';
                document.getElementById('currentSqM').textContent = '—';
                document.getElementById('currentAcres').textContent = '—';
                document.getElementById('currentPerimeter').textContent = Math.round(linearFeet).toLocaleString() + ' lin ft';
            } else {
                document.getElementById('currentSqFt').textContent = Math.round(sqFeet).toLocaleString();
                document.getElementById('currentSqM').textContent = Math.round(sqMeters).toLocaleString();
                document.getElementById('currentAcres').textContent = acres.toFixed(3);
                document.getElementById('currentPerimeter').textContent = Math.round(perimeterFeet).toLocaleString() + ' ft';
            }

            document.getElementById('currentMeasurement').style.display = 'block';
        }

        function saveArea() {
            if (!currentShape) return;

            const areaName = document.getElementById('areaName').value || `Area ${areaCounter + 1}`;
            const areaType = document.getElementById('areaType').value;
            const sqFtText = document.getElementById('currentSqFt').textContent.replace(/,/g, '');
            const sqFt = sqFtText === '—' ? 0 : parseInt(sqFtText);
            const perimText = document.getElementById('currentPerimeter').textContent.replace(/,/g, '').replace(/\s*(ft|lin ft)/, '');
            const linearFt = currentShapeType === 'polyline' ? parseInt(perimText) || 0 : 0;

            const area = {
                id: ++areaCounter,
                name: areaName,
                type: areaType,
                sqFt: sqFt,
                linearFt: linearFt,
                shape: currentShape,
                shapeType: currentShapeType,
                color: getRandomColor()
            };

            // Update shape appearance
            if (currentShapeType === 'polyline') {
                currentShape.setOptions({
                    strokeColor: area.color,
                    strokeWeight: 3,
                    editable: false,
                    draggable: false
                });
            } else {
                currentShape.setOptions({
                    fillColor: area.color,
                    fillOpacity: 0.3,
                    editable: false,
                    draggable: false
                });
            }

            savedAreas.push(area);
            updateAreasList();

            // Clear current drawing
            currentShape = null;
            document.getElementById('currentMeasurement').style.display = 'none';
            document.getElementById('areaName').value = '';

            calculatePricing();
        }

        function updateAreasList() {
            const container = document.getElementById('areasList');

            if (savedAreas.length === 0) {
                container.innerHTML = '<p class="text-muted text-center small py-4">No areas measured yet</p>';
                return;
            }

            container.innerHTML = savedAreas.map(area => `
                <div class="mw-area-item" style="border-left: 4px solid ${area.color};">
                    <div class="mw-area-item-info">
                        <div class="mw-area-item-name">${area.name}</div>
                        <div class="mw-area-item-detail">${area.type} - ${area.shapeType === 'polyline' ? (area.linearFt || 0).toLocaleString() + ' lin ft' : area.sqFt.toLocaleString() + ' sq ft'}</div>
                    </div>
                    <div class="mw-area-item-actions">
                        <button onclick="zoomToArea(${area.id})" title="Zoom to area">Zoom</button>
                        <button onclick="deleteArea(${area.id})" title="Delete">Delete</button>
                    </div>
                </div>
            `).join('');

            // Update totals
            const totalSqFt = savedAreas.reduce((sum, area) => sum + area.sqFt, 0);
            document.getElementById('areaCount').textContent = savedAreas.length;
            document.getElementById('totalArea').textContent = totalSqFt.toLocaleString() + ' sq ft';
        }

        function deleteArea(id) {
            const area = savedAreas.find(a => a.id === id);
            if (area) {
                if (area.shape) area.shape.setMap(null);
                savedAreas = savedAreas.filter(a => a.id !== id);
                updateAreasList();
                calculatePricing();
            }
        }

        function zoomToArea(id) {
            const area = savedAreas.find(a => a.id === id);
            if (!area || !area.shape) return;

            let bounds = new google.maps.LatLngBounds();

            if (area.shape.getPath) {
                area.shape.getPath().forEach(point => bounds.extend(point));
            } else {
                bounds = area.shape.getBounds();
            }

            map.fitBounds(bounds);
        }

        function clearAllAreas() {
            if (confirm('Clear all measured areas?')) {
                savedAreas.forEach(area => { if (area.shape) area.shape.setMap(null); });
                savedAreas = [];
                clearCurrentDrawing();
                updateAreasList();
                document.getElementById('pricingDisplay').style.display = 'none';
            }
        }

        function calculatePricing() {
            const serviceType = document.getElementById('serviceType').value;
            const totalSqFt = savedAreas.reduce((sum, area) => sum + area.sqFt, 0);

            if (totalSqFt === 0) {
                document.getElementById('pricingDisplay').style.display = 'none';
                return;
            }

            let rate;
            if (serviceType === 'custom') {
                document.getElementById('customRateInput').style.display = 'block';
                rate = parseFloat(document.getElementById('customRate').value) || 0;
            } else {
                document.getElementById('customRateInput').style.display = 'none';
                rate = servicePrices[serviceType] || 0;
            }

            const subtotal = totalSqFt * rate;
            const gst = subtotal * 0.05;
            const total = subtotal + gst;

            document.getElementById('pricingArea').textContent = totalSqFt.toLocaleString() + ' sq ft';
            document.getElementById('pricingRate').textContent = '$' + rate.toFixed(3) + '/sq ft';
            document.getElementById('pricingSubtotal').textContent = '$' + subtotal.toFixed(2);
            document.getElementById('pricingGST').textContent = '$' + gst.toFixed(2);
            document.getElementById('pricingTotal').textContent = '$' + total.toFixed(2);

            document.getElementById('pricingDisplay').style.display = 'block';
        }

        function addToQuote() {
            const quoteData = {
                areas: savedAreas.map(a => ({
                    name: a.name,
                    type: a.type,
                    sqFt: a.sqFt
                })),
                service: document.getElementById('serviceType').value,
                pricing: {
                    subtotal: document.getElementById('pricingSubtotal').textContent,
                    gst: document.getElementById('pricingGST').textContent,
                    total: document.getElementById('pricingTotal').textContent
                }
            };

            console.log('Adding to quote:', quoteData);
            alert('Quote data ready! This will be integrated with your quote system.');

            // In production, this would send data to the quote builder
            // window.parent.postMessage({ type: 'ADD_TO_QUOTE', data: quoteData }, '*');
        }

        function exportToJSON() {
            const data = {
                timestamp: new Date().toISOString(),
                address: document.getElementById('addressInput').value,
                areas: savedAreas.map(a => ({
                    id: a.id,
                    name: a.name,
                    type: a.type,
                    sqFt: a.sqFt
                })),
                totalSqFt: savedAreas.reduce((sum, a) => sum + a.sqFt, 0)
            };

            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'area-measurements.json';
            a.click();
        }

        function exportScreenshot() {
            alert('Screenshot feature will capture the map view. This requires additional setup with html2canvas library.');
        }

        function printMeasurements() {
            window.print();
        }

        function exportToPDF() {
            alert('PDF export feature will generate a professional measurement report. This requires jsPDF library integration.');
        }

        function saveToProperty() {
            const propertyId = document.getElementById('propertyId');
            if (!propertyId) {
                alert('No property selected. Please access this page from a property link.');
                return;
            }

            if (savedAreas.length === 0) {
                alert('No areas to save. Please measure at least one area first.');
                return;
            }

            const statusDiv = document.getElementById('saveStatus');
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#dbeafe';
            statusDiv.style.color = '#1e40af';
            statusDiv.textContent = 'Saving measurements...';

            // Prepare measurement data
            const measurements = savedAreas.map(area => {
                let coords = null;
                if (area.shape && area.shape.getPath) {
                    coords = area.shape.getPath().getArray().map(p => ({
                        lat: p.lat(),
                        lng: p.lng()
                    }));
                }

                return {
                    name: area.name,
                    type: area.type,
                    sqFt: area.sqFt,
                    linearFt: area.linearFt || null,
                    perimeter: area.perimeter || null,
                    shape: area.shapeType || 'polygon',
                    coords: coords
                };
            });

            // Send to server
            const formData = new FormData();
            formData.append('action', 'save_measurements');
            formData.append('property_id', propertyId.value);
            formData.append('measurements', JSON.stringify(measurements));

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.style.background = '#d1fae5';
                    statusDiv.style.color = '#065f46';
                    statusDiv.textContent = data.message;
                } else {
                    statusDiv.style.background = '#fee2e2';
                    statusDiv.style.color = '#991b1b';
                    statusDiv.textContent = data.error || 'Failed to save';
                }
            })
            .catch(error => {
                statusDiv.style.background = '#fee2e2';
                statusDiv.style.color = '#991b1b';
                statusDiv.textContent = 'Error: ' + error.message;
            });
        }

        function getRandomColor() {
            const colors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#6366f1'];
            return colors[Math.floor(Math.random() * colors.length)];
        }

        // Service type change handler
        document.getElementById('serviceType').addEventListener('change', calculatePricing);

        // ─── Group Manager ──────────────────────────────────────
        function openGroupManager() {
            document.getElementById('groupManagerModal').style.display = 'flex';
            loadGroups();
        }

        function closeGroupManager() {
            document.getElementById('groupManagerModal').style.display = 'none';
        }

        function loadGroups() {
            var container = document.getElementById('groupsList');
            container.innerHTML = '<p class="text-muted text-center py-3">Loading...</p>';

            fetch('../api/measurement-groups.php?action=list')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) { container.innerHTML = '<p class="text-danger">' + (data.error || 'Error') + '</p>'; return; }
                    if (data.groups.length === 0) { container.innerHTML = '<p class="text-muted text-center py-3">No groups defined</p>'; return; }

                    var html = '';
                    data.groups.forEach(function(g) {
                        var typeBadges = (g.types_array || []).map(function(t) {
                            return '<span class="badge badge-secondary mr-1 mb-1" style="font-size:0.75rem;">' + t +
                                   ' <button type="button" class="close ml-1" style="font-size:0.85rem; line-height:1; color:#fff; text-shadow:none;" onclick="removeTypeFromGroup(' + g.id + ', \'' + t + '\')">&times;</button></span>';
                        }).join('');

                        html += '<div class="card mb-2">' +
                            '<div class="card-body py-2 px-3">' +
                                '<div class="d-flex justify-content-between align-items-start mb-1">' +
                                    '<div style="flex:1;">' +
                                        '<input type="text" class="form-control form-control-sm font-weight-bold" value="' + (g.group_label || '').replace(/"/g, '&quot;') + '" ' +
                                            'onchange="renameGroup(' + g.id + ', this.value)" style="border:none; padding:0; background:transparent; font-size:0.9rem;" onfocus="this.style.border=\'1px solid #ced4da\'; this.style.padding=\'0.15rem 0.25rem\'; this.style.background=\'#fff\';" onblur="this.style.border=\'none\'; this.style.padding=\'0\'; this.style.background=\'transparent\';">' +
                                        '<div class="text-muted" style="font-size:0.7rem;">Key: ' + g.group_key + ' | Unit: ' + g.unit + '</div>' +
                                    '</div>' +
                                    '<button type="button" class="btn btn-sm btn-outline-danger ml-2" onclick="deleteGroup(' + g.id + ', \'' + g.group_key.replace(/'/g, "\\'") + '\')" style="font-size:0.7rem; padding:0.1rem 0.4rem;">Delete</button>' +
                                '</div>' +
                                '<div class="mt-1">' + typeBadges + '</div>' +
                                '<div class="mt-1 d-flex" style="gap:0.25rem;">' +
                                    '<input type="text" class="form-control form-control-sm" id="newType_' + g.id + '" placeholder="New type..." style="font-size:0.75rem; max-width:140px;">' +
                                    '<button class="btn btn-sm btn-outline-primary" onclick="addTypeToGroup(' + g.id + ')" style="font-size:0.7rem; white-space:nowrap;">+ Type</button>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });

                    container.innerHTML = html;
                })
                .catch(function(err) { container.innerHTML = '<p class="text-danger">Error: ' + err.message + '</p>'; });
        }

        function renameGroup(groupId, newLabel) {
            if (!newLabel.trim()) return;
            var fd = new FormData();
            fd.append('action', 'update-label');
            fd.append('group_id', groupId);
            fd.append('group_label', newLabel.trim());
            fetch('../api/measurement-groups.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) alert(data.error || 'Error');
                    // Refresh the area type dropdown
                    refreshAreaTypeDropdown();
                });
        }

        function deleteGroup(groupId, groupKey) {
            if (!confirm('Delete group "' + groupKey + '"? Existing measurements will keep their data.')) return;
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('group_id', groupId);
            fetch('../api/measurement-groups.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { loadGroups(); refreshAreaTypeDropdown(); }
                    else alert(data.error || 'Error');
                });
        }

        function addTypeToGroup(groupId) {
            var input = document.getElementById('newType_' + groupId);
            var newType = (input.value || '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
            if (!newType) { alert('Enter a type name'); return; }
            var fd = new FormData();
            fd.append('action', 'add-type');
            fd.append('group_id', groupId);
            fd.append('measurement_type', newType);
            fetch('../api/measurement-groups.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { loadGroups(); refreshAreaTypeDropdown(); }
                    else alert(data.error || 'Error');
                });
        }

        function removeTypeFromGroup(groupId, type) {
            if (!confirm('Remove type "' + type + '" from this group?')) return;
            var fd = new FormData();
            fd.append('action', 'remove-type');
            fd.append('group_id', groupId);
            fd.append('measurement_type', type);
            fetch('../api/measurement-groups.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { loadGroups(); refreshAreaTypeDropdown(); }
                    else alert(data.error || 'Error');
                });
        }

        function addNewGroup() {
            var label = document.getElementById('newGroupLabel').value.trim();
            if (!label) { alert('Enter a group name'); return; }
            var key = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
            var unit = document.getElementById('newGroupUnit').value;

            var fd = new FormData();
            fd.append('action', 'add');
            fd.append('group_key', key);
            fd.append('group_label', label);
            fd.append('unit', unit);
            fd.append('measurement_types', key); // default: one type matching the key
            fetch('../api/measurement-groups.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('newGroupLabel').value = '';
                        loadGroups();
                        refreshAreaTypeDropdown();
                    } else {
                        alert(data.error || 'Error');
                    }
                });
        }

        function refreshAreaTypeDropdown() {
            fetch('../api/measurement-groups.php?action=list')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) return;
                    var select = document.getElementById('areaType');
                    var currentVal = select.value;
                    select.innerHTML = '';
                    data.groups.forEach(function(g) {
                        if (!g.is_active || g.is_active === '0') return;
                        (g.types_array || []).forEach(function(t) {
                            var opt = document.createElement('option');
                            opt.value = t;
                            opt.textContent = t.charAt(0).toUpperCase() + t.slice(1).replace(/_/g, ' ');
                            select.appendChild(opt);
                        });
                    });
                    // Restore selection if still valid
                    if (select.querySelector('option[value="' + currentVal + '"]')) {
                        select.value = currentVal;
                    }
                });
        }
    </script>

    <!-- Group Manager Modal -->
    <div id="groupManagerModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div class="card" style="width:500px; max-width:90vw; max-height:85vh; overflow-y:auto;">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Manage Area Groups</h5>
                <button type="button" class="close" onclick="closeGroupManager()" style="font-size:1.2rem;">&times;</button>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Area groups organize measurement types for pricing. Edit names inline, add/remove types, or create new groups.</p>

                <div id="groupsList">
                    <p class="text-muted text-center py-3">Loading...</p>
                </div>

                <hr>
                <h6 class="mb-2" style="font-size:0.85rem;">Add New Group</h6>
                <div class="d-flex mb-2" style="gap:0.5rem;">
                    <input type="text" id="newGroupLabel" class="form-control form-control-sm" placeholder="Group name (e.g., Salt Area)" style="flex:2;">
                    <select id="newGroupUnit" class="form-control form-control-sm" style="flex:1;">
                        <option value="sqft">sq ft</option>
                        <option value="linear_ft">linear ft</option>
                    </select>
                    <button class="btn btn-sm btn-primary" onclick="addNewGroup()" style="white-space:nowrap;">Add Group</button>
                </div>
            </div>
        </div>
    </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
