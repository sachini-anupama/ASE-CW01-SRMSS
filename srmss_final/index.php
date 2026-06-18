<?php
require __DIR__ . '/auth_check.php';
require __DIR__ . '/backend/config.php';

// ── Real DB Dashboard Stats ───────────────────────────────────
try {
    $pdo = db();

    $totalRoutes    = $pdo->query("SELECT COUNT(*) FROM Route WHERE Status='Active'")->fetchColumn();
    $activeDrivers  = $pdo->query("SELECT COUNT(*) FROM Driver WHERE Status='Active'")->fetchColumn();
    $fleetVehicles  = $pdo->query("SELECT COUNT(*) FROM Vehicle")->fetchColumn();
    $completedTrips = $pdo->query("
        SELECT COUNT(*) FROM TripStatus 
        WHERE Status='Completed' 
        AND DATE(ActualArrival) = CURDATE()
    ")->fetchColumn();

    $trips = $pdo->query("
        SELECT 
            CONCAT('TRP-', LPAD(ts.TripStatusID, 3, '0')) AS TripID,
            CONCAT(r.Origin, ' → ', r.Destination)        AS Route,
            d.FullName                                     AS Driver,
            v.VehicleNumber                                AS Vehicle,
            TIME_FORMAT(s.EstimatedStartTime, '%h:%i %p') AS Departure,
            ts.Status
        FROM TripStatus ts
        JOIN Schedule s ON ts.ScheduleID = s.ScheduleID
        JOIN Route r    ON s.RouteID     = r.RouteID
        JOIN Driver d   ON s.DriverID    = d.DriverID
        JOIN Vehicle v  ON s.VehicleID   = v.VehicleID
        ORDER BY s.EstimatedStartTime
        LIMIT 10
    ")->fetchAll();

} catch (\Throwable $e) {
    $totalRoutes = $activeDrivers = $fleetVehicles = $completedTrips = 0;
    $trips = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Schedulix SRMSS | Smart Route Management & Scheduling System</title>
  <style>
    :root {
      --primary-blue: #1559b7;
      --primary-dark: #0f2f5f;
      --secondary-cyan: #2286e9;
      --accent-blue: #2286e9;
      --success-green: #16a56f;
      --warning-amber: #f1a208;
      --danger-red: #dc3545;
      --dark-bg: #1f2937;
      --light-bg: #f5f9fd;
      --white: #ffffff;
      --text-dark: #17243a;
      --text-light: #68758a;
      --border-color: #dbe7f3;
      --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
      --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { width: 100%; height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen',
        'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
      background: var(--light-bg);
      color: var(--text-dark);
      line-height: 1.6;
    }
    svg.icon { width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    svg.icon-lg { width: 24px; height: 24px; }
    .page-header-controls .notification-bell svg.icon-lg { fill: rgba(255,255,255,0.9); }
    svg.icon-xl { width: 28px; height: 28px; }
    .app-wrapper { display: grid; grid-template-columns: 270px 1fr; grid-template-rows: 1fr; height: 100vh; background: var(--light-bg); }
    .sidebar { grid-row: 1 / -1; grid-column: 1; background: linear-gradient(180deg, #0f2f5f, #0d4b79 58%, #0f765f); color: var(--white); overflow-y: auto; border-right: 1px solid rgba(255, 255, 255, 0.1); padding: 0; display: flex; flex-direction: column; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); display: flex; align-items: center; gap: 12px; min-height: 70px; }
    .sidebar-logo { width: 44px; height: 44px; object-fit: contain; border-radius: 10px; flex-shrink: 0; display: block; }
    .sidebar-brand h3 { margin: 0; font-size: 16px; font-weight: 700; line-height: 1.2; }
    .sidebar-brand p { margin: 3px 0 0; font-size: 11px; opacity: 0.8; font-weight: 500; }
    .sidebar-nav { flex: 1; padding: 12px 8px; overflow-y: auto; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 12px; margin-bottom: 6px; color: rgba(255, 255, 255, 0.8); text-decoration: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; border: 1px solid transparent; }
    .nav-item:hover { background: rgba(255, 255, 255, 0.12); color: var(--white); transform: translateX(4px); }
    .nav-item.active { background: rgba(255, 255, 255, 0.2); color: var(--white); border-color: rgba(255, 255, 255, 0.3); font-weight: 600; }
    .nav-item svg { flex-shrink: 0; }
    .sidebar-footer { padding: 12px 8px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: auto; }
    .topbar { display: none; }
    .notification-bell { position: relative; cursor: pointer; }
    .notification-count { position: absolute; top: -8px; right: -8px; background: var(--danger-red); color: var(--white); border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
    .notification-panel { position: absolute; top: 100%; right: 0; width: 350px; max-height: 450px; background: var(--white); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: var(--shadow-xl); display: none; flex-direction: column; z-index: 1000; margin-top: 10px; }
    .notification-panel.active { display: flex; }
    .notification-header { padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .notification-header h4 { margin: 0; font-size: 16px; font-weight: 600; }
    .notification-body { flex: 1; overflow-y: auto; max-height: 350px; }
    .notification-item { padding: 16px; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.2s; }
    .notification-item:hover { background: var(--light-bg); }
    .notification-item.unread { background: rgba(30, 64, 175, 0.05); }
    .notification-item-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; font-size: 18px; }
    .notification-item-title { font-weight: 600; font-size: 13px; color: var(--text-dark); margin-bottom: 4px; }
    .notification-item-msg { font-size: 13px; color: var(--text-light); line-height: 1.4; }
    .notification-item-time { font-size: 11px; color: var(--text-light); margin-top: 6px; }
    .notification-footer { padding: 12px 16px; border-top: 1px solid var(--border-color); text-align: center; }
    .notification-footer a { color: var(--primary-blue); font-size: 13px; font-weight: 600; text-decoration: none; }
    .user-profile { display: flex; align-items: center; gap: 12px; padding: 6px 12px 6px 6px; border: 1px solid rgba(255,255,255,0.35); border-radius: 20px; background: rgba(255,255,255,0.15); cursor: pointer; }
    .avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.25); color: var(--white); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
    .user-info { display: flex; flex-direction: column; }
    .user-info-name { font-size: 13px; font-weight: 600; color: var(--white); }
    .user-info-role { font-size: 11px; color: rgba(255,255,255,0.75); }
    .avatar img, .profile-picture-preview img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
    .profile-picture-preview { width: 86px; height: 86px; border-radius: 50%; background: var(--light-bg); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; overflow: hidden; font-size: 28px; font-weight: 700; color: var(--primary-blue); }
    .report-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
    .main-content { grid-column: 2; grid-row: 1; display: flex; flex-direction: column; overflow: hidden; background: var(--light-bg); }
    .page-section { display: none; animation: fadeIn 0.3s ease-in-out; flex-direction: column; flex: 1; overflow: hidden; }
    .page-section.active { display: flex; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .page-section > .page-header { min-height: 110px; padding: 20px 32px; display: flex; flex-direction: row; align-items: center; justify-content: space-between; background: linear-gradient(100deg, #0d3d68 0%, #0b6473 58%, #078064 100%); box-shadow: 0 4px 18px rgba(13, 61, 104, 0.22); color: var(--white); flex-shrink: 0; z-index: 100; }
    .page-header-text { display: flex; flex-direction: column; justify-content: center; }
    .page-header-controls { display: flex; align-items: center; gap: 20px; flex-shrink: 0; }
    .page-section-body { flex: 1; overflow-y: auto; padding: 28px; }
    .page-section > .page-header h1 { font-size: 32px; line-height: 1.18; font-weight: 800; margin: 0 0 12px; color: var(--white); text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); }
    .page-section > .page-header p { max-width: 780px; margin: 0; font-size: 16px; line-height: 1.55; color: rgba(255, 255, 255, 0.82); }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; margin-bottom: 28px; }
    .stat-card { background: var(--white); padding: 20px; border-radius: 12px; box-shadow: var(--shadow); border-left: 4px solid var(--primary-blue); transition: all 0.3s ease; }
    .stat-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
    .stat-card.success { border-left-color: var(--success-green); }
    .stat-card.warning { border-left-color: var(--warning-amber); }
    .stat-card.danger { border-left-color: var(--danger-red); }
    .stat-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.5px; margin-bottom: 8px; }
    .stat-value { font-size: 32px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
    .stat-change { font-size: 12px; color: var(--text-light); }
    .card { background: var(--white); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; margin-bottom: 20px; border: 1px solid var(--border-color); }
    .card-header { padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--white); }
    .card-header h3 { margin: 0; font-size: 18px; font-weight: 600; color: var(--text-dark); }
    .card-body { padding: 20px; }
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; }
    .btn-primary { background: var(--primary-blue); color: var(--white); }
    .btn-primary:hover { background: var(--primary-dark); box-shadow: var(--shadow-lg); transform: translateY(-1px); }
    .btn-secondary { background: var(--light-bg); color: var(--text-dark); border: 1px solid var(--border-color); }
    .btn-secondary:hover { background: var(--border-color); }
    .btn-success { background: var(--success-green); color: var(--white); }
    .btn-danger { background: var(--danger-red); color: var(--white); }
    .btn-danger:hover { background: #b91c1c; box-shadow: var(--shadow-lg); }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    .table-container { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: var(--light-bg); border-bottom: 2px solid var(--border-color); }
    th { padding: 14px 16px; text-align: left; font-weight: 600; color: var(--text-dark); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
    td { padding: 14px 16px; border-bottom: 1px solid var(--border-color); font-size: 14px; color: var(--text-dark); }
    tbody tr:hover { background: rgba(30, 64, 175, 0.02); }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-danger { background: #fee2e2; color: #7f1d1d; }
    .badge-info { background: #dbeafe; color: #0c4a6e; }
    .search-filter { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .search-input { flex: 1; min-width: 200px; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--white); }
    .filter-select { padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--white); cursor: pointer; }
    .trip-tracker { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
    .map-container { background: var(--white); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; height: 400px; display: flex; align-items: center; justify-content: center; position: relative; }
    .active-trips { display: flex; flex-direction: column; gap: 12px; }
    .trip-card { background: var(--white); border-radius: 12px; padding: 16px; box-shadow: var(--shadow); border-left: 4px solid var(--secondary-cyan); transition: all 0.3s ease; }
    .trip-card:hover { box-shadow: var(--shadow-lg); transform: translateX(4px); }
    .trip-card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px; }
    .trip-id { font-weight: 700; color: var(--text-dark); font-size: 14px; }
    .trip-route { font-size: 13px; color: var(--text-light); margin-top: 4px; }
    .trip-status { display: flex; align-items: center; gap: 6px; }
    .trip-status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--success-green); animation: pulse 2s infinite; }
    .trip-status-dot.delayed { background: var(--warning-amber); }
    .trip-status-dot.completed { background: var(--text-light); animation: none; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    .trip-details { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px; }
    .trip-detail-item { display: flex; flex-direction: column; }
    .trip-detail-label { color: var(--text-light); font-size: 11px; text-transform: uppercase; font-weight: 600; margin-bottom: 4px; letter-spacing: 0.5px; }
    .trip-detail-value { color: var(--text-dark); font-weight: 600; }
    .trip-card-footer { display: flex; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color); }
    .trip-card-footer .btn { flex: 1; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 16px; }
    .form-control { display: flex; flex-direction: column; }
    .form-control label { margin-bottom: 8px; font-weight: 600; color: var(--text-dark); font-size: 14px; }
    .form-control input, .form-control select, .form-control textarea { padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; font-family: inherit; background: var(--white); }
    .form-control input:focus, .form-control select:focus, .form-control textarea:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1); }
    .alert { padding: 14px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; border-left: 4px solid; }
    .alert-warning { background: #fef3c7; color: #92400e; border-left-color: var(--warning-amber); }
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; align-items: center; justify-content: center; }
    .modal.active { display: flex; }
    .modal-content { background: var(--white); border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-xl); animation: slideUp 0.3s ease-out; }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header { padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { margin: 0; font-size: 20px; font-weight: 600; color: var(--text-dark); }
    .close-btn { background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-light); transition: color 0.2s; }
    .close-btn:hover { color: var(--text-dark); }
    .modal-body { padding: 20px; }
    .modal-footer { padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px; }
    @media (max-width: 1200px) { .trip-tracker { grid-template-columns: 1fr; } .map-container { height: 300px; } }
    @media (max-width: 768px) { .app-wrapper { grid-template-columns: 1fr; } .sidebar { position: fixed; left: -270px; top: 0; height: 100vh; z-index: 1000; transition: left 0.3s ease; } .sidebar.mobile-open { left: 0; } .main-content { grid-column: 1; } .stats-grid { grid-template-columns: 1fr; } .form-grid { grid-template-columns: 1fr; } .search-filter { flex-direction: column; } }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--text-light); }
  </style>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>#gps-map { width: 100%; height: 100%; z-index: 1; }</style>
</head>
<body>

<!-- SVG Icon Library -->
<svg style="display: none;">
  <defs>
    <symbol id="i-dashboard" viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h2v8H3zm4-8h2v16H7zm4-2h2v18h-2zm4 4h2v14h-2zm4-2h2v16h-2z"/></symbol>
    <symbol id="i-route" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></symbol>
    <symbol id="i-driver" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></symbol>
    <symbol id="i-vehicle" viewBox="0 0 24 24" fill="currentColor"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5H6.5c-.66 0-1.22.42-1.42 1.01L3 12v8h4l1 2h9l1-2h4v-8l-2.08-5.99zM6.5 9h11l1.96 2.5H4.54L6.5 9zm6.5 13c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6-2c0 1.1-.9 2-2 2s-2-.9-2-2 .9-2 2-2 2 .9 2 2z"/></symbol>
    <symbol id="i-schedule" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/></symbol>
    <symbol id="i-fuel" viewBox="0 0 24 24" fill="currentColor"><path d="M15 7V3H9v4H5v11c0 2.2 1.8 4 4 4h6c2.2 0 4-1.8 4-4v-11h-4zm-2 8h-2v-2h2v2z"/></symbol>
    <symbol id="i-reports" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2V17zm4 0h-2V7h2V17zm4 0h-2v-4h2V17z"/></symbol>
    <symbol id="i-notification" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
    <symbol id="i-alert-circle" viewBox="0 0 24 24" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></symbol>
  </defs>
</svg>

<?php
// ── Notification helper: build the dropdown once, reuse via PHP include ──────
ob_start();
?>
<div class="notification-bell" onclick="toggleNotifications(event)">
  <svg class="icon-lg" viewBox="0 0 24 24"><use href="#i-notification"/></svg>
  <span class="notification-count">0</span>
  <div class="notification-panel">
    <div class="notification-header">
      <h4>Notifications</h4>
      <button class="close-btn" onclick="toggleNotifications(event)">&times;</button>
    </div>
    <div class="notification-body"></div>
    <div class="notification-footer"><a href="#" class="mark-notifications-read">Mark all as read</a></div>
  </div>
</div>
<div class="user-profile" onclick="navigateTo('profile')">
  <div class="avatar"><?= htmlspecialchars($avatarInitials) ?></div>
  <div class="user-info">
    <div class="user-info-name"><?= htmlspecialchars($currentUser['name']) ?></div>
    <div class="user-info-role"><?= htmlspecialchars($currentUser['role']) ?></div>
  </div>
</div>
<?php
$headerControls = ob_get_clean();
?>

<div class="app-wrapper">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <img class="sidebar-logo" src="assets/schedulix-logo.jpeg" alt="Schedulix SRMSS logo">
      <div class="sidebar-brand">
        <h3>SRMSS</h3>
        <p>Schedulix</p>
      </div>
    </div>
    <nav class="sidebar-nav">
      <a class="nav-item active" data-nav="dashboard">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-dashboard"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item" data-nav="routes">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-route"/></svg>
        <span>Route Management</span>
      </a>
      <a class="nav-item" data-nav="drivers">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-driver"/></svg>
        <span>Driver Management</span>
      </a>
      <a class="nav-item" data-nav="vehicles">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-vehicle"/></svg>
        <span>Vehicle Management</span>
      </a>
      <a class="nav-item" data-nav="schedules">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-schedule"/></svg>
        <span>Schedule Management</span>
      </a>
      <a class="nav-item" data-nav="fuel-maintenance">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-fuel"/></svg>
        <span>Fuel &amp; Maintenance</span>
      </a>
      <a class="nav-item" data-nav="reports">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-reports"/></svg>
        <span>Reports &amp; Analytics</span>
      </a>
      <a class="nav-item" data-nav="profile">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-driver"/></svg>
        <span>Profile Management</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <!-- CHANGE 1: logout.php redirect -->
      <a class="nav-item" href="logout.php" onclick="return confirm('Are you sure you want to logout?')">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-logout"/></svg>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">

    <!-- DASHBOARD -->
    <section class="page-section active" id="section-dashboard">
      <div class="page-header">
        <div class="page-header-text">
          <h1>Smart Route Dashboard</h1>
          <p>Track active routes, schedule status, assigned vehicles, and dispatch alerts from one simple operating view.</p>
        </div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="stats-grid">
          <div class="stat-card success"><div class="stat-label">Total Routes</div><div class="stat-value"><?= $totalRoutes ?></div><div class="stat-change">Active routes</div></div>
          <div class="stat-card"><div class="stat-label">Active Drivers</div><div class="stat-value"><?= $activeDrivers ?></div><div class="stat-change">Currently active</div></div>
          <div class="stat-card warning"><div class="stat-label">Fleet Vehicles</div><div class="stat-value"><?= $fleetVehicles ?></div><div class="stat-change">Total registered</div></div>
          <div class="stat-card success"><div class="stat-label">Completed Trips Today</div><div class="stat-value"><?= $completedTrips ?></div><div class="stat-change">As of today</div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3>Today's Trip Status</h3></div>
          <div class="card-body">
            <div class="table-container">
              <table>
                <thead><tr><th>Trip ID</th><th>Route</th><th>Driver</th><th>Vehicle</th><th>Departure</th><th>Status</th></tr></thead>
                <tbody>
                  <?php if (empty($trips)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:30px;">No trip records found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($trips as $t):
                      $badgeClass = match($t['Status']) {
                        'Completed' => 'badge-success',
                        'On Time'   => 'badge-success',
                        'Delayed'   => 'badge-warning',
                        'Cancelled' => 'badge-danger',
                        default     => 'badge-info'
                      };
                    ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($t['TripID']) ?></strong></td>
                      <td><?= htmlspecialchars($t['Route']) ?></td>
                      <td><?= htmlspecialchars($t['Driver']) ?></td>
                      <td><?= htmlspecialchars($t['Vehicle']) ?></td>
                      <td><?= htmlspecialchars($t['Departure']) ?></td>
                      <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($t['Status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ROUTE MANAGEMENT -->
    <section class="page-section" id="section-routes">
      <div class="page-header">
        <div class="page-header-text"><h1>Smart Route Management</h1><p>Create, monitor, and update bus routes with clear stop details, distance data, and operational status.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="trip-tracker">
          <div class="map-container"><div id="gps-map"></div></div>
          <div>
            <div style="margin-bottom:16px;"><h3 style="margin:0;font-size:16px;color:var(--text-dark);">Active Trips</h3></div>
            <div class="active-trips">
              <div class="trip-card"><div class="trip-card-header"><div><div class="trip-id">Loading</div><div class="trip-route">Loading active trips</div></div></div></div>
            </div>
          </div>
        </div>
        <div class="search-filter">
          <input type="text" class="search-input" placeholder="Search routes...">
          <select class="filter-select"><option>All Status</option><option>Active</option><option>Inactive</option></select>
          <button class="btn btn-primary" onclick="openModal('routeModal')">+ Add Route</button>
        </div>
        <div class="card">
          <div class="card-header"><h3>All Routes</h3></div>
          <div class="card-body"><div class="table-container"><table><thead><tr><th>Route ID</th><th>Route Name</th><th>Origin &rarr; Destination</th><th>Distance</th><th>Stops</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr><td><strong>RT-001</strong></td><td>Colombo - Kandy Express</td><td>Colombo Fort &rarr; Kandy City</td><td>116 km</td><td>3</td><td><span class="badge badge-success">Active</span></td><td><button class="btn btn-secondary btn-sm" onclick="openModal('routeModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr><tr><td><strong>RT-002</strong></td><td>Colombo - Galle Route</td><td>Colombo Port &rarr; Galle Fort</td><td>116 km</td><td>5</td><td><span class="badge badge-success">Active</span></td><td><button class="btn btn-secondary btn-sm" onclick="openModal('routeModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr><tr><td><strong>RT-003</strong></td><td>Kandy - Jaffna Highway</td><td>Kandy City &rarr; Jaffna Central</td><td>248 km</td><td>4</td><td><span class="badge badge-success">Active</span></td><td><button class="btn btn-secondary btn-sm" onclick="openModal('routeModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr></tbody></table></div></div>
        </div>
      </div>
    </section>

    <!-- DRIVER MANAGEMENT -->
    <section class="page-section" id="section-drivers">
      <div class="page-header">
        <div class="page-header-text"><h1>Smart Driver Management</h1><p>Manage driver profiles, contact details, license records, and availability from one organized workspace.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="search-filter">
          <input type="text" class="search-input" placeholder="Search drivers by name or ID...">
          <select class="filter-select"><option>All Status</option><option>Active</option><option>On Leave</option><option>Inactive</option></select>
          <button class="btn btn-primary" onclick="openModal('driverModal')">+ Add Driver</button>
        </div>
        <div class="alert alert-warning"><svg style="width:20px;height:20px;"><use href="#i-alert-circle"/></svg><span>2 drivers have licenses expiring within 30 days. Please renew them.</span></div>
        <div class="card">
          <div class="card-header"><h3>Driver Directory</h3></div>
          <div class="card-body"><div class="table-container"><table><thead><tr><th>Driver ID</th><th>Full Name</th><th>License Number</th><th>Contact</th><th>Status</th><th>License Expiry</th><th>Actions</th></tr></thead><tbody><tr><td><strong>DRV-001</strong></td><td>Ahmed Khan</td><td>L-123456</td><td>+94 701 234 567</td><td><span class="badge badge-success">Active</span></td><td>2026-01-10</td><td><button class="btn btn-secondary btn-sm" onclick="openModal('driverModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr><tr><td><strong>DRV-002</strong></td><td>Ravi Kumar</td><td>L-789012</td><td>+94 702 345 678</td><td><span class="badge badge-success">Active</span></td><td>2026-06-15</td><td><button class="btn btn-secondary btn-sm" onclick="openModal('driverModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr><tr><td><strong>DRV-003</strong></td><td>Kumara Silva</td><td>L-345678</td><td>+94 703 456 789</td><td><span class="badge badge-warning">On Leave</span></td><td>2024-12-20</td><td><button class="btn btn-secondary btn-sm" onclick="openModal('driverModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr></tbody></table></div></div>
        </div>
      </div>
    </section>

    <!-- VEHICLE MANAGEMENT -->
    <section class="page-section" id="section-vehicles">
      <div class="page-header">
        <div class="page-header-text"><h1>Smart Vehicle Management</h1><p>Register fleet vehicles, review capacity, monitor assignments, and keep service readiness visible.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="search-filter">
          <input type="text" class="search-input" placeholder="Search vehicles by plate...">
          <select class="filter-select"><option>All Status</option><option>Available</option><option>In Service</option><option>Under Maintenance</option></select>
          <button class="btn btn-primary" onclick="openModal('vehicleModal')">+ Add Vehicle</button>
        </div>
        <div class="stats-grid">
          <div class="stat-card"><div class="stat-label">Total Fleet</div><div class="stat-value">15</div><div class="stat-change">All vehicles registered</div></div>
          <div class="stat-card success"><div class="stat-label">In Service</div><div class="stat-value">8</div><div class="stat-change">Currently operating</div></div>
          <div class="stat-card warning"><div class="stat-label">Under Maintenance</div><div class="stat-value">2</div><div class="stat-change">Scheduled service</div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3>Fleet Inventory</h3></div>
          <div class="card-body"><div class="table-container"><table><thead><tr><th>Vehicle ID</th><th>License Plate</th><th>Make &amp; Model</th><th>Type</th><th>Capacity</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr><td><strong>VEH-001</strong></td><td>WP-ABC-1234</td><td>Hino FL Series</td><td>Standard Bus</td><td>50 seats</td><td><span class="badge badge-success">Available</span></td><td><button class="btn btn-secondary btn-sm" onclick="openModal('vehicleModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr><tr><td><strong>VEH-002</strong></td><td>WP-DEF-5678</td><td>Tata LP 713</td><td>Express Bus</td><td>45 seats</td><td><span class="badge badge-info">In Service</span></td><td><button class="btn btn-secondary btn-sm" onclick="openModal('vehicleModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr><tr><td><strong>VEH-003</strong></td><td>WP-GHI-9012</td><td>Ashok Leyland</td><td>Mini Bus</td><td>30 seats</td><td><span class="badge badge-warning">Maintenance</span></td><td><button class="btn btn-secondary btn-sm" onclick="openModal('vehicleModal')">Edit</button> <button class="btn btn-danger btn-sm">Delete</button></td></tr></tbody></table></div></div>
        </div>
      </div>
    </section>

    <!-- SCHEDULE MANAGEMENT -->
    <section class="page-section" id="section-schedules">
      <div class="page-header">
        <div class="page-header-text"><h1>Smart Schedule Management</h1><p>Plan trips, assign routes, drivers, and vehicles, and keep timetable status easy to scan.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="search-filter">
          <input type="text" class="search-input" placeholder="Search by route or driver...">
          <select class="filter-select"><option>All Status</option><option>Scheduled</option><option>In Progress</option><option>Delayed</option><option>Completed</option><option>Cancelled</option></select>
          <button class="btn btn-primary" onclick="openModal('scheduleModal')">+ Create Schedule</button>
        </div>
        <div class="stats-grid">
          <div class="stat-card success"><div class="stat-label">Trips Today</div><div class="stat-value">12</div><div class="stat-change">All on schedule</div></div>
          <div class="stat-card"><div class="stat-label">Pending Schedules</div><div class="stat-value">5</div><div class="stat-change">Next 7 days</div></div>
          <div class="stat-card warning"><div class="stat-label">Delayed Today</div><div class="stat-value">1</div><div class="stat-change">15 min average</div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3>Active Schedules</h3></div>
          <div class="card-body"><div class="table-container"><table><thead><tr><th>Schedule ID</th><th>Route</th><th>Driver</th><th>Vehicle</th><th>Departure</th><th>ETA</th><th>Status</th></tr></thead><tbody><tr><td><strong>SCH-001</strong></td><td>Colombo &rarr; Kandy</td><td>Ahmed Khan</td><td>WP-ABC-1234</td><td>06:00 AM</td><td>10:30 AM</td><td><span class="badge badge-success">On Time</span></td></tr><tr><td><strong>SCH-002</strong></td><td>Colombo &rarr; Galle</td><td>Ravi Kumar</td><td>WP-DEF-5678</td><td>06:30 AM</td><td>09:15 AM</td><td><span class="badge badge-success">On Time</span></td></tr></tbody></table></div></div>
        </div>
      </div>
    </section>

    <!-- FUEL & MAINTENANCE -->
    <section class="page-section" id="section-fuel-maintenance">
      <div class="page-header">
        <div class="page-header-text"><h1>Smart Fleet Care</h1><p>Track fuel usage, maintenance activity, service costs, and upcoming vehicle care in one view.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="search-filter">
          <input type="text" class="search-input" placeholder="Search by vehicle...">
          <select class="filter-select"><option>All Records</option><option>Fuel Logs</option><option>Maintenance</option></select>
          <button class="btn btn-primary" onclick="openModal('fuelModal')">+ Log Fuel</button>
          <button class="btn btn-primary" onclick="openModal('maintenanceModal')">+ Log Maintenance</button>
        </div>
        <div class="stats-grid">
          <div class="stat-card"><div class="stat-label">Total Fuel Cost (This Month)</div><div class="stat-value">Rs. 185,000</div><div class="stat-change">+5% from last month</div></div>
          <div class="stat-card warning"><div class="stat-label">Maintenance Due</div><div class="stat-value">3</div><div class="stat-change">Within 7 days</div></div>
          <div class="stat-card success"><div class="stat-label">Fleet Efficiency</div><div class="stat-value">6.8 km/L</div><div class="stat-change">Average consumption</div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3>Maintenance Schedule</h3></div>
          <div class="card-body"><div class="table-container"><table><thead><tr><th>Vehicle</th><th>Maintenance Type</th><th>Last Service</th><th>Next Due</th><th>Status</th><th>Cost</th></tr></thead><tbody><tr><td><strong>WP-ABC-1234</strong></td><td>Routine Service</td><td>Dec 20, 2023</td><td>Jan 20, 2024</td><td><span class="badge badge-warning">Due Soon</span></td><td>Rs. 8,000</td></tr><tr><td><strong>WP-DEF-5678</strong></td><td>Brake Check</td><td>Jan 10, 2024</td><td>Apr 10, 2024</td><td><span class="badge badge-success">OK</span></td><td>Rs. 3,500</td></tr><tr><td><strong>WP-GHI-9012</strong></td><td>Tire Replacement</td><td>Jan 5, 2024</td><td>Jan 12, 2024</td><td><span class="badge badge-danger">OVERDUE</span></td><td>Rs. 25,000</td></tr></tbody></table></div></div>
        </div>
      </div>
    </section>

    <!-- REPORTS & ANALYTICS -->
    <section class="page-section" id="section-reports">
      <div class="page-header">
        <div class="page-header-text"><h1>Smart Reports &amp; Analytics</h1><p>Review route performance, vehicle utilization, fuel trends, and maintenance insights for better decisions.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="search-filter">
          <select class="filter-select"><option>Select Report Type</option><option>Trip Completion</option><option>Route Performance</option><option>Driver Performance</option><option>Vehicle Utilization</option><option>Fuel Analysis</option><option>Maintenance Cost</option></select>
          <input type="date" class="search-input" style="max-width:150px;" aria-label="Start Date" title="Start Date">
          <input type="date" class="search-input" style="max-width:150px;" aria-label="End Date" title="End Date">
          <button class="btn btn-primary" id="generateReportBtn">Generate Report</button>
        </div>
        <div class="stats-grid">
          <div class="stat-card success"><div class="stat-label">Trip Completion Rate</div><div class="stat-value">94%</div><div class="stat-change">This month</div></div>
          <div class="stat-card"><div class="stat-label">On-Time Delivery Rate</div><div class="stat-value">89%</div><div class="stat-change">+3% improvement</div></div>
          <div class="stat-card warning"><div class="stat-label">Avg Delay</div><div class="stat-value">12 min</div><div class="stat-change">When delayed</div></div>
          <div class="stat-card"><div class="stat-label">Fleet Utilization</div><div class="stat-value">73%</div><div class="stat-change">Current average</div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3>Route Performance Analysis</h3></div>
          <div class="card-body"><div class="table-container"><table><thead><tr><th>Route</th><th>Trips</th><th>Completed</th><th>Success Rate</th></tr></thead><tbody><tr><td>Colombo &rarr; Kandy</td><td>48</td><td>46</td><td><strong>95.8%</strong></td></tr><tr><td>Colombo &rarr; Galle</td><td>42</td><td>38</td><td><strong>90.5%</strong></td></tr><tr><td>Kandy &rarr; Jaffna</td><td>35</td><td>32</td><td><strong>91.4%</strong></td></tr></tbody></table></div></div>
          <div class="report-actions"><button class="btn btn-secondary" id="downloadReportPdf" type="button">Download PDF</button></div>
        </div>
      </div>
    </section>

    <!-- PROFILE MANAGEMENT -->
    <section class="page-section" id="section-profile">
      <div class="page-header">
        <div class="page-header-text"><h1>Profile Management</h1><p>Review your account details, update your profile picture, and manage system users.</p></div>
        <div class="page-header-controls"><?= $headerControls ?></div>
      </div>
      <div class="page-section-body">
        <div class="card">
          <div class="card-header"><h3>My Profile</h3></div>
          <div class="card-body">
            <div class="form-grid">
              <div class="form-control"><label>Profile Picture</label><div class="profile-picture-preview" id="profilePicturePreview"><?= htmlspecialchars($avatarInitials) ?></div><input type="file" id="profilePictureInput" accept="image/*"><button class="btn btn-secondary btn-sm" type="button" id="removeProfilePicture">Remove Picture</button></div>
              <div class="form-control"><label>Full Name</label><input type="text" id="profileFullName"></div>
              <div class="form-control"><label>Email</label><input type="email" id="profileEmail"></div>
              <div class="form-control"><label>Phone</label><input type="tel" id="profilePhone"></div>
              <div class="form-control"><label>Role</label><input type="text" id="profileRole" disabled></div>
              <div class="form-control"><label>Status</label><input type="text" id="profileStatus" disabled></div>
            </div>
            <button class="btn btn-primary" type="button" id="saveProfileBtn">Save Profile</button>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h3>System Users</h3><button class="btn btn-primary" type="button" onclick="openModal('userModal')">+ Add User</button></div>
          <div class="card-body"><div class="table-container"><table id="usersTable"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th></tr></thead><tbody></tbody></table></div></div>
        </div>
      </div>
    </section>

  </main>
</div>

<!-- MODALS -->
<!-- Driver Modal -->
<div class="modal" id="driverModal"><div class="modal-content"><div class="modal-header"><h3>Add / Edit Driver</h3><button class="close-btn" onclick="closeModal('driverModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Driver ID</label><input type="text" placeholder="e.g., DRV-001"></div><div class="form-control"><label>Full Name</label><input type="text" placeholder="e.g., Ahmed Khan"></div></div><div class="form-grid"><div class="form-control"><label>NIC Number</label><input type="text" placeholder="e.g., 123456789V"></div><div class="form-control"><label>Contact Number</label><input type="tel" placeholder="+94 701 234 567"></div></div><div class="form-control"><label>Address</label><textarea placeholder="Full address" rows="2"></textarea></div><div class="form-grid"><div class="form-control"><label>License Number</label><input type="text" placeholder="e.g., L-123456"></div><div class="form-control"><label>License Type</label><select><option>Heavy Vehicle</option><option>Light Vehicle</option><option>Motorcycle</option></select></div></div><div class="form-grid"><div class="form-control"><label>License Issue Date</label><input type="date"></div><div class="form-control"><label>License Expiry Date</label><input type="date"></div></div><div class="form-control"><label>Status</label><select><option>Active</option><option>On Leave</option><option>Inactive</option></select></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('driverModal')">Cancel</button><button class="btn btn-primary">Save Driver</button></div></div></div>

<!-- Vehicle Modal -->
<div class="modal" id="vehicleModal"><div class="modal-content"><div class="modal-header"><h3>Add / Edit Vehicle</h3><button class="close-btn" onclick="closeModal('vehicleModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Vehicle ID</label><input type="text" placeholder="e.g., VEH-001"></div><div class="form-control"><label>License Plate</label><input type="text" placeholder="e.g., WP-ABC-1234"></div></div><div class="form-grid"><div class="form-control"><label>Make</label><input type="text" placeholder="e.g., Hino"></div><div class="form-control"><label>Model</label><input type="text" placeholder="e.g., FL Series"></div></div><div class="form-grid"><div class="form-control"><label>Year</label><input type="number" placeholder="e.g., 2020"></div><div class="form-control"><label>Vehicle Type</label><select><option>Standard Bus</option><option>Express Bus</option><option>Mini Bus</option><option>School Bus</option></select></div></div><div class="form-grid"><div class="form-control"><label>Seating Capacity</label><input type="number" placeholder="e.g., 50"></div><div class="form-control"><label>Fuel Type</label><select><option>Diesel</option><option>Petrol</option><option>CNG</option></select></div></div><div class="form-grid"><div class="form-control"><label>Insurance Expiry</label><input type="date"></div><div class="form-control"><label>Status</label><select><option>Available</option><option>In Service</option><option>Under Maintenance</option></select></div></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('vehicleModal')">Cancel</button><button class="btn btn-primary">Save Vehicle</button></div></div></div>

<!-- Schedule Modal -->
<div class="modal" id="scheduleModal"><div class="modal-content"><div class="modal-header"><h3>Create Schedule</h3><button class="close-btn" onclick="closeModal('scheduleModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Schedule ID</label><input type="text" placeholder="Auto-generated"></div><div class="form-control"><label>Select Route</label><select><option>-- Select Route --</option><option>RT-001: Colombo &rarr; Kandy</option><option>RT-002: Colombo &rarr; Galle</option><option>RT-003: Kandy &rarr; Jaffna</option></select></div></div><div class="form-grid"><div class="form-control"><label>Select Driver</label><select><option>-- Select Driver --</option><option>DRV-001: Ahmed Khan</option><option>DRV-002: Ravi Kumar</option><option>DRV-003: Kumara Silva</option></select></div><div class="form-control"><label>Select Vehicle</label><select><option>-- Select Vehicle --</option><option>VEH-001: WP-ABC-1234</option><option>VEH-002: WP-DEF-5678</option><option>VEH-003: WP-GHI-9012</option></select></div></div><div class="form-grid"><div class="form-control"><label>Schedule Date</label><input type="date"></div><div class="form-control"><label>Schedule Type</label><select><option>One-off</option><option>Daily</option><option>Weekly</option></select></div></div><div class="form-grid"><div class="form-control"><label>Departure Time</label><input type="time"></div><div class="form-control"><label>Expected Arrival Time</label><input type="time"></div></div><div class="form-control"><label>Remarks / Notes</label><textarea placeholder="Any special instructions..." rows="2"></textarea></div><div class="form-control"><label>Status</label><select><option>Scheduled</option><option>In Progress</option><option>Delayed</option><option>Completed</option><option>Cancelled</option></select></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('scheduleModal')">Cancel</button><button class="btn btn-primary">Create Schedule</button></div></div></div>

<!-- Fuel Modal -->
<div class="modal" id="fuelModal"><div class="modal-content"><div class="modal-header"><h3>Log Fuel Entry</h3><button class="close-btn" onclick="closeModal('fuelModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Vehicle</label><select><option>-- Select Vehicle --</option><option>VEH-001: WP-ABC-1234</option><option>VEH-002: WP-DEF-5678</option><option>VEH-003: WP-GHI-9012</option></select></div><div class="form-control"><label>Date</label><input type="date"></div></div><div class="form-grid"><div class="form-control"><label>Fuel Quantity (Liters)</label><input type="number" placeholder="e.g., 50" step="0.1"></div><div class="form-control"><label>Fuel Cost (Rs)</label><input type="number" placeholder="e.g., 7500"></div></div><div class="form-control"><label>Odometer Reading (km)</label><input type="number" placeholder="e.g., 125430"></div><div class="form-control"><label>Trip ID (Optional)</label><input type="text" placeholder="Link to specific trip"></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('fuelModal')">Cancel</button><button class="btn btn-primary">Log Fuel</button></div></div></div>

<!-- Maintenance Modal -->
<div class="modal" id="maintenanceModal"><div class="modal-content"><div class="modal-header"><h3>Log Maintenance Activity</h3><button class="close-btn" onclick="closeModal('maintenanceModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Vehicle</label><select><option>-- Select Vehicle --</option><option>VEH-001: WP-ABC-1234</option><option>VEH-002: WP-DEF-5678</option><option>VEH-003: WP-GHI-9012</option></select></div><div class="form-control"><label>Date</label><input type="date"></div></div><div class="form-control"><label>Maintenance Type</label><select><option>Routine Service</option><option>Tire Change</option><option>Engine Repair</option><option>Brake Check</option><option>Oil Change</option><option>Other</option></select></div><div class="form-control"><label>Description</label><textarea placeholder="Describe work done..." rows="3"></textarea></div><div class="form-grid"><div class="form-control"><label>Cost (Rs)</label><input type="number" placeholder="e.g., 8000"></div><div class="form-control"><label>Mechanic / Service Center</label><input type="text" placeholder="Name"></div></div><div class="form-grid"><div class="form-control"><label>Next Service Mileage (km)</label><input type="number" placeholder="e.g., 130000"></div><div class="form-control"><label>Next Service Date</label><input type="date"></div></div><div class="form-control"><label>Status</label><select><option>Scheduled</option><option>In Progress</option><option>Completed</option><option>Cancelled</option></select></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('maintenanceModal')">Cancel</button><button class="btn btn-primary">Log Maintenance</button></div></div></div>

<!-- Route Modal -->
<div class="modal" id="routeModal"><div class="modal-content"><div class="modal-header"><h3>Add / Edit Route</h3><button class="close-btn" onclick="closeModal('routeModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Route ID</label><input type="text" placeholder="e.g., RT-001"></div><div class="form-control"><label>Route Name</label><input type="text" placeholder="e.g., Colombo - Kandy Express"></div></div><div class="form-grid"><div class="form-control"><label>Origin / Start Point</label><input type="text" placeholder="e.g., Colombo Fort"></div><div class="form-control"><label>Destination / End Point</label><input type="text" placeholder="e.g., Kandy City"></div></div><div class="form-control"><label>Intermediate Stops (comma-separated)</label><textarea placeholder="e.g., Toll Gate, Warakapola" rows="3"></textarea></div><div class="form-grid"><div class="form-control"><label>Total Distance (km)</label><input type="number" placeholder="e.g., 116"></div><div class="form-control"><label>Route Type</label><select><option>Express</option><option>Local</option><option>School</option></select></div></div><div class="form-control"><label>Route Status</label><select><option>Active</option><option>Inactive</option></select></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('routeModal')">Cancel</button><button class="btn btn-primary">Save Route</button></div></div></div>

<!-- User Modal -->
<div class="modal" id="userModal"><div class="modal-content"><div class="modal-header"><h3>Add User</h3><button class="close-btn" onclick="closeModal('userModal')">&times;</button></div><div class="modal-body"><div class="form-grid"><div class="form-control"><label>Full Name</label><input type="text"></div><div class="form-control"><label>Email</label><input type="email"></div></div><div class="form-grid"><div class="form-control"><label>Phone</label><input type="tel"></div><div class="form-control"><label>Role</label><select><option>Admin</option><option>Supervisor</option><option>Driver</option><option>Clerk</option></select></div></div><div class="form-grid"><div class="form-control"><label>Password</label><input type="password"></div><div class="form-control"><label>Status</label><select><option>Active</option><option>Inactive</option><option>Suspended</option></select></div></div></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('userModal')">Cancel</button><button class="btn btn-primary">Save User</button></div></div></div>

<!-- Trip Details Modal -->
<div class="modal" id="tripDetailsModal"><div class="modal-content"><div class="modal-header"><h3>Trip Details</h3><button class="close-btn" onclick="closeModal('tripDetailsModal')">&times;</button></div><div class="modal-body" id="tripDetailsBody"></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('tripDetailsModal')">Close</button></div></div></div>

<!-- JAVASCRIPT -->
<script>
  document.addEventListener('DOMContentLoaded', () => {
    setupNavigation();
  });

  function setupNavigation() {
    document.querySelectorAll('.nav-item[data-nav]').forEach(item => {
      item.addEventListener('click', e => {
        e.preventDefault();
        navigateTo(item.getAttribute('data-nav'));
      });
    });
  }

  function navigateTo(page) {
    document.querySelectorAll('.page-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('section-' + page)?.classList.add('active');
    document.querySelector('[data-nav="' + page + '"]')?.classList.add('active');
  }

  function toggleNotifications(event) {
    if (event) event.stopPropagation();
    const bell = event?.target?.closest('.notification-bell') || document.querySelector('.notification-bell');
    bell?.querySelector('.notification-panel')?.classList.toggle('active');
  }

  document.addEventListener('click', e => {
    if (!e.target.closest('.notification-bell')) document.querySelectorAll('.notification-panel.active').forEach(panel => panel.classList.remove('active'));
  });

  function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
  function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

  window.addEventListener('click', e => {
    if (e.target.classList.contains('modal')) e.target.classList.remove('active');
  });

  // CHANGE 3: logout goes to logout.php
  function logout() {
    window.location.href = 'logout.php';
  }
</script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/srmss-api.js"></script>
</body>
</html>
