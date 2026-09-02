/**
 * Location-Aware Job Creation Component
 * /crm/jobs/location-job-creation.js
 */

const LocationJobCreation = {
  template: `
    <div class="mw-job-creation-mobile">
      <!-- SCREEN 1: Get Location or Manual Entry -->
      <div v-if="screen === 'location'" class="mw-screen">
        <div class="mw-header">
          <h2>Create Job</h2>
          <p class="text-muted">Use your location to find nearby properties</p>
        </div>

        <div class="mw-location-input">
          <button
            @click="requestLocation"
            :disabled="locationLoading"
            class="btn btn-primary btn-lg btn-block"
          >
            <i v-if="locationLoading" class="spinner-border spinner-border-sm mr-2"></i>
            <i v-else data-feather="map-pin" class="mr-2"></i>
            {{ locationLoading ? 'Getting your location...' : 'Use My Location' }}
          </button>

          <div class="mw-divider">OR</div>

          <div class="form-group">
            <label>Enter Address</label>
            <input
              v-model="manualAddress"
              type="text"
              placeholder="123 Main St, Vancouver"
              class="form-control form-control-lg"
              @keyup.enter="searchByAddress"
            />
            <button @click="searchByAddress" class="btn btn-outline-primary btn-block mt-2">
              Search Address
            </button>
          </div>
        </div>

        <div v-if="errorMessage" class="alert alert-danger mt-3">
          {{ errorMessage }}
        </div>

        <small class="text-muted d-block mt-4">
          <i data-feather="info" style="width: 14px; height: 14px;"></i>
          Location will be used to suggest nearby properties
        </small>
      </div>

      <!-- SCREEN 2: Select Nearby Property or Create New -->
      <div v-if="screen === 'select_property'" class="mw-screen">
        <div class="mw-header">
          <button @click="screen = 'location'" class="btn btn-sm btn-link">
            <i data-feather="arrow-left"></i> Back
          </button>
          <h2>Select Property</h2>
          <p class="text-muted">{{ nearbyProperties.length }} properties nearby</p>
        </div>

        <!-- Suggested Properties -->
        <div class="mw-property-list">
          <div
            v-for="prop in nearbyProperties"
            :key="prop.id"
            @click="selectProperty(prop)"
            class="mw-property-item"
          >
            <div class="mw-property-distance">
              <span class="badge badge-info">{{ (prop.distance_km * 1000).toFixed(0) }}m</span>
            </div>
            <div class="mw-property-info">
              <strong>{{ prop.address }}</strong>
              <small class="d-block text-muted">{{ prop.company_name }} • {{ prop.company_type }}</small>
              <small v-if="prop.suggested_package" class="d-block badge badge-success">
                Last: {{ prop.suggested_package.package_name }}
              </small>
              <small v-if="prop.job_count" class="d-block text-muted">
                {{ prop.job_count }} past jobs
              </small>
            </div>
            <div class="mw-property-arrow">
              <i data-feather="chevron-right"></i>
            </div>
          </div>
        </div>

        <!-- Create New Property Option -->
        <div class="mw-create-new mt-4">
          <button
            @click="screen = 'create_property'"
            class="btn btn-outline-secondary btn-block"
          >
            <i data-feather="plus"></i> Create New Property
          </button>
        </div>
      </div>

      <!-- SCREEN 3: Create New Property -->
      <div v-if="screen === 'create_property'" class="mw-screen">
        <div class="mw-header">
          <button @click="screen = 'select_property'" class="btn btn-sm btn-link">
            <i data-feather="arrow-left"></i> Back
          </button>
          <h2>New Property</h2>
        </div>

        <form @submit.prevent="submitNewProperty" class="mw-form">
          <div class="form-group">
            <label>Client *</label>
            <div class="mw-typeahead">
              <input
                v-model="newProperty.clientSearch"
                @input="searchClients"
                type="text"
                placeholder="Search or select client"
                class="form-control"
                required
              />
              <div v-if="clientSuggestions.length" class="mw-suggestions">
                <div
                  v-for="client in clientSuggestions"
                  :key="client.id"
                  @click="selectClient(client)"
                  class="mw-suggestion-item"
                >
                  {{ client.company_name }}
                </div>
              </div>
            </div>
            <small v-if="selectedClient" class="d-block mt-2 text-success">
              ✓ {{ selectedClient.company_name }}
            </small>
          </div>

          <div class="form-group">
            <label>Address *</label>
            <input
              v-model="newProperty.address"
              type="text"
              :placeholder="reverseGeocodedAddress || 'Enter full address'"
              class="form-control"
              required
            />
          </div>

          <div class="form-group">
            <label>Property Type</label>
            <select v-model="newProperty.propertyType" class="form-control">
              <option value="residential">Residential</option>
              <option value="strata">Strata/Condo</option>
              <option value="commercial">Commercial</option>
            </select>
          </div>

          <div class="form-group">
            <label>Access Notes (optional)</label>
            <textarea
              v-model="newProperty.accessNotes"
              placeholder="e.g., Gate code 1234, side gate"
              class="form-control"
              rows="2"
            ></textarea>
          </div>

          <button
            type="submit"
            :disabled="!selectedClient || !newProperty.address || creatingProperty"
            class="btn btn-primary btn-lg btn-block"
          >
            <i v-if="creatingProperty" class="spinner-border spinner-border-sm mr-2"></i>
            {{ creatingProperty ? 'Creating...' : 'Create Property' }}
          </button>
        </form>
      </div>

      <!-- SCREEN 4: Select Service or Recent Job -->
      <div v-if="screen === 'service_selection'" class="mw-screen">
        <div class="mw-header">
          <button @click="resetFlow" class="btn btn-sm btn-link">
            <i data-feather="arrow-left"></i> Back
          </button>
          <h2>{{ selectedProperty.company_name }}</h2>
          <p class="text-muted">{{ selectedProperty.address }}</p>
        </div>

        <!-- Quick Repeat Service -->
        <div v-if="recentJobs.length" class="mw-recent-jobs mb-4">
          <h6><strong>Quick Repeat</strong></h6>
          <div class="mw-quick-repeat-grid">
            <div
              v-for="job in recentJobs"
              :key="job.id"
              @click="selectServiceFromRecent(job)"
              class="mw-quick-repeat-card"
            >
              <strong>{{ job.package_name || job.title }}</strong>
              <small class="d-block text-muted">{{ job.default_duration_minutes || '?' }} min</small>
              <small class="d-block text-success">\${{ job.base_price }}</small>
            </div>
          </div>
        </div>

        <!-- Browse All Services -->
        <h6><strong>All Services</strong></h6>
        <div class="mw-service-grid">
          <div
            v-for="pkg in availablePackages"
            :key="pkg.id"
            @click="selectService(pkg)"
            class="mw-service-card"
          >
            <i :data-feather="pkg.icon || 'layers'" class="mw-service-icon"></i>
            <strong>{{ pkg.package_name }}</strong>
            <small class="d-block text-muted">{{ pkg.default_duration_minutes }} min</small>
            <small class="d-block text-success">\${{ pkg.base_price }}</small>
          </div>
        </div>
      </div>

      <!-- SCREEN 5: Confirm & Create Job -->
      <div v-if="screen === 'confirm_job'" class="mw-screen">
        <div class="mw-header">
          <button @click="screen = 'service_selection'" class="btn btn-sm btn-link">
            <i data-feather="arrow-left"></i> Back
          </button>
          <h2>Confirm Job</h2>
        </div>

        <div class="mw-job-summary">
          <div class="mw-summary-item">
            <strong>Client:</strong>
            {{ selectedProperty.company_name }}
          </div>
          <div class="mw-summary-item">
            <strong>Property:</strong>
            {{ selectedProperty.address }}
          </div>
          <div class="mw-summary-item">
            <strong>Service:</strong>
            {{ selectedService.package_name }}
          </div>
          <div class="mw-summary-item">
            <strong>Duration:</strong>
            {{ selectedService.default_duration_minutes }} minutes
          </div>
          <div class="mw-summary-item">
            <strong>Price:</strong>
            <span class="text-success">\${{ selectedService.base_price }}</span>
          </div>
        </div>

        <div class="mw-form-group mt-4">
          <label>Schedule</label>
          <div class="btn-group btn-group-toggle btn-block" role="group">
            <label class="btn btn-outline-secondary" :class="{ active: jobSchedule === 'now' }">
              <input type="radio" value="now" v-model="jobSchedule" />
              Now
            </label>
            <label class="btn btn-outline-secondary" :class="{ active: jobSchedule === 'later' }">
              <input type="radio" value="later" v-model="jobSchedule" />
              Later
            </label>
          </div>
        </div>

        <div v-if="jobSchedule === 'later'" class="form-group mt-3">
          <button type="button" ref="jobScheduleDateTrigger" class="mw-datepicker-trigger" aria-haspopup="true" aria-expanded="false">
            <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="mw-datepicker-date" data-mw-dp-label></span>
            <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <input v-model="jobScheduleDate" ref="jobScheduleDateInput" type="date" class="form-control" hidden required />
          <input v-model="jobScheduleTime" type="time" class="form-control mt-2" required />
        </div>

        <button
          @click="submitJob"
          :disabled="submittingJob"
          class="btn btn-success btn-lg btn-block mt-4"
        >
          <i v-if="submittingJob" class="spinner-border spinner-border-sm mr-2"></i>
          {{ submittingJob ? 'Creating...' : 'Create Job' }}
        </button>
      </div>
    </div>
  `,

  data() {
    return {
      screen: 'location',
      locationLoading: false,
      manualAddress: '',
      errorMessage: '',

      // Location data
      crewLocation: { lat: null, lng: null },
      reverseGeocodedAddress: '',
      nearbyProperties: [],

      // Selected property
      selectedProperty: null,
      selectedClient: null,
      selectedService: null,
      recentJobs: [],
      availablePackages: [],

      // New property form
      newProperty: {
        clientSearch: '',
        address: '',
        propertyType: 'residential',
        accessNotes: ''
      },
      clientSuggestions: [],
      creatingProperty: false,

      // Job creation
      jobSchedule: 'now',
      jobScheduleDate: new Date().toISOString().split('T')[0],
      jobScheduleTime: '09:00',
      submittingJob: false,

      // CSRF token (will be injected from page)
      csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || ''
    };
  },

  mounted() {
    hydrateFeatherIcons();
  },

  watch: {
    jobSchedule() {
      hydrateFeatherIcons();
      // v-if unmounts/remounts this block on every toggle, so the trigger
      // needs a fresh MwDatePicker each time it reappears — $nextTick because
      // the DOM patch (and $refs) aren't ready until after this tick.
      if (this.jobSchedule === 'later') {
        this.$nextTick(() => {
          if (window.MwDatePicker && this.$refs.jobScheduleDateTrigger && this.$refs.jobScheduleDateInput) {
            new MwDatePicker(this.$refs.jobScheduleDateTrigger, { target: this.$refs.jobScheduleDateInput });
          }
        });
      }
    }
  },

  methods: {
    requestLocation() {
      this.locationLoading = true;
      this.errorMessage = '';

      if (!navigator.geolocation) {
        this.errorMessage = 'Geolocation not supported on this device';
        this.locationLoading = false;
        return;
      }

      navigator.geolocation.getCurrentPosition(
        (position) => {
          this.crewLocation = {
            lat: position.coords.latitude,
            lng: position.coords.longitude
          };

          this.logCrewLocation();
          this.reverseGeocode();
          this.findNearbyProperties();
        },
        (error) => {
          this.errorMessage = `Location error: ${error.message}`;
          this.locationLoading = false;
        }
      );
    },

    reverseGeocode() {
      fetch('/crm/jobs/jobs_create_location_appstack.php?action=reverse_geocode', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          latitude: this.crewLocation.lat,
          longitude: this.crewLocation.lng
        })
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            this.reverseGeocodedAddress = data.address;
          }
        })
        .catch(err => console.log('Geocoding error:', err));
    },

    findNearbyProperties() {
      fetch('/crm/jobs/jobs_create_location_appstack.php?action=find_nearby_properties', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          latitude: this.crewLocation.lat,
          longitude: this.crewLocation.lng,
          radius_km: 1
        })
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            this.nearbyProperties = data.properties;
            this.screen = 'select_property';
          } else {
            this.errorMessage = data.error || 'No properties found nearby';
          }
          this.locationLoading = false;
        })
        .catch(err => {
          this.errorMessage = err.message;
          this.locationLoading = false;
        });
    },

    searchByAddress() {
      if (!this.manualAddress.trim()) return;
      // Simplified: just move to property selection
      this.screen = 'select_property';
    },

    selectProperty(property) {
      this.selectedProperty = property;

      fetch('/crm/jobs/jobs_create_location_appstack.php?action=get_property_summary', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ property_id: property.id })
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            this.recentJobs = data.recent_jobs || [];
            this.availablePackages = data.available_packages || [];
            hydrateFeatherIcons();
          }
        });

      this.screen = 'service_selection';
    },

    selectService(pkg) {
      this.selectedService = pkg;
      this.screen = 'confirm_job';
    },

    selectServiceFromRecent(job) {
      this.selectedService = {
        id: job.package_id,
        package_name: job.package_name,
        base_price: job.base_price,
        default_duration_minutes: job.default_duration_minutes
      };
      this.screen = 'confirm_job';
    },

    searchClients() {
      if (this.newProperty.clientSearch.length < 2) {
        this.clientSuggestions = [];
        return;
      }

      fetch('/crm/jobs/jobs_create_location_appstack.php?action=search_clients', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: this.newProperty.clientSearch })
      })
        .then(r => r.json())
        .then(data => {
          this.clientSuggestions = data.clients || [];
        });
    },

    selectClient(client) {
      this.selectedClient = client;
      this.newProperty.clientSearch = client.company_name;
      this.clientSuggestions = [];
    },

    submitNewProperty() {
      if (!this.selectedClient || !this.newProperty.address) return;

      this.creatingProperty = true;

      const formData = new FormData();
      formData.append('action', 'create_property_from_location');
      formData.append('csrf_token', this.csrfToken);
      formData.append('latitude', this.crewLocation.lat);
      formData.append('longitude', this.crewLocation.lng);
      formData.append('address', this.newProperty.address);
      formData.append('client_id', this.selectedClient.id);
      formData.append('property_type', this.newProperty.propertyType);

      fetch('/crm/jobs/jobs_create_location_appstack.php', {
        method: 'POST',
        body: formData
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // Re-fetch nearby properties and select the new one
            this.findNearbyProperties();
          } else {
            alert('Error: ' + data.error);
          }
          this.creatingProperty = false;
        });
    },

    submitJob() {
      if (!this.selectedProperty || !this.selectedService) return;

      this.submittingJob = true;

      const formData = new FormData();
      formData.append('action', 'create_job');
      formData.append('csrf_token', this.csrfToken);
      formData.append('property_id', this.selectedProperty.id);
      formData.append('client_id', this.selectedProperty.company_id);
      formData.append('service_package_id', this.selectedService.id);
      formData.append(
        'scheduled_date',
        this.jobSchedule === 'now'
          ? new Date().toISOString().split('T')[0]
          : this.jobScheduleDate
      );
      formData.append('latitude', this.crewLocation.lat);
      formData.append('longitude', this.crewLocation.lng);
      formData.append('billing_template_id', 1); // Default template

      fetch('/crm/jobs/jobs_create_location_appstack.php', {
        method: 'POST',
        body: formData
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            alert('Job created!');
            // Redirect to job view
            window.location.href = `/crm/jobs/jobs_view_appstack.php?id=${data.job_id}&created=1`;
          } else {
            alert('Error: ' + data.error);
          }
          this.submittingJob = false;
        });
    },

    logCrewLocation() {
      fetch('/crm/jobs/jobs_create_location_appstack.php?action=log_crew_location', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          latitude: this.crewLocation.lat,
          longitude: this.crewLocation.lng,
          accuracy_meters: 50
        })
      }).catch(err => console.log('Location logged'));
    },

    resetFlow() {
      this.screen = 'location';
      this.selectedProperty = null;
      this.selectedService = null;
      this.manualAddress = '';
    }
  }
};

// Mount Vue app
new Vue({
  el: '#job-creation-app',
  components: { LocationJobCreation },
  template: '<LocationJobCreation />'
});
