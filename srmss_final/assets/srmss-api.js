(function () {
  const API_BASE = window.SRMSS_API_BASE || './backend/api.php';
  const SRI_LANKA_BOUNDS = [[5.8, 79.4], [10.1, 82.1]];
  const state = {
    routes: [],
    drivers: [],
    vehicles: [],
    schedules: [],
    fuel_logs: [],
    maintenance_logs: [],
    active_trips: [],
    notifications: [],
    users: [],
    profile: null,
    report: null,
    profileImage: null
  };

  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    wrapOpenModal();
    wireSaveButtons();
    wireFilters();
    wireProfile();
    wireReports();
    wireNotifications();
    await refreshAll();
  }

  function wrapOpenModal() {
    const originalOpenModal = window.openModal;
    window.openModal = function (modalId) {
      if (modalId !== 'tripDetailsModal') clearModal(modalId);
      if (typeof originalOpenModal === 'function') originalOpenModal(modalId);
      else document.getElementById(modalId)?.classList.add('active');
    };
  }

  function wireSaveButtons() {
    const modalMap = {
      routeModal: { resource: 'routes', read: readRoute },
      driverModal: { resource: 'drivers', read: readDriver },
      vehicleModal: { resource: 'vehicles', read: readVehicle },
      scheduleModal: { resource: 'schedules', read: readSchedule },
      fuelModal: { resource: 'fuel_logs', read: readFuelLog },
      maintenanceModal: { resource: 'maintenance_logs', read: readMaintenanceLog },
      userModal: { resource: 'users', read: readUser }
    };

    Object.entries(modalMap).forEach(([modalId, config]) => {
      const modal = document.getElementById(modalId);
      const saveButton = modal?.querySelector('.modal-footer .btn-primary');
      if (!modal || !saveButton) return;

      saveButton.addEventListener('click', async () => {
        const id = modal.dataset.recordId;
        const payload = config.read(modal);
        if (id) payload.id = id;
        saveButton.disabled = true;
        try {
          await api(config.resource, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(payload)
          });
          closeModal(modalId);
          await refreshAll();
        } catch (error) {
          alert(error.message || 'Could not save record.');
        } finally {
          saveButton.disabled = false;
        }
      });
    });
  }

  async function refreshAll() {
    const [routes, drivers, vehicles, schedules, fuelLogs, maintenanceLogs, dashboard, trips, notifications, profile, users] = await Promise.all([
      api('routes'),
      api('drivers'),
      api('vehicles'),
      api('schedules'),
      api('fuel_logs'),
      api('maintenance_logs'),
      api('dashboard'),
      api('active_trips'),
      api('notifications'),
      api('profile'),
      api('users')
    ]);

    state.routes = routes.data || [];
    state.drivers = drivers.data || [];
    state.vehicles = vehicles.data || [];
    state.schedules = schedules.data || [];
    state.fuel_logs = fuelLogs.data || [];
    state.maintenance_logs = maintenanceLogs.data || [];
    state.active_trips = trips.data || [];
    state.notifications = notifications.data || [];
    state.profile = profile.data || null;
    state.profileImage = state.profile?.profile_image || null;
    state.users = users.data || [];

    populateSelects();
    renderAllFiltered();
    renderDashboard(dashboard.data || {});
    renderActiveTrips();
    renderNotifications();
    renderProfile();
    renderUsers();
    startLiveTracking();
  }

  async function api(resource, options = {}) {
    const response = await fetch(`${API_BASE}?resource=${resource}`, {
      headers: { 'Content-Type': 'application/json' },
      ...options
    });
    const payload = await response.json();
    if (!response.ok || payload.success === false) {
      throw new Error(payload.message || 'Request failed.');
    }
    return payload;
  }

  function controls(modal) {
    return Array.from(modal?.querySelectorAll('.modal-body input, .modal-body select, .modal-body textarea') || []);
  }

  function clearModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    delete modal.dataset.recordId;
    delete modal.dataset.recordStatus;
    controls(modal).forEach((control) => {
      if (control.type === 'file') control.value = '';
      else if (control.tagName === 'SELECT') control.selectedIndex = 0;
      else control.value = '';
    });
    populateSelects();
  }

  function readRoute(modal) {
    const c = controls(modal);
    return {
      route_code: c[0].value,
      route_name: c[1].value,
      origin: c[2].value,
      destination: c[3].value,
      stops: c[4].value,
      distance_km: c[5].value,
      route_type: c[6].value,
      status: c[7].value
    };
  }

  function readDriver(modal) {
    const c = controls(modal);
    return {
      driver_code: c[0].value,
      full_name: c[1].value,
      nic_number: c[2].value,
      contact_number: c[3].value,
      address: c[4].value,
      license_number: c[5].value,
      license_type: c[6].value,
      license_issue_date: c[7].value,
      license_expiry_date: c[8].value,
      status: c[9].value
    };
  }

  function readVehicle(modal) {
    const c = controls(modal);
    return {
      vehicle_code: c[0].value,
      license_plate: c[1].value,
      make: c[2].value,
      model: c[3].value,
      year: c[4].value,
      vehicle_type: c[5].value || c[2].value,
      seating_capacity: c[6].value,
      fuel_type: c[7].value,
      insurance_expiry: c[8].value,
      status: c[9].value
    };
  }

  function readSchedule(modal) {
    const c = controls(modal);
    return {
      schedule_code: c[0].value,
      route_id: c[1].value,
      driver_id: c[2].value,
      vehicle_id: c[3].value,
      schedule_date: c[4].value,
      schedule_type: c[5].value,
      departure_time: c[6].value,
      expected_arrival_time: c[7].value,
      remarks: c[8].value,
      status: c[9]?.value || modal.dataset.recordStatus || 'Scheduled'
    };
  }

  function readFuelLog(modal) {
    const c = controls(modal);
    return {
      vehicle_id: c[0].value,
      fuel_date: c[1].value,
      quantity_liters: c[2].value,
      fuel_cost: c[3].value,
      odometer_reading: c[4].value,
      trip_id: c[5].value
    };
  }

  function readMaintenanceLog(modal) {
    const c = controls(modal);
    return {
      vehicle_id: c[0].value,
      maintenance_date: c[1].value,
      maintenance_type: c[2].value,
      description: c[3].value,
      cost: c[4].value,
      service_center: c[5].value,
      next_service_mileage: c[6].value,
      next_service_date: c[7].value,
      maintenance_status: c[8]?.value || modal.dataset.recordStatus || 'Scheduled'
    };
  }

  function readUser(modal) {
    const c = controls(modal);
    return {
      full_name: c[0].value,
      email: c[1].value,
      phone: c[2].value,
      role: c[3].value,
      password: c[4].value,
      status: c[5].value
    };
  }

  function populateSelects() {
    const scheduleControls = controls(document.getElementById('scheduleModal'));
    const fuelControls = controls(document.getElementById('fuelModal'));
    const maintenanceControls = controls(document.getElementById('maintenanceModal'));

    setSelect(scheduleControls[1], state.routes, '-- Select Route --', (r) => `${r.route_code}: ${r.origin} - ${r.destination}`);
    setSelect(scheduleControls[2], state.drivers.filter((d) => d.status !== 'Inactive'), '-- Select Driver --', (d) => `${d.driver_code}: ${d.full_name}`);
    setSelect(scheduleControls[3], state.vehicles.filter((v) => v.status !== 'Under Maintenance'), '-- Select Vehicle --', (v) => `${v.vehicle_code}: ${v.license_plate}`);
    setSelect(fuelControls[0], state.vehicles, '-- Select Vehicle --', (v) => `${v.vehicle_code}: ${v.license_plate}`);
    setSelect(maintenanceControls[0], state.vehicles, '-- Select Vehicle --', (v) => `${v.vehicle_code}: ${v.license_plate}`);
  }

  function setSelect(select, items, placeholder, label) {
    if (!select) return;
    const current = select.value;
    select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>` + items
      .map((item) => `<option value="${item.id}">${escapeHtml(label(item))}</option>`)
      .join('');
    if (current) select.value = current;
  }

  function wireFilters() {
    ['routes', 'drivers', 'vehicles', 'schedules', 'fuel-maintenance'].forEach((section) => {
      const root = document.getElementById(`section-${section}`);
      root?.querySelectorAll('.search-input, .filter-select').forEach((control) => {
        control.addEventListener('input', renderAllFiltered);
        control.addEventListener('change', renderAllFiltered);
      });
    });
  }

  function filterFor(section, rows, fields, statusField = 'status') {
    const root = document.getElementById(`section-${section}`);
    const search = root?.querySelector('.search-input')?.value.trim().toLowerCase() || '';
    const selected = root?.querySelector('.filter-select')?.value || '';
    return rows.filter((row) => {
      const matchesSearch = !search || fields.some((field) => String(row[field] ?? '').toLowerCase().includes(search));
      const matchesStatus = !selected || selected.startsWith('All') || String(row[statusField] ?? '') === selected;
      return matchesSearch && matchesStatus;
    });
  }

  function renderAllFiltered() {
    renderRoutes(filterFor('routes', state.routes, ['route_code', 'route_name', 'origin', 'destination']));
    renderDrivers(filterFor('drivers', state.drivers, ['driver_code', 'full_name', 'license_number', 'contact_number']));
    renderVehicles(filterFor('vehicles', state.vehicles, ['vehicle_code', 'license_plate', 'make', 'model', 'vehicle_type']));
    renderSchedules(filterFor('schedules', state.schedules, ['schedule_code', 'route_name', 'origin', 'destination', 'driver_name', 'license_plate']));
    renderFuelMaintenance();
  }

  function renderRoutes(rows) {
    const tbody = document.querySelector('#section-routes table tbody');
    if (!tbody) return;
    tbody.innerHTML = rows.map((route) => `
      <tr>
        <td><strong>${escapeHtml(route.route_code)}</strong></td>
        <td>${escapeHtml(route.route_name)}</td>
        <td>${escapeHtml(route.origin)} - ${escapeHtml(route.destination)}</td>
        <td>${escapeHtml(route.distance_km || '0')} km</td>
        <td>${countStops(route.stops)}</td>
        <td>${badge(route.status)}</td>
        <td>${rowActions('routes', route.id)}</td>
      </tr>
    `).join('') || emptyRow(7);
  }

  function renderDrivers(rows) {
    const tbody = document.querySelector('#section-drivers table tbody');
    if (!tbody) return;
    tbody.innerHTML = rows.map((driver) => `
      <tr>
        <td><strong>${escapeHtml(driver.driver_code)}</strong></td>
        <td>${escapeHtml(driver.full_name)}</td>
        <td>${escapeHtml(driver.license_number)}</td>
        <td>${escapeHtml(driver.contact_number)}</td>
        <td>${badge(driver.status)}</td>
        <td>${escapeHtml(driver.license_expiry_date || 'Not stored')}</td>
        <td>${rowActions('drivers', driver.id)}</td>
      </tr>
    `).join('') || emptyRow(7);
  }

  function renderVehicles(rows) {
    const tbody = document.querySelector('#section-vehicles table tbody');
    if (!tbody) return;
    tbody.innerHTML = rows.map((vehicle) => `
      <tr>
        <td><strong>${escapeHtml(vehicle.vehicle_code)}</strong></td>
        <td>${escapeHtml(vehicle.license_plate)}</td>
        <td>${escapeHtml([vehicle.make, vehicle.model].filter(Boolean).join(' ') || vehicle.vehicle_type || '')}</td>
        <td>${escapeHtml(vehicle.vehicle_type || '')}</td>
        <td>${escapeHtml(vehicle.seating_capacity || '0')} seats</td>
        <td>${badge(vehicle.status)}</td>
        <td>${rowActions('vehicles', vehicle.id)}</td>
      </tr>
    `).join('') || emptyRow(7);
  }

  function renderSchedules(rows) {
    const table = document.querySelector('#section-schedules table');
    const tbody = table?.querySelector('tbody');
    if (!table || !tbody) return;
    ensureActionHeader(table);
    tbody.innerHTML = rows.map((schedule) => `
      <tr>
        <td><strong>${escapeHtml(schedule.schedule_code)}</strong></td>
        <td>${escapeHtml(`${schedule.origin || ''} - ${schedule.destination || ''}`)}</td>
        <td>${escapeHtml(schedule.driver_name || '')}</td>
        <td>${escapeHtml(schedule.license_plate || '')}</td>
        <td>${formatTime(schedule.departure_time)}</td>
        <td>${formatTime(schedule.expected_arrival_time)}</td>
        <td>${badge(schedule.status)}</td>
        <td>${rowActions('schedules', schedule.id)}</td>
      </tr>
    `).join('') || emptyRow(8);
  }

  function renderFuelMaintenance() {
    const root = document.getElementById('section-fuel-maintenance');
    const table = root?.querySelector('table');
    const tbody = table?.querySelector('tbody');
    if (!table || !tbody) return;
    ensureActionHeader(table);
    const search = root.querySelector('.search-input')?.value.trim().toLowerCase() || '';
    const type = root.querySelector('.filter-select')?.value || 'All Records';

    if (type === 'Fuel Logs') {
      table.querySelector('thead tr').innerHTML = '<th>Vehicle</th><th>Fuel Date</th><th>Liters</th><th>Cost</th><th>Odometer</th><th>Status</th><th>Actions</th>';
      const rows = state.fuel_logs.filter((item) => !search || String(item.license_plate || '').toLowerCase().includes(search));
      tbody.innerHTML = rows.map((item) => `
        <tr><td><strong>${escapeHtml(item.license_plate || '')}</strong></td><td>${escapeHtml(item.fuel_date || '')}</td><td>${number(item.quantity_liters)}</td><td>Rs. ${number(item.fuel_cost)}</td><td>${number(item.odometer_reading)}</td><td>${badge('Logged')}</td><td>${rowActions('fuel_logs', item.id)}</td></tr>
      `).join('') || emptyRow(7);
      return;
    }

    if (type === 'All Records') {
      table.querySelector('thead tr').innerHTML = '<th>Record Type</th><th>Vehicle</th><th>Date</th><th>Details</th><th>Status</th><th>Cost</th><th>Actions</th>';
      const fuelRows = state.fuel_logs
        .filter((item) => !search || String(item.license_plate || '').toLowerCase().includes(search))
        .map((item) => ({ kind: 'Fuel', vehicle: item.license_plate, date: item.fuel_date, details: `${number(item.quantity_liters)} L`, status: 'Logged', cost: item.fuel_cost, actions: rowActions('fuel_logs', item.id) }));
      const maintenanceRows = state.maintenance_logs
        .filter((item) => !search || String(item.license_plate || '').toLowerCase().includes(search))
        .map((item) => ({ kind: 'Maintenance', vehicle: item.license_plate, date: item.maintenance_date, details: item.maintenance_type, status: maintenanceStatusText(item.next_service_date, item.maintenance_status), cost: item.cost, actions: rowActions('maintenance_logs', item.id) }));
      tbody.innerHTML = [...fuelRows, ...maintenanceRows].map((item) => `
        <tr><td>${escapeHtml(item.kind)}</td><td><strong>${escapeHtml(item.vehicle || '')}</strong></td><td>${escapeHtml(item.date || '')}</td><td>${escapeHtml(item.details || '')}</td><td>${badge(item.status)}</td><td>Rs. ${number(item.cost)}</td><td>${item.actions}</td></tr>
      `).join('') || emptyRow(7);
      return;
    }

    table.querySelector('thead tr').innerHTML = '<th>Vehicle</th><th>Maintenance Type</th><th>Last Service</th><th>Next Due</th><th>Status</th><th>Cost</th><th>Actions</th>';
    const rows = state.maintenance_logs.filter((item) => !search || String(item.license_plate || '').toLowerCase().includes(search));
    tbody.innerHTML = rows.map((item) => `
      <tr>
        <td><strong>${escapeHtml(item.license_plate || '')}</strong></td>
        <td>${escapeHtml(item.maintenance_type)}</td>
        <td>${escapeHtml(item.maintenance_date || '')}</td>
        <td>${escapeHtml(item.next_service_date || '')}</td>
        <td>${maintenanceStatus(item.next_service_date, item.maintenance_status)}</td>
        <td>Rs. ${number(item.cost)}</td>
        <td>${rowActions('maintenance_logs', item.id)}</td>
      </tr>
    `).join('') || emptyRow(7);
  }

  function renderDashboard(data) {
    const values = document.querySelectorAll('#section-dashboard .stat-value');
    if (values.length >= 4) {
      values[0].textContent = data.routes ?? state.routes.filter((r) => r.status === 'Active').length;
      values[1].textContent = data.drivers ?? state.drivers.filter((d) => d.status === 'Active').length;
      values[2].textContent = data.vehicles ?? state.vehicles.length;
      values[3].textContent = data.schedules_today ?? state.schedules.length;
    }

    const fleetValues = document.querySelectorAll('#section-vehicles .stat-value');
    if (fleetValues.length >= 3) {
      fleetValues[0].textContent = state.vehicles.length;
      fleetValues[1].textContent = state.vehicles.filter((v) => v.status === 'In Service').length;
      fleetValues[2].textContent = state.vehicles.filter((v) => v.status === 'Under Maintenance').length;
    }

    const scheduleValues = document.querySelectorAll('#section-schedules .stat-value');
    if (scheduleValues.length >= 3) {
      scheduleValues[0].textContent = state.schedules.length;
      scheduleValues[1].textContent = state.schedules.filter((s) => s.status === 'Scheduled').length;
      scheduleValues[2].textContent = state.schedules.filter((s) => s.status === 'Delayed').length;
    }

    const fuelValues = document.querySelectorAll('#section-fuel-maintenance .stat-value');
    if (fuelValues.length >= 3) {
      fuelValues[0].textContent = `Rs. ${number(state.fuel_logs.reduce((sum, item) => sum + Number(item.fuel_cost || 0), 0))}`;
      fuelValues[1].textContent = state.maintenance_logs.filter((m) => maintenanceStatusText(m.next_service_date, m.maintenance_status) !== 'OK').length;
      const liters = state.fuel_logs.reduce((sum, item) => sum + Number(item.quantity_liters || 0), 0);
      const km = state.fuel_logs.reduce((sum, item) => sum + Number(item.odometer_reading || 0), 0);
      fuelValues[2].textContent = liters ? `${(km / liters).toFixed(1)} km/L` : '0 km/L';
    }
  }

  function rowActions(resource, id) {
    return `
      <button class="btn btn-secondary btn-sm" type="button" onclick="SRMSS.edit('${resource}', ${id})">Edit</button>
      <button class="btn btn-danger btn-sm" type="button" onclick="SRMSS.remove('${resource}', ${id})">Delete</button>
    `;
  }

  window.SRMSS = {
    edit(resource, id) {
      const item = state[resource].find((record) => Number(record.id) === Number(id));
      if (!item) return;
      const modalId = {
        routes: 'routeModal',
        drivers: 'driverModal',
        vehicles: 'vehicleModal',
        schedules: 'scheduleModal',
        maintenance_logs: 'maintenanceModal',
        fuel_logs: 'fuelModal'
      }[resource];
      fillModal(modalId, item);
      document.getElementById(modalId).classList.add('active');
    },
    async remove(resource, id) {
      if (!confirm('Delete this record?')) return;
      try {
        await fetch(`${API_BASE}?resource=${resource}&id=${id}`, { method: 'DELETE' }).then(async (response) => {
          const payload = await response.json();
          if (!response.ok || payload.success === false) throw new Error(payload.message || 'Delete failed.');
        });
        await refreshAll();
      } catch (error) {
        alert(error.message || 'Could not delete record. It may be used by another table.');
      }
    },
    trackTrip(id) {
      const trip = state.active_trips.find((record) => Number(record.id) === Number(id));
      if (!trip) return;
      navigateTo('routes');
      setTimeout(() => drawTripRoute(trip), 150);
    },
    showTrip(id) {
      const trip = state.active_trips.find((record) => Number(record.id) === Number(id));
      if (!trip) return;
      document.getElementById('tripDetailsBody').innerHTML = `
        <div class="form-grid">
          <div class="form-control"><label>Trip ID</label><input value="${escapeAttr(trip.trip_code)}" disabled></div>
          <div class="form-control"><label>Status</label><input value="${escapeAttr(trip.status)}" disabled></div>
          <div class="form-control"><label>Route</label><input value="${escapeAttr(`${trip.origin} - ${trip.destination}`)}" disabled></div>
          <div class="form-control"><label>Driver</label><input value="${escapeAttr(trip.driver_name)}" disabled></div>
          <div class="form-control"><label>Vehicle</label><input value="${escapeAttr(trip.license_plate)}" disabled></div>
          <div class="form-control"><label>Departure</label><input value="${formatTime(trip.departure_time)}" disabled></div>
          <div class="form-control"><label>ETA</label><input value="${formatTime(trip.expected_arrival_time)}" disabled></div>
          <div class="form-control"><label>Stops</label><textarea disabled rows="3">${escapeHtml(trip.stops || '')}</textarea></div>
        </div>`;
      openModal('tripDetailsModal');
    }
  };

  function fillModal(modalId, item) {
    clearModal(modalId);
    const modal = document.getElementById(modalId);
    modal.dataset.recordId = item.id;
    modal.dataset.recordStatus = item.status || item.maintenance_status || '';
    const c = controls(modal);
    const values = {
      routeModal: ['route_code', 'route_name', 'origin', 'destination', 'stops', 'distance_km', 'route_type', 'status'],
      driverModal: ['driver_code', 'full_name', 'nic_number', 'contact_number', 'address', 'license_number', 'license_type', 'license_issue_date', 'license_expiry_date', 'status'],
      vehicleModal: ['vehicle_code', 'license_plate', 'make', 'model', 'year', 'vehicle_type', 'seating_capacity', 'fuel_type', 'insurance_expiry', 'status'],
      scheduleModal: ['schedule_code', 'route_id', 'driver_id', 'vehicle_id', 'schedule_date', 'schedule_type', 'departure_time', 'expected_arrival_time', 'remarks', 'status'],
      fuelModal: ['vehicle_id', 'fuel_date', 'quantity_liters', 'fuel_cost', 'odometer_reading', 'trip_id'],
      maintenanceModal: ['vehicle_id', 'maintenance_date', 'maintenance_type', 'description', 'cost', 'service_center', 'next_service_mileage', 'next_service_date', 'maintenance_status']
    }[modalId] || [];
    values.forEach((field, index) => {
      if (c[index]) c[index].value = item[field] || '';
    });
  }

  function renderActiveTrips() {
    const container = document.querySelector('#section-routes .active-trips');
    if (!container) return;
    container.innerHTML = state.active_trips.map((trip) => `
      <div class="trip-card">
        <div class="trip-card-header">
          <div><div class="trip-id">${escapeHtml(trip.trip_code)}</div><div class="trip-route">${escapeHtml(trip.origin)} &rarr; ${escapeHtml(trip.destination)}</div></div>
          <div class="trip-status"><div class="trip-status-dot ${trip.status === 'Delayed' ? 'delayed' : ''}"></div><span style="font-size:12px;font-weight:600;">${escapeHtml(trip.status)}</span></div>
        </div>
        <div class="trip-details">
          <div class="trip-detail-item"><div class="trip-detail-label">Driver</div><div class="trip-detail-value">${escapeHtml(trip.driver_name)}</div></div>
          <div class="trip-detail-item"><div class="trip-detail-label">Vehicle</div><div class="trip-detail-value">${escapeHtml(trip.license_plate)}</div></div>
          <div class="trip-detail-item"><div class="trip-detail-label">Departure</div><div class="trip-detail-value">${formatTime(trip.departure_time)}</div></div>
          <div class="trip-detail-item"><div class="trip-detail-label">ETA</div><div class="trip-detail-value">${formatTime(trip.expected_arrival_time)}</div></div>
        </div>
        <div class="trip-card-footer"><button class="btn btn-secondary btn-sm" type="button" onclick="SRMSS.trackTrip(${trip.id})">Track</button><button class="btn btn-primary btn-sm" type="button" onclick="SRMSS.showTrip(${trip.id})">Details</button></div>
      </div>
    `).join('') || '<div class="trip-card"><div class="trip-id">No active trips</div></div>';
  }

  function wireProfile() {
    document.getElementById('saveProfileBtn')?.addEventListener('click', async () => {
      try {
        await api('profile', {
          method: 'PUT',
          body: JSON.stringify({
            full_name: document.getElementById('profileFullName').value,
            email: document.getElementById('profileEmail').value,
            phone: document.getElementById('profilePhone').value,
            profile_image: state.profileImage
          })
        });
        await refreshAll();
      } catch (error) {
        alert(error.message || 'Could not save profile.');
      }
    });

    document.getElementById('removeProfilePicture')?.addEventListener('click', () => {
      state.profileImage = null;
      renderProfilePicture();
    });

    document.getElementById('profilePictureInput')?.addEventListener('change', (event) => {
      const file = event.target.files?.[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => {
        state.profileImage = reader.result;
        renderProfilePicture();
      };
      reader.readAsDataURL(file);
    });
  }

  function renderProfile() {
    if (!state.profile) return;
    document.getElementById('profileFullName').value = state.profile.full_name || '';
    document.getElementById('profileEmail').value = state.profile.email || '';
    document.getElementById('profilePhone').value = state.profile.phone || '';
    document.getElementById('profileRole').value = state.profile.role || '';
    document.getElementById('profileStatus').value = state.profile.status || '';
    renderProfilePicture();
  }

  function renderProfilePicture() {
    const preview = document.getElementById('profilePicturePreview');
    const initials = initialsFor(state.profile?.full_name || '');
    if (preview) preview.innerHTML = state.profileImage ? `<img src="${escapeAttr(state.profileImage)}" alt="Profile picture">` : initials;
    document.querySelectorAll('.avatar').forEach((avatar) => {
      avatar.innerHTML = state.profileImage ? `<img src="${escapeAttr(state.profileImage)}" alt="Profile picture">` : initials;
    });
    document.querySelectorAll('.user-info-name').forEach((node) => { node.textContent = state.profile?.full_name || ''; });
  }

  function renderUsers() {
    const tbody = document.querySelector('#usersTable tbody');
    if (!tbody) return;
    tbody.innerHTML = state.users.map((user) => `
      <tr><td>${escapeHtml(user.full_name)}</td><td>${escapeHtml(user.email)}</td><td>${escapeHtml(user.phone || '')}</td><td>${escapeHtml(user.role)}</td><td>${badge(user.status)}</td></tr>
    `).join('') || emptyRow(5);
  }

  function wireNotifications() {
    document.addEventListener('click', async (event) => {
      if (!event.target.closest('.mark-notifications-read')) return;
      event.preventDefault();
      await api('notifications', { method: 'PUT', body: '{}' });
      await refreshAll();
    });
  }

  function renderNotifications() {
    const unread = state.notifications.filter((item) => !Number(item.is_read)).length;
    document.querySelectorAll('.notification-count').forEach((node) => { node.textContent = unread; });
    document.querySelectorAll('.notification-body').forEach((body) => {
      body.innerHTML = state.notifications.map((item) => `
        <div class="notification-item ${Number(item.is_read) ? '' : 'unread'}">
          <div class="notification-item-icon" style="background:rgba(30,64,175,0.1);color:var(--primary-blue);">${notificationIcon(item.type)}</div>
          <div class="notification-item-title">${escapeHtml(item.title)}</div>
          <div class="notification-item-msg">${escapeHtml(item.message)}</div>
          <div class="notification-item-time">${escapeHtml(item.created_at || '')}</div>
        </div>
      `).join('') || '<div class="notification-item"><div class="notification-item-msg">No notifications</div></div>';
    });
  }

  function wireReports() {
    const root = document.getElementById('section-reports');
    const dateInputs = root?.querySelectorAll('input[type="date"]') || [];
    if (dateInputs[0]) {
      dateInputs[0].setAttribute('aria-label', 'Start Date');
      dateInputs[0].setAttribute('title', 'Start Date');
    }
    if (dateInputs[1]) {
      dateInputs[1].setAttribute('aria-label', 'End Date');
      dateInputs[1].setAttribute('title', 'End Date');
    }
    document.getElementById('generateReportBtn')?.addEventListener('click', generateReport);
    document.getElementById('downloadReportPdf')?.addEventListener('click', downloadReportPdf);
  }

  async function generateReport() {
    const root = document.getElementById('section-reports');
    const controls = root.querySelectorAll('.filter-select, .search-input');
    const type = controls[0]?.value && controls[0].value !== 'Select Report Type' ? controls[0].value : 'Route Performance';
    const from = controls[1]?.value || '';
    const to = controls[2]?.value || '';
    const report = await api(`reports&type=${encodeURIComponent(type)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
    state.report = { type, from, to };
    renderReport(report.data);
  }

  function renderReport(data) {
    const stats = document.querySelectorAll('#section-reports .stat-value');
    if (stats.length >= 4) {
      stats[0].textContent = `${data.summary.completion_rate}%`;
      stats[1].textContent = `${data.summary.completed_trips}/${data.summary.total_trips}`;
      stats[2].textContent = data.summary.delayed_trips ?? state.schedules.filter((s) => s.status === 'Delayed').length;
      stats[3].textContent = data.summary.vehicle_count;
    }
    renderReportHeader(data.title);
    const tbody = document.querySelector('#section-reports table tbody');
    if (!tbody) return;
    tbody.innerHTML = data.rows.map((row) => {
      const trips = Number(row.trips || 0);
      const completed = Number(row.completed || 0);
      const rate = trips ? ((completed / trips) * 100).toFixed(1) : '0.0';
      const fourth = reportFourthValue(data.title, row, rate);
      return `<tr><td>${escapeHtml(row.label)}</td><td>${trips}</td><td>${number(completed)}</td><td><strong>${fourth}</strong></td></tr>`;
    }).join('') || emptyRow(4);
  }

  function renderReportHeader(type) {
    const heading = document.querySelector('#section-reports .card-header h3');
    if (heading) heading.textContent = `${type || 'Route Performance'} Analysis`;
    const headRow = document.querySelector('#section-reports table thead tr');
    if (!headRow) return;
    const headers = {
      'Fuel Analysis': ['Vehicle', 'Fuel Entries', 'Liters', 'Total Cost'],
      'Maintenance Cost': ['Vehicle', 'Records', 'Completed', 'Total Cost'],
      'Vehicle Utilization': ['Vehicle', 'Trips', 'Completed', 'Distance'],
      'Driver Performance': ['Driver', 'Trips', 'Completed', 'Success Rate'],
      'Trip Completion': ['Status', 'Trips', 'Completed', 'Success Rate'],
      'Route Performance': ['Route', 'Trips', 'Completed', 'Success Rate']
    }[type] || ['Route', 'Trips', 'Completed', 'Success Rate'];
    headRow.innerHTML = headers.map((item) => `<th>${escapeHtml(item)}</th>`).join('');
  }

  function reportFourthValue(type, row, rate) {
    if (type === 'Fuel Analysis') return `Rs. ${number(row.active)}`;
    if (type === 'Maintenance Cost') return `Rs. ${number(row.active)}`;
    if (type === 'Vehicle Utilization') return `${number(row.distance_km)} km`;
    return `${rate}%`;
  }

  async function downloadReportPdf() {
    if (!state.report) await generateReport();
    const params = state.report || { type: 'Route Performance', from: '', to: '' };
    const response = await fetch(`${API_BASE}?resource=reports&type=${encodeURIComponent(params.type)}&from=${encodeURIComponent(params.from)}&to=${encodeURIComponent(params.to)}&format=pdf`);
    if (!response.ok) {
      alert('Could not download PDF report.');
      return;
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'srmss-report.pdf';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function ensureActionHeader(table) {
    const headRow = table.querySelector('thead tr');
    if (!headRow || Array.from(headRow.children).some((th) => th.textContent.trim() === 'Actions')) return;
    const th = document.createElement('th');
    th.textContent = 'Actions';
    headRow.appendChild(th);
  }

  function badge(status) {
    const normalized = String(status || '').toLowerCase();
    let klass = 'badge-info';
    if (['active', 'available', 'on time', 'completed', 'ok', 'logged'].includes(normalized)) klass = 'badge-success';
    if (['on leave', 'scheduled', 'in progress', 'maintenance', 'under maintenance', 'due soon'].includes(normalized)) klass = 'badge-warning';
    if (['inactive', 'suspended', 'cancelled', 'delayed', 'overdue'].includes(normalized)) klass = 'badge-danger';
    return `<span class="badge ${klass}">${escapeHtml(status || '')}</span>`;
  }

  function maintenanceStatus(nextDate, storedStatus) {
    return badge(maintenanceStatusText(nextDate, storedStatus));
  }

  function maintenanceStatusText(nextDate, storedStatus) {
    if (storedStatus === 'Completed') return 'OK';
    if (!nextDate) return storedStatus || 'OK';
    const today = new Date();
    const due = new Date(`${nextDate}T00:00:00`);
    const days = Math.ceil((due - today) / 86400000);
    if (days < 0) return 'OVERDUE';
    if (days <= 7) return 'Due Soon';
    return storedStatus || 'OK';
  }

  function countStops(stops) {
    return stops ? stops.split(',').filter(Boolean).length : 0;
  }

  function formatTime(value) {
    return value ? String(value).slice(0, 5) : '';
  }

  function number(value) {
    return Number(value || 0).toLocaleString('en-LK');
  }

  function initialsFor(name) {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] || 'U') + (parts[1]?.[0] || '')).toUpperCase();
  }

  function emptyRow(cols) {
    return `<tr><td colspan="${cols}">No records found.</td></tr>`;
  }

  function notificationIcon(type) {
    return { success: '&#10003;', warning: '&#9888;', danger: '!', info: '&#128203;' }[type] || '&#128203;';
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#96;');
  }

  const STOP_DATA = [
    { id: 1, name: 'Colombo Fort', lat: 6.9344, lng: 79.8428 },
    { id: 2, name: 'Pettah', lat: 6.9404, lng: 79.8574 },
    { id: 3, name: 'Maradana', lat: 6.9210, lng: 79.8637 },
    { id: 4, name: 'Nugegoda', lat: 6.8742, lng: 79.8897 },
    { id: 5, name: 'Maharagama', lat: 6.8480, lng: 79.9270 },
    { id: 6, name: 'Kandy', lat: 7.2906, lng: 80.6337 },
    { id: 7, name: 'Galle', lat: 6.0535, lng: 80.2210 },
    { id: 8, name: 'Jaffna', lat: 9.6615, lng: 80.0255 }
  ];
  let gpsMap = null;
  let activeRouteLayer = null;
  let routeStopLayer = null;
  let vehicleLayer = null;
  let selectedTripId = null;
  let liveTrackingStarted = false;

  function initGpsMap() {
    const mapEl = document.getElementById('gps-map');
    if (!mapEl || gpsMap || typeof L === 'undefined') return;
    gpsMap = L.map('gps-map', {
      maxBounds: SRI_LANKA_BOUNDS,
      maxBoundsViscosity: 1.0,
      minZoom: 7
    }).setView([7.8731, 80.7718], 8);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(gpsMap);
    routeStopLayer = L.layerGroup().addTo(gpsMap);
    vehicleLayer = L.layerGroup().addTo(gpsMap);
    STOP_DATA.forEach((stop) => addStopMarker(stop.name, [stop.lat, stop.lng]));
  }

  function drawTripRoute(trip) {
    initGpsMap();
    if (!gpsMap) return;
    if (activeRouteLayer) activeRouteLayer.remove();
    selectedTripId = Number(trip.id);
    routeStopLayer?.clearLayers();
    const coords = routeCoordinates(trip);
    if (coords.length < 2) {
      const fallback = [findStop(trip.origin), findStop(trip.destination)].filter(Boolean);
      if (fallback.length >= 2) coords.push(...fallback);
    }
    activeRouteLayer = L.polyline(coords.length >= 2 ? coords : [[7.8731, 80.7718], [7.2906, 80.6337]], {
      color: '#1559b7',
      weight: 5,
      opacity: 0.9
    }).addTo(gpsMap).bindTooltip(`${trip.trip_code}: ${trip.origin} - ${trip.destination}`, { sticky: true });
    coords.forEach((coord, index) => addStopMarker(routePointLabels(trip)[index] || `Stop ${index + 1}`, coord));
    renderVehicleLocations();
    gpsMap.fitBounds(activeRouteLayer.getBounds(), { padding: [30, 30] });
  }

  function routeCoordinates(trip) {
    const parsed = parseRoutePoints(trip.route_points);
    if (parsed.length >= 2) return parsed.map((point) => point.coord);
    const stops = String(trip.stops || '').split(',').map((name) => name.trim()).filter(Boolean);
    const names = [trip.origin, ...stops, trip.destination].filter(Boolean);
    return names.map(findStop).filter(Boolean);
  }

  function routePointLabels(trip) {
    const parsed = parseRoutePoints(trip.route_points);
    if (parsed.length >= 2) return parsed.map((point) => point.name);
    return [trip.origin, ...String(trip.stops || '').split(',').map((name) => name.trim()).filter(Boolean), trip.destination].filter(Boolean);
  }

  function parseRoutePoints(value) {
    return String(value || '').split(';;').map((entry) => {
      const [name, lat, lng] = entry.split('|');
      const coord = [Number(lat), Number(lng)];
      return name && Number.isFinite(coord[0]) && Number.isFinite(coord[1]) ? { name, coord } : null;
    }).filter(Boolean);
  }

  function addStopMarker(name, coord) {
    if (!routeStopLayer || !coord) return;
    L.circleMarker(coord, {
      radius: 5,
      color: '#1559b7',
      weight: 2,
      fillColor: '#ffffff',
      fillOpacity: 1
    }).addTo(routeStopLayer).bindPopup(`<strong>${escapeHtml(name)}</strong>`);
  }

  function renderVehicleLocations() {
    initGpsMap();
    if (!gpsMap || !vehicleLayer) return;
    vehicleLayer.clearLayers();
    state.active_trips.forEach((trip) => {
      if (selectedTripId && Number(trip.id) !== selectedTripId) return;
      const coords = routeCoordinates(trip);
      const position = livePosition(trip, coords);
      if (!position) return;
      L.marker(position, {
        title: `${trip.trip_code} - ${trip.license_plate}`
      }).addTo(vehicleLayer).bindPopup(`<strong>${escapeHtml(trip.license_plate)}</strong><br>${escapeHtml(trip.trip_code)}<br>${escapeHtml(trip.origin)} - ${escapeHtml(trip.destination)}`);
    });
  }

  function livePosition(trip, coords) {
    if (!coords.length) return null;
    if (coords.length === 1) return coords[0];
    const start = new Date(String(trip.scheduled_start || '').replace(' ', 'T')).getTime();
    const end = new Date(String(trip.scheduled_end || '').replace(' ', 'T')).getTime();
    const now = Date.now();
    const progress = end > start ? Math.max(0, Math.min(1, (now - start) / (end - start))) : 0;
    const scaled = progress * (coords.length - 1);
    const index = Math.min(coords.length - 2, Math.floor(scaled));
    const local = scaled - index;
    return [
      coords[index][0] + (coords[index + 1][0] - coords[index][0]) * local,
      coords[index][1] + (coords[index + 1][1] - coords[index][1]) * local
    ];
  }

  function startLiveTracking() {
    if (liveTrackingStarted) return;
    liveTrackingStarted = true;
    setInterval(async () => {
      try {
        const trips = await api('active_trips');
        state.active_trips = trips.data || [];
        renderActiveTrips();
        renderVehicleLocations();
      } catch (error) {
        console.warn('Live tracking update failed:', error.message || error);
      }
    }, 20000);
    setInterval(renderVehicleLocations, 5000);
  }

  function findStop(name) {
    const lower = String(name || '').toLowerCase();
    const stop = STOP_DATA.find((item) => lower.includes(item.name.toLowerCase()) || item.name.toLowerCase().includes(lower));
    return stop ? [stop.lat, stop.lng] : null;
  }

  window.addEventListener('load', () => setTimeout(initGpsMap, 600));
  document.addEventListener('click', (event) => {
    const nav = event.target.closest('[data-nav]');
    if (nav && nav.dataset.nav === 'routes') setTimeout(initGpsMap, 250);
  });
})();
