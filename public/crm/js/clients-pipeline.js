/* Moved out of public/crm/clients_appstack.php (clients refactor, no change in behaviour). PHP values arrive in window.mwClients.clients_pipeline. */
            // Kanban drag and drop functionality
            let draggedCard = null;

            function setupKanbanDragDrop() {
              const cards = document.querySelectorAll('.mw-kanban-card');
              const dropZones = document.querySelectorAll('.mw-kanban-cards');

              cards.forEach(card => {
                card.addEventListener('dragstart', handleDragStart);
                card.addEventListener('dragend', handleDragEnd);
              });

              dropZones.forEach(zone => {
                zone.addEventListener('dragover', handleDragOver);
                zone.addEventListener('drop', handleDrop);
              });
            }

            function handleDragStart(e) {
              draggedCard = this;
              this.style.opacity = '0.5';
              e.dataTransfer.effectAllowed = 'move';
            }

            function handleDragEnd(e) {
              this.style.opacity = '1';
              draggedCard = null;
            }

            function handleDragOver(e) {
              if (e.preventDefault) {
                e.preventDefault();
              }
              e.dataTransfer.dropEffect = 'move';
              return false;
            }

            function handleDrop(e) {
              if (e.stopPropagation) {
                e.stopPropagation();
              }

              if (!draggedCard) return false;

              const targetStage = this.dataset.stage;
              const companyId = draggedCard.dataset.companyId ? parseInt(draggedCard.dataset.companyId) : null;
              const contactId = draggedCard.dataset.contactId ? parseInt(draggedCard.dataset.contactId) : null;

              // Move card to new column
              this.appendChild(draggedCard);

              // Update backend — company or contact
              if (companyId) {
                fetch('clients_appstack.php?action=move_company', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    company_id: companyId,
                    new_stage: targetStage,
                    csrf_token: mwClients.clients_pipeline.c0
                  })
                })
                .then(r => r.json())
                .then(data => {
                  if (!data.success) {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    location.reload();
                  }
                })
                .catch(err => {
                  alert('Error: ' + err.message);
                  location.reload();
                });
              } else if (contactId) {
                fetch('clients_appstack.php?action=move_contact', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    contact_id: contactId,
                    new_stage: targetStage,
                    csrf_token: mwClients.clients_pipeline.c0
                  })
                })
                .then(r => r.json())
                .then(data => {
                  if (!data.success) {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    location.reload();
                  }
                })
                .catch(err => {
                  alert('Error: ' + err.message);
                  location.reload();
                });
              }

              return false;
            }

            // View mode toggle
            function setupViewToggle() {
              const kanbanView = document.getElementById('kanban-view');
              const listView = document.getElementById('list-view');
              const kanbanRadio = document.getElementById('view_kanban');
              const listRadio = document.getElementById('view_list');

              if (!kanbanRadio || !listRadio) return;

              kanbanRadio.addEventListener('change', function() {
                if (this.checked) {
                  kanbanView.style.display = 'flex';
                  listView.style.display = 'none';
                }
              });

              listRadio.addEventListener('change', function() {
                if (this.checked) {
                  kanbanView.style.display = 'none';
                  listView.style.display = 'block';
                }
              });
            }

            // Stage management functions
            function showStageManagerModal() {
              const modal = document.getElementById('stageManagerModal');
              if (modal) {
                $(modal).modal('show');
              }
            }

            function handleAddStage(event) {
              event.preventDefault();

              const stageKey = document.getElementById('stageKey').value.trim().toLowerCase();
              const stageLabel = document.getElementById('stageLabel').value.trim();
              const stageOrder = parseInt(document.getElementById('stageOrder').value) || 0;
              const stageColor = document.getElementById('stageColor').value;
              const stageDescription = document.getElementById('stageDescription').value.trim();

              if (!stageKey || !stageLabel) {
                alert('Please fill in Stage Key and Stage Label');
                return;
              }

              fetch('clients_appstack.php?action=manage_stages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  stage_action: 'add',
                  stage_key: stageKey,
                  stage_label: stageLabel,
                  stage_order: stageOrder,
                  stage_color: stageColor,
                  description: stageDescription,
                  csrf_token: mwClients.clients_pipeline.c0
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Stage added! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            function editStage(stageId, label, color, order) {
              const newLabel = prompt('Edit stage label:', label);
              if (!newLabel) return;

              fetch('clients_appstack.php?action=manage_stages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  stage_action: 'update',
                  stage_id: stageId,
                  stage_label: newLabel,
                  stage_color: color,
                  stage_order: order,
                  csrf_token: mwClients.clients_pipeline.c0
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Stage updated! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            function deleteStage(stageId, label) {
              if (!confirm('Delete stage "' + label + '"? This action cannot be undone and the stage must not be in use.')) {
                return;
              }

              fetch('clients_appstack.php?action=manage_stages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  stage_action: 'delete',
                  stage_id: stageId,
                  csrf_token: mwClients.clients_pipeline.c0
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Stage deleted! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // ── Google Places Address Autocomplete ──
            function initAddressAutocomplete(inputId, cityId, postalId, provinceId, latId, lngId) {
              var input = document.getElementById(inputId);
              if (!input) return;
              if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                setTimeout(function() { initAddressAutocomplete(inputId, cityId, postalId, provinceId, latId, lngId); }, 200);
                return;
              }
              var ac = new google.maps.places.Autocomplete(input, {
                types: ['address'],
                componentRestrictions: { country: ['ca'] },
                fields: ['address_components', 'geometry', 'formatted_address']
              });
              ac.addListener('place_changed', function() {
                var place = ac.getPlace();
                if (!place || !place.geometry) return;
                var street = '', city = '', postal = '', province = '';
                if (place.address_components) {
                  for (var i = 0; i < place.address_components.length; i++) {
                    var c = place.address_components[i];
                    if (c.types.indexOf('street_number') !== -1) street = c.long_name + ' ' + street;
                    if (c.types.indexOf('route') !== -1) street = street + c.long_name;
                    if (c.types.indexOf('locality') !== -1) city = c.long_name;
                    if (c.types.indexOf('postal_code') !== -1) postal = c.long_name;
                    if (c.types.indexOf('administrative_area_level_1') !== -1) province = c.short_name;
                  }
                }
                if (street.trim()) input.value = street.trim();
                var cityEl = document.getElementById(cityId);
                if (cityEl && city) cityEl.value = city;
                var postalEl = document.getElementById(postalId);
                if (postalEl && postal) postalEl.value = postal;
                if (provinceId) {
                  var provEl = document.getElementById(provinceId);
                  if (provEl && province) provEl.value = province;
                }
                if (latId) {
                  var latEl = document.getElementById(latId);
                  if (latEl) latEl.value = place.geometry.location.lat();
                }
                if (lngId) {
                  var lngEl = document.getElementById(lngId);
                  if (lngEl) lngEl.value = place.geometry.location.lng();
                }
                // Check for duplicate property address after selection
                if (inputId === 'propertyAddress' || inputId === 'propAddress' || inputId === 'compPropAddress') {
                  checkDuplicatePropertyAddress(input.value.trim(), inputId);
                }
              });
            }

            // ── Duplicate property address check ──
            function checkDuplicatePropertyAddress(address, sourceInputId) {
              if (!address) return;
              fetch('api/check-address.php?address=' + encodeURIComponent(address))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  var warningId = sourceInputId === 'propAddress' ? 'propAddressDupeWarning' :
                                  sourceInputId === 'compPropAddress' ? 'compPropAddressDupeWarning' : 'propertyAddressDupeWarning';
                  var warningEl = document.getElementById(warningId);
                  if (!warningEl) return;
                  if (data.exists) {
                    warningEl.innerHTML = '<i data-feather="alert-triangle" class="mw-icon-xs"></i> <strong>Address already exists</strong> — linked to ' +
                      (data.contact_name ? data.contact_name : 'an existing property') +
                      (data.property_id ? ' (Property #' + data.property_id + ')' : '') +
                      '. Saving will link this contact to the existing property.';
                    warningEl.style.display = 'block';
                    if (typeof feather !== 'undefined') feather.replace();
                  } else {
                    warningEl.style.display = 'none';
                  }
                })
                .catch(function() {});
            }

            // Client search functionality
            function setupClientSearch() {
              var searchInput = document.getElementById('mw-client-search');
              var searchClear = document.getElementById('mw-search-clear');
              var searchCount = document.getElementById('mw-search-count');
              if (!searchInput) return;

              searchInput.addEventListener('input', function() {
                var query = this.value.toLowerCase().trim();
                searchClear.style.display = query ? 'flex' : 'none';

                // Filter list view rows
                var rows = document.querySelectorAll('.mw-client-row');
                var visible = 0;
                rows.forEach(function(row) {
                  var data = row.getAttribute('data-search') || '';
                  var props = (row.getAttribute('data-props') || '').split('|').filter(Boolean);
                  var propHit = '';
                  if (query) {
                    for (var i = 0; i < props.length; i++) {
                      if (props[i].indexOf(query) !== -1) { propHit = props[i]; break; }
                    }
                  }
                  var match = !query || data.indexOf(query) !== -1 || propHit !== '';
                  row.style.display = match ? '' : 'none';
                  var note = row.querySelector('.mw-prop-match');
                  if (note) {
                    note.textContent = propHit ? 'Property: ' + propHit : '';
                    note.classList.toggle('d-none', !propHit);
                  }
                  if (match) visible++;
                });

                // Filter kanban cards
                var cards = document.querySelectorAll('.mw-kanban-card');
                cards.forEach(function(card) {
                  var name = (card.getAttribute('data-company-name') || '').toLowerCase();
                  var text = (card.textContent || '').toLowerCase();
                  var match = !query || name.indexOf(query) !== -1 || text.indexOf(query) !== -1 ||
                    (card.getAttribute('data-props') || '').indexOf(query) !== -1;
                  card.style.display = match ? '' : 'none';
                });

                // Update kanban column counts
                document.querySelectorAll('.mw-kanban-column').forEach(function(col) {
                  var visibleCards = col.querySelectorAll('.mw-kanban-card:not([style*="display: none"])').length;
                  var badge = col.querySelector('.mw-kanban-header .badge');
                  if (badge) badge.textContent = visibleCards;
                });

                // Show/hide standalone section header when filtered
                var standaloneHeader = document.getElementById('mw-standalone-header');
                var standaloneDesc = document.getElementById('mw-standalone-desc');
                var standaloneTable = document.getElementById('mw-standalone-table');
                if (standaloneTable) {
                  var standaloneVisible = standaloneTable.querySelectorAll('.mw-client-row:not([style*="display: none"])').length;
                  var showStandalone = !query || standaloneVisible > 0;
                  if (standaloneHeader) standaloneHeader.style.display = showStandalone ? '' : 'none';
                  if (standaloneDesc) standaloneDesc.style.display = showStandalone ? '' : 'none';
                }

                // Show result count
                if (query) {
                  searchCount.textContent = visible + ' result' + (visible !== 1 ? 's' : '') + ' found';
                  searchCount.classList.remove('d-none');
                } else {
                  searchCount.classList.add('d-none');
                }
              });
            }

            function clearClientSearch() {
              var input = document.getElementById('mw-client-search');
              if (input) {
                input.value = '';
                input.dispatchEvent(new Event('input'));
                input.focus();
              }
            }

            // Company toggle and mode switching
            document.addEventListener('DOMContentLoaded', function() {
              setupKanbanDragDrop();
              setupViewToggle();
              setupClientSearch();

              // Initialize address autocomplete for create form
              initAddressAutocomplete('propertyAddress', 'propertyCity', 'propertyPostalCode', null, 'propertyLatitude', 'propertyLongitude');
              initAddressAutocomplete('billingAddress', 'billingCity', 'billingPostalCode', 'billingProvince', null, null);
              // For Add Property modal
              initAddressAutocomplete('propAddress', 'propCity', 'propPostalCode', null, null, null);
              // For Edit Property modal
              initAddressAutocomplete('editPropAddress', 'editPropCity', 'editPropPostal', 'editPropProvince', null, null);

              // Company link toggle
              const linkToggle = document.getElementById('linkCompanyToggle');
              const companySection = document.getElementById('companySection');
              const existingRadio = document.getElementById('company_mode_existing');
              const newRadio = document.getElementById('company_mode_new');
              const existingSection = document.getElementById('existing-company-section');
              const newSection = document.getElementById('new-company-section');

              if (linkToggle) {
                function toggleCompanySection() {
                  companySection.style.display = linkToggle.checked ? 'block' : 'none';
                }
                linkToggle.addEventListener('change', toggleCompanySection);
                toggleCompanySection(); // apply initial state
              }

              if (existingRadio && newRadio) {
                function updateCompanyMode() {
                  if (existingRadio.checked) {
                    existingSection.style.display = 'block';
                    newSection.style.display = 'none';
                  } else {
                    existingSection.style.display = 'none';
                    newSection.style.display = 'block';
                  }
                }
                existingRadio.addEventListener('change', updateCompanyMode);
                newRadio.addEventListener('change', updateCompanyMode);
                updateCompanyMode(); // apply initial state
              }
            });

            function convertToProspect(requestId, contactName) {
              if (!confirm('Convert "' + contactName + '" to a prospect client?')) return;

              fetch('clients_appstack.php?action=convert_to_prospect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  request_id: requestId,
                  csrf_token: mwClients.clients_pipeline.c0
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  alert('Prospect created! Reloading...');
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(err => alert('Error: ' + err.message));
            }

            // ── Bulk Delete ──────────────────────────────────────
            var mwClientsBulkSelected = new Set();

            // Select-all checkbox
            var clientsSelectAll = document.getElementById('mw-clients-select-all');
            if (clientsSelectAll) {
              clientsSelectAll.addEventListener('change', function() {
                var checked = this.checked;
                document.querySelectorAll('#list-view .mw-bulk-row-select').forEach(function(cb) {
                  cb.checked = checked;
                  var id = parseInt(cb.dataset.id);
                  if (checked) {
                    mwClientsBulkSelected.add(id);
                  } else {
                    mwClientsBulkSelected.delete(id);
                  }
                });
                mwClientsBulkUpdateBar();
              });
            }

            // Individual row checkbox
            document.addEventListener('change', function(e) {
              if (e.target.classList.contains('mw-bulk-row-select') && e.target.closest('#list-view')) {
                var id = parseInt(e.target.dataset.id);
                if (e.target.checked) {
                  mwClientsBulkSelected.add(id);
                } else {
                  mwClientsBulkSelected.delete(id);
                  if (clientsSelectAll) clientsSelectAll.checked = false;
                }
                mwClientsBulkUpdateBar();
              }
            });

            function mwClientsBulkUpdateBar() {
              var bar = document.getElementById('mw-clients-bulk-bar');
              var count = document.getElementById('mw-clients-bulk-count');
              if (!bar || !count) return;
              count.textContent = mwClientsBulkSelected.size;
              if (mwClientsBulkSelected.size > 0) {
                bar.classList.add('mw-bulk-visible');
              } else {
                bar.classList.remove('mw-bulk-visible');
              }
            }

            function mwBulkClearClients() {
              mwClientsBulkSelected.clear();
              document.querySelectorAll('#list-view .mw-bulk-row-select').forEach(function(cb) { cb.checked = false; });
              if (clientsSelectAll) clientsSelectAll.checked = false;
              mwClientsBulkUpdateBar();
            }

            function mwBulkDeleteClients() {
              var count = mwClientsBulkSelected.size;
              if (count === 0) return;

              var response = prompt(
                'WARNING: Permanently delete ' + count + ' client(s)?\n\n' +
                'Clients with any quote, job or invoice are skipped — archive those instead.\n\n' +
                'This is IRREVERSIBLE. Type DELETE to confirm:'
              );
              if (response !== 'DELETE') return;

              fetch('?action=bulk_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  ids: Array.from(mwClientsBulkSelected),
                  csrf_token: mwClients.clients_pipeline.c1
                })
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.success) {
                  var msg = data.deleted_count + ' client(s) deleted.';
                  if (data.skipped_count > 0) msg += '\n' + data.skipped_count + ' skipped: they have quotes, jobs or invoices (archive them instead).';
                  alert(msg);
                  location.reload();
                } else {
                  alert('Error: ' + (data.error || 'Unknown error'));
                }
              })
              .catch(function(err) { alert('Error: ' + err.message); });
            }

            // ── Merge Duplicate Contacts ──────────────────────────────

            var mergeKeepId = null;
            var mergeMergeId = null;
            var mergeContactA = null;
            var mergeContactB = null;

            function showMergeModal(contactId) {
              mergeKeepId = null;
              mergeMergeId = null;
              document.getElementById('mergeModalBody').innerHTML =
                '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Loading contact details...</p></div>';
              document.getElementById('mergeModalFooter').style.display = 'none';
              $('#mergeContactModal').modal('show');

              fetch('api/contact-duplicates.php?action=pair&id=' + contactId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                  if (!data.success || !data.contact || !data.duplicates.length) {
                    document.getElementById('mergeModalBody').innerHTML =
                      '<div class="text-center py-4"><p class="text-muted">No duplicates found for this contact.</p></div>';
                    return;
                  }

                  mergeContactA = data.contact;
                  mergeContactB = data.duplicates[0]; // First duplicate
                  mergeKeepId = parseInt(mergeContactA.id);
                  mergeMergeId = parseInt(mergeContactB.id);

                  renderMergeComparison();
                  document.getElementById('mergeModalFooter').style.display = '';
                  feather.replace();
                })
                .catch(function(err) {
                  document.getElementById('mergeModalBody').innerHTML =
                    '<div class="alert alert-danger">Failed to load contact details: ' + err.message + '</div>';
                });
            }

            function renderMergeComparison() {
              var a = mergeContactA;
              var b = mergeContactB;
              var fields = [
                { key: 'first_name', label: 'First Name' },
                { key: 'last_name', label: 'Last Name' },
                { key: 'email', label: 'Email' },
                { key: 'phone', label: 'Phone' },
                { key: 'mobile', label: 'Mobile' },
                { key: 'preferred_contact_method', label: 'Preferred Contact' }
              ];

              var html = '<div class="mb-3">';
              html += '<div class="d-flex justify-content-between align-items-center mb-3">';
              html += '<div><small class="text-muted">Select which value to keep for each field. The "merge" contact will be deactivated.</small></div>';
              html += '<button type="button" class="btn btn-sm mw-merge-swap-btn" onclick="swapMergeSides()">';
              html += '<i data-feather="refresh-cw" style="width: 14px; height: 14px;"></i> Swap Sides</button>';
              html += '</div>';

              // Duplicate selector if multiple duplicates
              html += '<table class="mw-merge-table">';
              html += '<thead><tr>';
              html += '<th class="mw-merge-field-label">Field</th>';
              html += '<th class="mw-merge-radio-cell" style="background: #d4edda;">Keep — #' + a.id + ' <small>(created ' + formatMergeDate(a.created_at) + ')</small></th>';
              html += '<th class="mw-merge-radio-cell" style="background: #f8d7da;">Merge — #' + b.id + ' <small>(created ' + formatMergeDate(b.created_at) + ')</small></th>';
              html += '</tr></thead><tbody>';

              fields.forEach(function(f) {
                var valA = (a[f.key] || '').toString().trim();
                var valB = (b[f.key] || '').toString().trim();
                // Default: pick the non-empty value, or the 'keep' side
                var defaultChoice = 'keep';
                if (!valA && valB) defaultChoice = 'merge';

                html += '<tr>';
                html += '<td class="mw-merge-field-label">' + f.label + '</td>';
                html += '<td class="mw-merge-radio-cell"><label>';
                html += '<input type="radio" name="merge_' + f.key + '" value="keep"' + (defaultChoice === 'keep' ? ' checked' : '') + '> ';
                html += valA ? escHtml(valA) : '<span class="mw-merge-value-empty">empty</span>';
                html += '</label></td>';
                html += '<td class="mw-merge-radio-cell"><label>';
                html += '<input type="radio" name="merge_' + f.key + '" value="merge"' + (defaultChoice === 'merge' ? ' checked' : '') + '> ';
                html += valB ? escHtml(valB) : '<span class="mw-merge-value-empty">empty</span>';
                html += '</label></td>';
                html += '</tr>';
              });

              // Notes row — special handling with append option
              var notesA = (a.notes || '').trim();
              var notesB = (b.notes || '').trim();
              html += '<tr>';
              html += '<td class="mw-merge-field-label">Notes</td>';
              html += '<td colspan="2">';
              html += '<label class="d-block mb-1"><input type="radio" name="merge_notes" value="keep" checked> Keep notes from #' + a.id;
              if (notesA) html += ': <em class="text-muted">' + escHtml(notesA.substring(0, 80)) + (notesA.length > 80 ? '...' : '') + '</em>';
              html += '</label>';
              html += '<label class="d-block mb-1"><input type="radio" name="merge_notes" value="merge"> Use notes from #' + b.id;
              if (notesB) html += ': <em class="text-muted">' + escHtml(notesB.substring(0, 80)) + (notesB.length > 80 ? '...' : '') + '</em>';
              html += '</label>';
              if (notesA || notesB) {
                html += '<label class="d-block"><input type="radio" name="merge_notes" value="append"> Append both notes together</label>';
              }
              html += '</td>';
              html += '</tr>';

              html += '</tbody></table>';

              // Show related data summary
              html += '<div class="row mt-3">';
              html += '<div class="col-md-6"><div class="card"><div class="card-body p-2">';
              html += '<small class="font-weight-bold">Contact #' + a.id + ' links:</small><br>';
              html += '<small>Properties: ' + (a.property_addresses || 'none') + '</small><br>';
              html += '<small>Quote requests: ' + (a.quote_request_count || 0) + '</small>';
              html += '</div></div></div>';
              html += '<div class="col-md-6"><div class="card"><div class="card-body p-2">';
              html += '<small class="font-weight-bold">Contact #' + b.id + ' links:</small><br>';
              html += '<small>Properties: ' + (b.property_addresses || 'none') + '</small><br>';
              html += '<small>Quote requests: ' + (b.quote_request_count || 0) + '</small>';
              html += '</div></div></div>';
              html += '</div>';

              html += '<div class="alert alert-info mt-3 mb-0"><small><strong>What happens on merge:</strong> All quote requests, properties, notes, and other records from the "Merge" contact will be reassigned to the "Keep" contact. The merged contact will be deactivated.</small></div>';

              html += '</div>';

              document.getElementById('mergeModalBody').innerHTML = html;
            }

            function swapMergeSides() {
              var tmp = mergeContactA;
              mergeContactA = mergeContactB;
              mergeContactB = tmp;
              mergeKeepId = parseInt(mergeContactA.id);
              mergeMergeId = parseInt(mergeContactB.id);
              renderMergeComparison();
              feather.replace();
            }

            function executeMerge() {
              if (!mergeKeepId || !mergeMergeId) return;

              var fields = {};
              var fieldKeys = ['first_name', 'last_name', 'email', 'phone', 'mobile', 'preferred_contact_method', 'notes'];
              fieldKeys.forEach(function(key) {
                var radios = document.querySelectorAll('input[name="merge_' + key + '"]');
                radios.forEach(function(r) {
                  if (r.checked) fields[key] = r.value;
                });
              });

              if (!confirm('Are you sure you want to merge contact #' + mergeMergeId + ' into contact #' + mergeKeepId + '? This cannot be undone.')) {
                return;
              }

              document.getElementById('mergeConfirmBtn').disabled = true;
              document.getElementById('mergeConfirmBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Merging...';

              fetch('api/contact-duplicates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  action: 'merge',
                  keep_id: mergeKeepId,
                  merge_id: mergeMergeId,
                  fields: fields,
                  csrf_token: mwClients.clients_pipeline.c0
                })
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.success) {
                  alert('Contacts merged.');
                  location.reload();
                } else {
                  alert('Couldn\'t merge these contacts. ' + (data.error || 'Try again.'));
                  document.getElementById('mergeConfirmBtn').disabled = false;
                  document.getElementById('mergeConfirmBtn').innerHTML = '<i data-feather="git-merge" style="width: 14px; height: 14px;"></i> Merge Contacts';
                  feather.replace();
                }
              })
              .catch(function(err) {
                alert('Error: ' + err.message);
                document.getElementById('mergeConfirmBtn').disabled = false;
                document.getElementById('mergeConfirmBtn').innerHTML = '<i data-feather="git-merge" style="width: 14px; height: 14px;"></i> Merge Contacts';
                feather.replace();
              });
            }

            function formatMergeDate(dateStr) {
              if (!dateStr) return '—';
              var d = new Date(dateStr);
              return d.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
            }

            function escHtml(str) {
              var div = document.createElement('div');
              div.appendChild(document.createTextNode(str));
              return div.innerHTML;
            }
          
