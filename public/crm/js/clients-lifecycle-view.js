/* Moved out of public/crm/includes/clients/view-contact.php (clients refactor, no change in behaviour). PHP values arrive in window.mwClients.clients_lifecycle_view. */
                (function() {
                  var KEY = 'clientLifecycleViewMode';
                  var saved = localStorage.getItem(KEY) || 'accordion';
                  var views = ['accordion', 'tabs', 'table'];

                  function showView(mode) {
                    views.forEach(function(v) {
                      var el = document.getElementById('lifecycle-view-' + v);
                      if (el) el.style.display = v === mode ? 'block' : 'none';
                    });
                    var btns = document.querySelectorAll('#lifecycleViewToggle .mw-lifecycle-view-btn');
                    for (var i = 0; i < btns.length; i++) {
                      if (btns[i].getAttribute('data-view') === mode) {
                        btns[i].classList.add('active');
                      } else {
                        btns[i].classList.remove('active');
                      }
                    }
                    localStorage.setItem(KEY, mode);
                    if (typeof feather !== 'undefined') feather.replace();
                  }

                  // Apply saved view on load
                  if (views.indexOf(saved) !== -1) {
                    showView(saved);
                  }

                  // Bind click handlers
                  var btns = document.querySelectorAll('#lifecycleViewToggle .mw-lifecycle-view-btn');
                  for (var i = 0; i < btns.length; i++) {
                    btns[i].addEventListener('click', function(e) {
                      e.preventDefault();
                      showView(this.getAttribute('data-view'));
                    });
                  }
                })();
                
