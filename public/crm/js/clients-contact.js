/* Moved out of public/crm/includes/clients/view-contact.php (clients refactor, no change in behaviour). PHP values arrive in window.mwClients.clients_contact. */
            (function() {
              var CONTACT_ID = mwClients.clients_contact.c2;
              var CSRF_TOKEN = mwClients.clients_contact.c0;

              // ── Inline bank-deposit matching (same as /crm/invoices/index.php) ──
              function mwFmtMoney(n) {
                return '$' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
              }
              window.mwToggleMatch = function (invoiceId) {
                var row = document.getElementById('mw-match-row-' + invoiceId);
                if (!row) return;
                var open = row.style.display !== 'none' && row.style.display !== '';
                row.style.display = open ? 'none' : 'table-row';
              };

              window.mwMarkDepositRecorded = function (btn, txId, desc) {
                  if (!confirm('Mark this deposit as already recorded?\n\n' + desc + '\n\nIt will stop being suggested against invoices and be booked as a cash-clearing transfer (not income). You can reverse this later.')) { return; }
                  var origLabel = btn.textContent;
                  btn.disabled = true;
                  btn.textContent = 'Saving…';
                  fetch('/crm/api/accounting-reconciliation.php', {
                      method : 'POST',
                      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                      body   : JSON.stringify({ action: 'mark_recorded', csrf_token: CSRF_TOKEN, transaction_id: txId })
                  })
                  .then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (data.ok) {
                          mwToast('Deposit marked as already recorded.', 'success');
                          setTimeout(function () { window.location.reload(); }, 1000);
                      } else {
                          btn.disabled = false; btn.textContent = origLabel;
                          mwToast(data.error || 'Could not update the deposit.', 'error');
                      }
                  })
                  .catch(function () {
                      btn.disabled = false; btn.textContent = origLabel;
                      mwToast('Couldn\'t reach the server. Check your connection and try again.', 'error');
                  });
              };
              window.mwAttachDeposit = function (btn, invoiceId, txId, invNum) {
                var item      = btn.closest('.mw-match-item');
                var input     = item.querySelector('.mw-match-amount');
                var amount    = parseFloat(input.value);
                var balance   = parseFloat(input.dataset.balance);
                var remaining = parseFloat(input.dataset.remaining);
                if (!(amount > 0)) { mwToast('Enter a valid amount.', 'error'); return; }
                if (amount > remaining + 0.005) {
                  mwToast('Amount exceeds the deposit (' + mwFmtMoney(remaining) + ' available).', 'error');
                  return;
                }
                if (amount > balance + 0.005) {
                  if (!confirm('That is more than the invoice balance (' + mwFmtMoney(balance)
                    + '). It will be capped to the balance. Continue?')) { return; }
                }
                var origLabel = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Attaching…';
                fetch('/crm/api/accounting-reconciliation.php', {
                  method : 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                  body   : JSON.stringify({
                    action        : 'attach',
                    csrf_token    : CSRF_TOKEN,
                    transaction_id: txId,
                    allocations   : [{ invoice_id: invoiceId, amount: amount }]
                  })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                  if (data.ok) {
                    var rec = (data.result && data.result.recorded && data.result.recorded[0]) || {};
                    mwToast(invNum + ' — ' + (rec.fully_paid ? 'paid in full' : 'partial payment recorded') + '.', 'success');
                    setTimeout(function () { window.location.reload(); }, 1200);
                  } else {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                    mwToast(data.error || 'Could not attach deposit.', 'error');
                  }
                })
                .catch(function (err) {
                  btn.disabled = false;
                  btn.textContent = origLabel;
                  console.error('[mwAttachDeposit]', err);
                  mwToast('Couldn\'t reach the server. Check your connection and try again.', 'error');
                });
              };
              var propertiesData = mwClients.clients_contact.c3;
              var nearbyClientsData = mwClients.clients_contact.c4;
              var availableTags = mwClients.clients_contact.c5;
              var gmap = null;
              var markers = {};
              var nearbyMarkers = [];
              var openInfoWindow = null;

              // ── Google Map with Nearby Clients ────────────────────
              function initContactMap() {
                var mapEl = document.getElementById('contactMapContainer');
                if (!mapEl) return;
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') return;

                var bounds = new google.maps.LatLngBounds();
                var hasMarkers = false;

                gmap = new google.maps.Map(mapEl, {
                  gestureHandling: 'greedy',
                  zoom: 12,
                  center: { lat: 49.2827, lng: -123.1207 },
                  mapTypeId: google.maps.MapTypeId.ROADMAP,
                  mapTypeControl: false,
                  streetViewControl: false,
                  styles: [
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                    { featureType: 'transit', stylers: [{ visibility: 'simplified' }] }
                  ]
                });

                // ── This client's properties (red markers) ──
                propertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: prop.address,
                    icon: {
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 10,
                      fillColor: '#E53E3E',
                      fillOpacity: 1,
                      strokeColor: '#fff',
                      strokeWeight: 2.5
                    },
                    zIndex: 1000
                  });

                  var infoContent = '<div style="max-width:260px;font-family:system-ui,sans-serif;">' +
                    '<div style="font-weight:700;color:#E53E3E;margin-bottom:2px;">This Client</div>' +
                    '<strong>' + escHtml(prop.address) + '</strong><br>' +
                    '<small style="color:#6B7280;">' + escHtml((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() {
                    if (openInfoWindow) openInfoWindow.close();
                    infoWindow.open(gmap, marker);
                    openInfoWindow = infoWindow;
                  });

                  markers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                // ── Nearby active clients (green markers) ──
                nearbyClientsData.forEach(function(nc, idx) {
                  var lat = parseFloat(nc.latitude);
                  var lng = parseFloat(nc.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var distKm = parseFloat(nc.distance_km);
                  var scaleSize = distKm <= 1 ? 8 : (distKm <= 3 ? 7 : 6);
                  var opacity = distKm <= 2 ? 0.9 : (distKm <= 5 ? 0.7 : 0.5);

                  var marker = new google.maps.Marker({
                    position: pos,
                    map: gmap,
                    title: nc.first_name + ' ' + nc.last_name + ' (' + nc.distance_km + ' km)',
                    icon: {
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: scaleSize,
                      fillColor: '#2D8659',
                      fillOpacity: opacity,
                      strokeColor: '#fff',
                      strokeWeight: 1.5
                    },
                    zIndex: 500 - idx
                  });

                  var services = (nc.service_types || '').split(',').map(function(s) {
                    return s.trim().replace(/_/g, ' ');
                  }).filter(Boolean).map(function(s) {
                    return '<span style="display:inline-block;background:#E8F3F0;color:#2D8659;padding:1px 6px;border-radius:3px;font-size:11px;margin:1px 2px 1px 0;">' + escHtml(s) + '</span>';
                  }).join('');

                  var infoContent = '<div style="max-width:280px;font-family:system-ui,sans-serif;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">' +
                      '<strong style="color:#2D8659;">' + escHtml(nc.first_name + ' ' + nc.last_name) + '</strong>' +
                      '<span style="background:#2D8659;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;">' + nc.distance_km + ' km</span>' +
                    '</div>' +
                    '<div style="font-size:13px;color:#374151;">' + escHtml(nc.address) + '</div>' +
                    '<div style="font-size:12px;color:#6B7280;margin-bottom:4px;">' + escHtml(nc.city || '') + '</div>' +
                    (services ? '<div style="margin-top:4px;">' + services + '</div>' : '') +
                    '<div style="margin-top:6px;font-size:12px;color:#6B7280;">' +
                      nc.active_plan_count + ' active plan' + (nc.active_plan_count != 1 ? 's' : '') +
                      (nc.total_per_visit ? ' &middot; $' + parseFloat(nc.total_per_visit).toFixed(0) + '/visit' : '') +
                    '</div>' +
                    '<a href="?action=view_contact&id=' + nc.contact_id + '" style="display:inline-block;margin-top:6px;font-size:12px;color:#2D8659;font-weight:600;text-decoration:none;">View Client &rarr;</a>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() {
                    if (openInfoWindow) openInfoWindow.close();
                    infoWindow.open(gmap, marker);
                    openInfoWindow = infoWindow;
                  });

                  nearbyMarkers.push({ marker: marker, infoWindow: infoWindow, data: nc });
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  gmap.fitBounds(bounds);
                  // Ensure we don't zoom in too much if only 1 property + few nearby
                  google.maps.event.addListenerOnce(gmap, 'bounds_changed', function() {
                    if (gmap.getZoom() > 15) gmap.setZoom(15);
                  });
                }

                // ── Hover highlight from route intel list ──
                document.querySelectorAll('.mw-route-intel-item').forEach(function(item) {
                  var idx = parseInt(item.getAttribute('data-nearby-idx'));
                  if (isNaN(idx) || !nearbyMarkers[idx]) return;
                  item.addEventListener('mouseenter', function() {
                    nearbyMarkers[idx].marker.setIcon({
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 12,
                      fillColor: '#F59E0B',
                      fillOpacity: 1,
                      strokeColor: '#fff',
                      strokeWeight: 2.5
                    });
                    nearbyMarkers[idx].marker.setZIndex(2000);
                  });
                  item.addEventListener('mouseleave', function() {
                    var nc = nearbyMarkers[idx].data;
                    var distKm = parseFloat(nc.distance_km);
                    nearbyMarkers[idx].marker.setIcon({
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: distKm <= 1 ? 8 : (distKm <= 3 ? 7 : 6),
                      fillColor: '#2D8659',
                      fillOpacity: distKm <= 2 ? 0.9 : (distKm <= 5 ? 0.7 : 0.5),
                      strokeColor: '#fff',
                      strokeWeight: 1.5
                    });
                    nearbyMarkers[idx].marker.setZIndex(500 - idx);
                  });
                });
              }

              // ── Focus Property on Map ──────────────────────────
              window.focusProperty = function(propertyId) {
                if (!gmap || !markers[propertyId]) return;
                var marker = markers[propertyId];
                gmap.panTo(marker.getPosition());
                gmap.setZoom(16);
                google.maps.event.trigger(marker, 'click');
              };

              // ── Edit Property ────────────────────────────────────
              window.showEditProperty = function(propId, address, city, province, postalCode, propName) {
                document.getElementById('editPropId').value = propId;
                document.getElementById('editPropAddress').value = address;
                document.getElementById('editPropCity').value = city;
                document.getElementById('editPropProvince').value = province;
                document.getElementById('editPropPostal').value = postalCode;
                document.getElementById('editPropName').value = propName;
                $('#editPropertyModal').modal('show');
              };

              window.saveEditProperty = function() {
                var propId   = document.getElementById('editPropId').value;
                var address  = document.getElementById('editPropAddress').value.trim();
                var city     = document.getElementById('editPropCity').value.trim();
                var province = document.getElementById('editPropProvince').value.trim();
                var postal   = document.getElementById('editPropPostal').value.trim();
                var name     = document.getElementById('editPropName').value.trim();

                if (!address) {
                  document.getElementById('editPropAddress').focus();
                  return;
                }

                var btn = document.querySelector('#editPropertyModal .btn-primary');
                btn.disabled = true;
                btn.textContent = 'Saving…';

                fetch('clients_appstack.php?action=edit_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    csrf_token:    mwClients.clients_contact.c1,
                    property_id:   parseInt(propId),
                    address:       address,
                    city:          city,
                    province:      province,
                    postal_code:   postal,
                    property_name: name
                  })
                })
                .then(r => r.json())
                .then(data => {
                  if (data.success) {
                    $('#editPropertyModal').modal('hide');
                    location.reload();
                  } else {
                    alert('Could not save: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = 'Save Changes';
                  }
                })
                .catch(() => {
                  alert('Couldn\'t reach the server. Check your connection and try again.');
                  btn.disabled = false;
                  btn.textContent = 'Save Changes';
                });
              };

              // ── Unlink / Reassign Property ───────────────────────
              window.showUnlinkProperty = function(propertyId, address) {
                document.getElementById('unlinkPropertyId').value = propertyId;
                document.getElementById('unlinkPropAddress').textContent = address;
                document.getElementById('reassignContactSelect').value = '';
                document.querySelector('input[name="unlinkAction"][value="unlink"]').checked = true;
                document.getElementById('reassignContactRow').style.display = 'none';
                $('#unlinkPropertyModal').modal('show');
              };

              // Toggle reassign dropdown visibility
              document.querySelectorAll('input[name="unlinkAction"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                  document.getElementById('reassignContactRow').style.display =
                    this.value === 'reassign' ? 'block' : 'none';
                });
              });

              window.unlinkProperty = function() {
                var propertyId = parseInt(document.getElementById('unlinkPropertyId').value);
                var action = document.querySelector('input[name="unlinkAction"]:checked').value;
                var newContactId = 0;

                if (action === 'reassign') {
                  newContactId = parseInt(document.getElementById('reassignContactSelect').value);
                  if (!newContactId) {
                    alert('Please select a contact to reassign to.');
                    return;
                  }
                }

                var btn = document.getElementById('confirmUnlinkBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';

                fetch('clients_appstack.php?action=unlink_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    property_id: propertyId,
                    current_contact_id: CONTACT_ID,
                    new_contact_id: newContactId,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="check"></i> Confirm';
                    feather.replace();
                  }
                })
                .catch(function() {
                  alert('Couldn\'t reach the server. Check your connection and try again.');
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="check"></i> Confirm';
                  feather.replace();
                });
              };

              // ── Add Property ────────────────────────────────────
              window.addPropertyToContact = function(event) {
                event.preventDefault();
                var address = document.getElementById('propAddress').value.trim();
                if (!address) { alert('Please enter a street address'); return; }

                var btn = document.getElementById('addPropBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

                fetch('clients_appstack.php?action=add_property_to_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    address: address,
                    city: document.getElementById('propCity').value.trim() || 'Vancouver',
                    postal_code: document.getElementById('propPostalCode').value.trim(),
                    property_name: document.getElementById('propName').value.trim(),
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    // Geocode the new property, then prompt for arrival border
                    var newPropId = data.property_id;
                    geocodePropertyAjax(newPropId).then(function() {
                      // Geocode succeeded — prompt to draw arrival border
                      $('#addPropertyModal').modal('hide');
                      var returnTo = 'clients_appstack.php?action=view_contact&id=' + CONTACT_ID;
                      var zoneUrl = 'jobs/zone-editor.php?property_id=' + newPropId + '&return_to=' + encodeURIComponent(returnTo);
                      document.getElementById('drawBorderNowBtn').href = zoneUrl;
                      $('#arrivalBorderPromptModal').modal('show');
                      feather.replace();
                    }).catch(function() {
                      // Geocode failed — just reload (can't draw border without coords)
                      location.reload();
                    });
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                    feather.replace();
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="plus"></i> Add Property';
                  feather.replace();
                });
              };

              window.skipArrivalBorder = function() {
                $('#arrivalBorderPromptModal').modal('hide');
                location.reload();
              };

              // ── Link Company ────────────────────────────────────
              window.linkCompanyToContact = function() {
                var sel = document.getElementById('linkCompanySelect');
                if (!sel || !sel.value) { alert('Please select a company'); return; }

                fetch('clients_appstack.php?action=link_company_to_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    company_id: parseInt(sel.value),
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) location.reload();
                  else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              // ── Geocode Property (client-side via Google Maps JS API) ──
              var geocoder = null;
              function geocodePropertyAjax(propertyId) {
                if (!geocoder) geocoder = new google.maps.Geocoder();
                var prop = propertiesData.find(function(p) { return p.id == propertyId; });
                if (!prop) return Promise.reject(new Error('Property not found'));

                var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
                var address = parts.join(', ');

                return new Promise(function(resolve, reject) {
                  geocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                      var loc = results[0].geometry.location;
                      fetch('clients_appstack.php?action=save_property_coords', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                          property_id: propertyId,
                          lat: loc.lat(),
                          lng: loc.lng(),
                          csrf_token: CSRF_TOKEN
                        })
                      })
                      .then(function(r) { return r.json(); })
                      .then(function(data) { resolve(data); })
                      .catch(function(err) { reject(err); });
                    } else {
                      resolve({ success: false, error: 'Geocode failed: ' + status + ' for "' + address + '"' });
                    }
                  });
                });
              }

              window.geocodeProperty = function(propertyId, btn) {
                if (btn) {
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                }
                geocodePropertyAjax(propertyId)
                  .then(function(data) {
                    if (data.success) {
                      location.reload();
                    } else {
                      alert('Couldn\'t find this address on the map. ' + (data.error || 'Check the address and try again.'));
                      if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" class="mw-icon-xs"></i>'; feather.replace(); }
                    }
                  })
                  .catch(function(err) {
                    alert('Error: ' + err.message);
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="crosshair" class="mw-icon-xs"></i>'; feather.replace(); }
                  });
              };

              window.geocodeAllProperties = function() {
                var toGeocode = propertiesData.filter(function(p) { return !parseFloat(p.latitude) || !parseFloat(p.longitude); });
                if (toGeocode.length === 0) return;

                var chain = Promise.resolve();
                toGeocode.forEach(function(p) {
                  chain = chain.then(function() {
                    return geocodePropertyAjax(p.id);
                  }).then(function() {
                    // Small delay between geocode requests to avoid rate limiting
                    return new Promise(function(resolve) { setTimeout(resolve, 300); });
                  });
                });
                chain.then(function() { location.reload(); });
              };

              // ── Utilities ───────────────────────────────────────
              function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
              }

              // ── Property Tags ──────────────────────────────────
              var activeTagPicker = null;

              window.showTagPicker = function(propertyId, btn) {
                // Close any existing picker
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }

                var picker = document.createElement('div');
                picker.className = 'mw-tag-picker-dropdown';
                picker.onclick = function(e) { e.stopPropagation(); };

                var html = '<div class="mw-tag-picker-inner">';
                html += '<select class="mw-tag-picker-select" id="tagSelect_' + propertyId + '" onchange="onTagSelectChange(' + propertyId + ')">';
                html += '<option value="">Select tag…</option>';
                for (var i = 0; i < availableTags.length; i++) {
                  html += '<option value="' + availableTags[i].tag_id + '" data-has-value="' + availableTags[i].has_value + '">' + escHtml(availableTags[i].tag_label) + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="mw-tag-picker-value" id="tagValue_' + propertyId + '" placeholder="Value…" style="display:none;">';
                html += '<button type="button" class="mw-tag-picker-save" onclick="applyPropertyTag(' + propertyId + ')">Save</button>';
                html += '<button type="button" class="mw-tag-picker-cancel" onclick="closeTagPicker()">&times;</button>';
                html += '</div>';

                picker.innerHTML = html;

                // Insert after the add button
                var tagsRow = document.getElementById('propTags_' + propertyId);
                tagsRow.appendChild(picker);
                activeTagPicker = picker;
              };

              window.onTagSelectChange = function(propertyId) {
                var sel = document.getElementById('tagSelect_' + propertyId);
                var valInput = document.getElementById('tagValue_' + propertyId);
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-has-value') === '1') {
                  valInput.style.display = '';
                  valInput.focus();
                } else {
                  valInput.style.display = 'none';
                  valInput.value = '';
                }
              };

              window.closeTagPicker = function() {
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }
              };

              window.applyPropertyTag = function(propertyId) {
                var sel = document.getElementById('tagSelect_' + propertyId);
                var valInput = document.getElementById('tagValue_' + propertyId);
                var tagId = parseInt(sel.value);
                if (!tagId) { alert('Please select a tag'); return; }

                var tagValue = valInput.value.trim() || null;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'apply',
                    entity_type: 'property',
                    entity_id: propertyId,
                    tag_id: tagId,
                    tag_value: tagValue,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                  }
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              };

              window.removePropertyTag = function(propertyId, entityTagId, btn) {
                if (!confirm('Remove this tag?')) return;
                btn.disabled = true;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'remove',
                    entity_tag_id: entityTagId,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    // Remove the tag badge from DOM
                    var tagBadge = btn.parentElement;
                    tagBadge.remove();
                  } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                  }
                })
                .catch(function(err) {
                  alert('Error: ' + err.message);
                  btn.disabled = false;
                });
              };

              // Close tag picker on outside click
              document.addEventListener('click', function() {
                if (activeTagPicker) {
                  activeTagPicker.remove();
                  activeTagPicker = null;
                }
              });

              // ── Print Billing Statement ──────────────────────────
              function printStatement(contactId) {
                window.print();
              }

              // ── Add Client Note ──────────────────────────────────
              function addClientNote() {
                var noteType = document.getElementById('noteType').value;
                var noteContent = document.getElementById('noteContent').value.trim();
                if (!noteContent) { alert('Please enter a note.'); return; }

                fetch('clients_appstack.php?action=add_client_note', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: CONTACT_ID,
                    note_type: noteType,
                    content: noteContent,
                    csrf_token: CSRF_TOKEN
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) location.reload();
                  else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(function(err) { alert('Error: ' + err.message); });
              }

              // ── Init ────────────────────────────────────────────
              document.addEventListener('DOMContentLoaded', function() {
                // Wait for Google Maps to load
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                  initContactMap();
                } else {
                  // Poll until loaded
                  var attempts = 0;
                  var interval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                      clearInterval(interval);
                      initContactMap();
                    }
                    if (attempts > 50) clearInterval(interval);
                  }, 200);
                }
              });
            })();
            
