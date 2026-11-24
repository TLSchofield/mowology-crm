/* Moved out of public/crm/includes/clients/view-company.php (clients refactor, no change in behaviour). PHP values arrive in window.mwClients.clients_company. */
            (function() {
              var COMPANY_ID = mwClients.clients_company.c1;
              var CSRF_TOKEN_CO = mwClients.clients_company.c0;
              var companyPropertiesData = mwClients.clients_company.c2;
              var companyAvailableTags = mwClients.clients_company.c3;
              var compGmap = null;
              var compMarkers = {};
              var compActiveTagPicker = null;

              // ── Utility ─────────────────────────────────────────────
              function escHtmlCo(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
              }

              // ── Google Places autocomplete ──────────────────────────
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof initAddressAutocomplete === 'function') {
                  initAddressAutocomplete('compPropAddress', 'compPropCity', 'compPropPostalCode', null, 'compPropLat', 'compPropLng');
                }
              });

              // ── Add Property ────────────────────────────────────────
              window.addPropertyToCompany = function(event) {
                event.preventDefault();
                var address = document.getElementById('compPropAddress').value.trim();
                if (!address) { alert('Please enter a street address'); return; }

                var btn = document.getElementById('addCompPropBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

                fetch('clients_appstack.php?action=add_property_to_company', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    company_id: COMPANY_ID,
                    address: address,
                    city: document.getElementById('compPropCity').value.trim() || 'Vancouver',
                    postal_code: document.getElementById('compPropPostalCode').value.trim(),
                    property_name: document.getElementById('compPropName').value.trim(),
                    latitude: document.getElementById('compPropLat').value || '',
                    longitude: document.getElementById('compPropLng').value || '',
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
                    location.reload();
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

              // ── Map ─────────────────────────────────────────────────
              function initCompanyMap() {
                var mapEl = document.getElementById('companyMapContainer');
                if (!mapEl) return;
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') return;

                var bounds = new google.maps.LatLngBounds();
                var hasMarkers = false;

                compGmap = new google.maps.Map(mapEl, {
                  gestureHandling: 'greedy',
                  zoom: 12,
                  center: { lat: 49.2827, lng: -123.1207 },
                  mapTypeId: google.maps.MapTypeId.ROADMAP,
                  mapTypeControl: false,
                  streetViewControl: false
                });

                companyPropertiesData.forEach(function(prop) {
                  var lat = parseFloat(prop.latitude);
                  var lng = parseFloat(prop.longitude);
                  if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

                  var pos = { lat: lat, lng: lng };
                  var marker = new google.maps.Marker({
                    position: pos,
                    map: compGmap,
                    title: prop.address
                  });

                  var infoContent = '<div style="max-width: 250px;">' +
                    '<strong>' + escHtmlCo(prop.address) + '</strong><br>' +
                    '<small>' + escHtmlCo((prop.city || '') + (prop.province ? ', ' + prop.province : '') + ' ' + (prop.postal_code || '')) + '</small>' +
                    '</div>';
                  var infoWindow = new google.maps.InfoWindow({ content: infoContent });
                  marker.addListener('click', function() { infoWindow.open(compGmap, marker); });

                  compMarkers[prop.id] = marker;
                  bounds.extend(pos);
                  hasMarkers = true;
                });

                if (hasMarkers) {
                  compGmap.fitBounds(bounds);
                  if (Object.keys(compMarkers).length === 1) {
                    google.maps.event.addListenerOnce(compGmap, 'bounds_changed', function() {
                      compGmap.setZoom(15);
                    });
                  }
                }
              }

              window.focusCompanyProperty = function(propertyId) {
                if (!compGmap || !compMarkers[propertyId]) return;
                var marker = compMarkers[propertyId];
                compGmap.panTo(marker.getPosition());
                compGmap.setZoom(16);
                google.maps.event.trigger(marker, 'click');
              };

              // Init map on DOMContentLoaded
              document.addEventListener('DOMContentLoaded', function() {
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                  initCompanyMap();
                } else {
                  var attempts = 0;
                  var interval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                      clearInterval(interval);
                      initCompanyMap();
                    }
                    if (attempts > 50) clearInterval(interval);
                  }, 200);
                }
              });

              // ── Geocoding ───────────────────────────────────────────
              var compGeocoder = null;

              function geocodeCompanyPropertyAjax(propertyId) {
                if (!compGeocoder) compGeocoder = new google.maps.Geocoder();
                var prop = companyPropertiesData.find(function(p) { return p.id == propertyId; });
                if (!prop) return Promise.reject(new Error('Property not found'));

                var parts = [prop.address, prop.city, prop.province, prop.postal_code, 'Canada'].filter(Boolean);
                var address = parts.join(', ');

                return new Promise(function(resolve, reject) {
                  compGeocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK' && results[0]) {
                      var loc = results[0].geometry.location;
                      fetch('clients_appstack.php?action=save_property_coords', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                          property_id: propertyId,
                          lat: loc.lat(),
                          lng: loc.lng(),
                          csrf_token: CSRF_TOKEN_CO
                        })
                      })
                      .then(function(r) { return r.json(); })
                      .then(function(data) { resolve(data); })
                      .catch(function(err) { reject(err); });
                    } else {
                      resolve({ success: false, error: 'Geocode failed: ' + status });
                    }
                  });
                });
              }

              window.geocodeCompanyProperty = function(propertyId, btn) {
                if (btn) {
                  btn.disabled = true;
                  btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                }
                geocodeCompanyPropertyAjax(propertyId)
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

              window.geocodeAllCompanyProperties = function() {
                var toGeocode = companyPropertiesData.filter(function(p) { return !parseFloat(p.latitude) || !parseFloat(p.longitude); });
                if (toGeocode.length === 0) return;

                var chain = Promise.resolve();
                toGeocode.forEach(function(p) {
                  chain = chain.then(function() {
                    return geocodeCompanyPropertyAjax(p.id);
                  }).then(function() {
                    return new Promise(function(resolve) { setTimeout(resolve, 300); });
                  });
                });
                chain.then(function() { location.reload(); });
              };

              // ── Property Tags ───────────────────────────────────────
              window.showCompanyTagPicker = function(propertyId, btn) {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }

                var picker = document.createElement('div');
                picker.className = 'mw-tag-picker-dropdown';
                picker.onclick = function(e) { e.stopPropagation(); };

                var html = '<div class="mw-tag-picker-inner">';
                html += '<select class="mw-tag-picker-select" id="compTagSelect_' + propertyId + '" onchange="onCompanyTagSelectChange(' + propertyId + ')">';
                html += '<option value="">Select tag…</option>';
                for (var i = 0; i < companyAvailableTags.length; i++) {
                  html += '<option value="' + companyAvailableTags[i].tag_id + '" data-has-value="' + companyAvailableTags[i].has_value + '">' +
                          escHtmlCo(companyAvailableTags[i].tag_label) + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="mw-tag-picker-value" id="compTagValue_' + propertyId + '" placeholder="Value…" style="display:none;">';
                html += '<button type="button" class="mw-tag-picker-save" onclick="applyCompanyPropertyTag(' + propertyId + ')">Save</button>';
                html += '<button type="button" class="mw-tag-picker-cancel" onclick="closeCompanyTagPicker()">&times;</button>';
                html += '</div>';

                picker.innerHTML = html;
                var tagsRow = document.getElementById('compPropTags_' + propertyId);
                tagsRow.appendChild(picker);
                compActiveTagPicker = picker;
              };

              window.onCompanyTagSelectChange = function(propertyId) {
                var sel = document.getElementById('compTagSelect_' + propertyId);
                var valInput = document.getElementById('compTagValue_' + propertyId);
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.getAttribute('data-has-value') === '1') {
                  valInput.style.display = '';
                  valInput.focus();
                } else {
                  valInput.style.display = 'none';
                  valInput.value = '';
                }
              };

              window.closeCompanyTagPicker = function() {
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }
              };

              window.applyCompanyPropertyTag = function(propertyId) {
                var sel = document.getElementById('compTagSelect_' + propertyId);
                var valInput = document.getElementById('compTagValue_' + propertyId);
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
                    csrf_token: CSRF_TOKEN_CO
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

              window.removeCompanyPropertyTag = function(propertyId, entityTagId, btn) {
                if (!confirm('Remove this tag?')) return;
                btn.disabled = true;

                fetch('/crm/api/tags.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    action: 'remove',
                    entity_tag_id: entityTagId,
                    csrf_token: CSRF_TOKEN_CO
                  })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (data.success) {
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
                if (compActiveTagPicker) {
                  compActiveTagPicker.remove();
                  compActiveTagPicker = null;
                }
              });

              // ── Unlink / Reassign Property ──────────────────────────
              window.showUnlinkCompanyProperty = function(propertyId, address, currentContactId) {
                document.getElementById('unlinkCompPropertyId').value = propertyId;
                document.getElementById('unlinkCompCurrentContactId').value = currentContactId;
                document.getElementById('unlinkCompPropAddress').textContent = address;
                document.getElementById('reassignCompContactSelect').value = '';
                document.querySelector('input[name="unlinkCompAction"][value="unlink"]').checked = true;
                document.getElementById('reassignCompContactRow').style.display = 'none';
                $('#unlinkCompanyPropertyModal').modal('show');
              };

              // Toggle reassign dropdown visibility
              document.querySelectorAll('input[name="unlinkCompAction"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                  document.getElementById('reassignCompContactRow').style.display =
                    this.value === 'reassign' ? 'block' : 'none';
                });
              });

              window.unlinkCompanyProperty = function() {
                var propertyId = parseInt(document.getElementById('unlinkCompPropertyId').value);
                var currentContactId = parseInt(document.getElementById('unlinkCompCurrentContactId').value);
                var action = document.querySelector('input[name="unlinkCompAction"]:checked').value;
                var newContactId = 0;

                if (action === 'reassign') {
                  newContactId = parseInt(document.getElementById('reassignCompContactSelect').value);
                  if (!newContactId) {
                    alert('Please select a contact to reassign to.');
                    return;
                  }
                }

                var btn = document.getElementById('confirmUnlinkCompBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';

                fetch('clients_appstack.php?action=unlink_property', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    property_id: propertyId,
                    current_contact_id: currentContactId,
                    new_contact_id: newContactId,
                    csrf_token: CSRF_TOKEN_CO
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

            })();
            
