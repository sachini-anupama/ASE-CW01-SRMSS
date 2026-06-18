<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$resource = $_GET['resource'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    ensure_runtime_schema();

    match ($resource) {
        'routes' => routes($method),
        'drivers' => drivers($method),
        'vehicles' => vehicles($method),
        'schedules' => schedules($method),
        'fuel_logs' => fuel_logs($method),
        'maintenance_logs' => maintenance_logs($method),
        'active_trips' => active_trips($method),
        'reports' => reports($method),
        'notifications' => notifications($method),
        'profile' => profile($method),
        'users' => users($method),
        'dashboard' => dashboard(),
        default => json_response(['success' => false, 'message' => 'Unknown resource.'], 404),
    };
} catch (PDOException $e) {
    json_response(['success' => false, 'message' => 'Database error.', 'detail' => $e->getMessage()], 500);
}

function ensure_runtime_schema(): void
{
    static $done = false;
    if ($done) return;

    $pdo = db();
    add_column('Vehicle', 'Make', "VARCHAR(80) NULL");
    add_column('Vehicle', 'Model', "VARCHAR(80) NULL");
    add_column('Vehicle', 'ManufactureYear', "INT NULL");
    add_column('Vehicle', 'FuelType', "VARCHAR(30) NULL DEFAULT 'Diesel'");
    add_column('Vehicle', 'InsuranceExpiry', "DATE NULL");
    add_column('Vehicle', 'Status', "ENUM('Available','In Service','Under Maintenance') NOT NULL DEFAULT 'Available'");
    add_column('Driver', 'NICNumber', "VARCHAR(20) NULL");
    add_column('Driver', 'LicenseType', "VARCHAR(60) NULL DEFAULT 'Heavy Vehicle'");
    add_column('Driver', 'LicenseIssueDate', "DATE NULL");
    add_column('Driver', 'LicenseExpiryDate', "DATE NULL");
    add_column('Schedule', 'ScheduleType', "VARCHAR(30) NULL DEFAULT 'One-off'");
    add_column('Schedule', 'Remarks', "TEXT NULL");
    add_column('FuelLog', 'TripID', "INT NULL");
    add_column('MaintenanceLog', 'NextServiceMileage', "DECIMAL(10,2) NULL");
    add_column('User', 'ProfileImage', "LONGTEXT NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS Notification (
        NotificationID INT NOT NULL AUTO_INCREMENT,
        Title VARCHAR(120) NOT NULL,
        Message VARCHAR(255) NOT NULL,
        Type ENUM('success','warning','info','danger') NOT NULL DEFAULT 'info',
        IsRead TINYINT(1) NOT NULL DEFAULT 0,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (NotificationID)
    )");

    $count = (int) $pdo->query('SELECT COUNT(*) FROM Notification')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO Notification (Title, Message, Type) VALUES (?, ?, ?)');
        $stmt->execute(['System Ready', 'SRMSS modules are connected to the live database.', 'success']);
        $stmt->execute(['Maintenance Due', 'Review upcoming maintenance records for due vehicles.', 'warning']);
        $stmt->execute(['Schedules Updated', 'Trip schedules are available for dispatch review.', 'info']);
    }

    $done = true;
}

function add_column(string $table, string $column, string $definition): void
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        db()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function routes(string $method): void
{
    $pdo = db();

    if ($method === 'GET') {
        $sql = "SELECT r.RouteID AS id,
                       CONCAT('RT-', LPAD(r.RouteID, 3, '0')) AS route_code,
                       r.RouteName AS route_name,
                       r.Origin AS origin,
                       r.Destination AS destination,
                       GROUP_CONCAT(st.StopName ORDER BY rs.StopOrder SEPARATOR ', ') AS stops,
                       r.Distance AS distance_km,
                       'Route' AS route_type,
                       r.Status AS status
                FROM Route r
                LEFT JOIN RouteStop rs ON rs.RouteID = r.RouteID
                LEFT JOIN Stop st ON st.StopID = rs.StopID
                GROUP BY r.RouteID
                ORDER BY r.RouteID";
        json_response(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['route_name', 'origin', 'destination']);
        $status = allowed($data['status'] ?? null, ['Active', 'Inactive', 'Under Review'], 'Active');

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO Route (RouteName, Origin, Destination, Distance, Status)
                 VALUES (:name, :origin, :destination, :distance, :status)'
            );
        } else {
            require_fields($data, ['id']);
            $stmt = $pdo->prepare(
                'UPDATE Route
                 SET RouteName = :name, Origin = :origin, Destination = :destination,
                     Distance = :distance, Status = :status
                 WHERE RouteID = :id'
            );
            $stmt->bindValue(':id', (int) $data['id'], PDO::PARAM_INT);
        }

        $stmt->execute([
            ':name' => $data['route_name'],
            ':origin' => $data['origin'],
            ':destination' => $data['destination'],
            ':distance' => decimal_or_null($data['distance_km'] ?? null),
            ':status' => $status,
        ] + ($method === 'PUT' ? [':id' => (int) $data['id']] : []));

        sync_route_stops((int) ($method === 'POST' ? $pdo->lastInsertId() : $data['id']), (string) ($data['stops'] ?? ''));
        notify('Route Saved', 'Route information was saved successfully.', 'success');
        json_response(['success' => true, 'message' => 'Route saved.']);
    }

    if ($method === 'DELETE') {
        delete_record('Route', 'RouteID');
    }

    method_not_allowed();
}

function sync_route_stops(int $routeId, string $stops): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM RouteStop WHERE RouteID = :id')->execute([':id' => $routeId]);
    $names = array_values(array_filter(array_map('trim', explode(',', $stops))));
    foreach ($names as $index => $name) {
        $stmt = $pdo->prepare('SELECT StopID FROM Stop WHERE StopName = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $stopId = (int) $stmt->fetchColumn();
        if (!$stopId) {
            $insert = $pdo->prepare('INSERT INTO Stop (StopName, Latitude, Longitude) VALUES (:name, 7.8731, 80.7718)');
            $insert->execute([':name' => $name]);
            $stopId = (int) $pdo->lastInsertId();
        }
        $link = $pdo->prepare('INSERT INTO RouteStop (RouteID, StopID, StopOrder) VALUES (:route, :stop, :ord)');
        $link->execute([':route' => $routeId, ':stop' => $stopId, ':ord' => $index + 1]);
    }
}

function drivers(string $method): void
{
    $pdo = db();

    if ($method === 'GET') {
        $sql = "SELECT DriverID AS id,
                       CONCAT('DRV-', LPAD(DriverID, 3, '0')) AS driver_code,
                       FullName AS full_name,
                       NICNumber AS nic_number,
                       Phone AS contact_number,
                       Address AS address,
                       LicenseNumber AS license_number,
                       LicenseType AS license_type,
                       LicenseIssueDate AS license_issue_date,
                       LicenseExpiryDate AS license_expiry_date,
                       Status AS status
                FROM Driver
                ORDER BY DriverID";
        json_response(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['full_name', 'license_number', 'contact_number']);
        $status = allowed($data['status'] ?? null, ['Active', 'Inactive', 'On Leave'], 'Active');

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO Driver
                 (FullName, NICNumber, LicenseNumber, Phone, Address, LicenseType, LicenseIssueDate, LicenseExpiryDate, Status)
                 VALUES (:name, :nic, :license, :phone, :address, :type, :issue, :expiry, :status)'
            );
        } else {
            require_fields($data, ['id']);
            $stmt = $pdo->prepare(
                'UPDATE Driver
                 SET FullName = :name, NICNumber = :nic, LicenseNumber = :license, Phone = :phone,
                     Address = :address, LicenseType = :type, LicenseIssueDate = :issue,
                     LicenseExpiryDate = :expiry, Status = :status
                 WHERE DriverID = :id'
            );
            $stmt->bindValue(':id', (int) $data['id'], PDO::PARAM_INT);
        }

        $stmt->execute([
            ':name' => $data['full_name'],
            ':nic' => $data['nic_number'] ?? null,
            ':license' => $data['license_number'],
            ':phone' => $data['contact_number'],
            ':address' => $data['address'] ?? null,
            ':type' => $data['license_type'] ?: 'Heavy Vehicle',
            ':issue' => date_or_null($data['license_issue_date'] ?? null),
            ':expiry' => date_or_null($data['license_expiry_date'] ?? null),
            ':status' => $status,
        ] + ($method === 'PUT' ? [':id' => (int) $data['id']] : []));
        notify('Driver Saved', 'Driver record was saved successfully.', 'success');
        json_response(['success' => true, 'message' => 'Driver saved.']);
    }

    if ($method === 'DELETE') {
        delete_record('Driver', 'DriverID');
    }

    method_not_allowed();
}

function vehicles(string $method): void
{
    $pdo = db();

    if ($method === 'GET') {
        $sql = "SELECT v.VehicleID AS id,
                       CONCAT('VEH-', LPAD(v.VehicleID, 3, '0')) AS vehicle_code,
                       v.VehicleNumber AS license_plate,
                       COALESCE(v.Make, v.Type) AS make,
                       v.Model AS model,
                       v.ManufactureYear AS year,
                       v.Type AS vehicle_type,
                       v.Capacity AS seating_capacity,
                       v.FuelType AS fuel_type,
                       v.InsuranceExpiry AS insurance_expiry,
                       v.Status AS status,
                       d.DepotName AS depot_name
                FROM Vehicle v
                LEFT JOIN Depot d ON d.DepotID = v.DepotID
                ORDER BY v.VehicleID";
        json_response(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['license_plate', 'vehicle_type', 'seating_capacity']);
        $status = allowed($data['status'] ?? null, ['Available', 'In Service', 'Under Maintenance'], 'Available');
        $depotId = first_id('Depot', 'DepotID');

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO Vehicle
                 (VehicleNumber, Type, Capacity, DepotID, Make, Model, ManufactureYear, FuelType, InsuranceExpiry, Status)
                 VALUES (:number, :type, :capacity, :depot, :make, :model, :year, :fuel, :insurance, :status)'
            );
        } else {
            require_fields($data, ['id']);
            $stmt = $pdo->prepare(
                'UPDATE Vehicle
                 SET VehicleNumber = :number, Type = :type, Capacity = :capacity, DepotID = :depot,
                     Make = :make, Model = :model, ManufactureYear = :year, FuelType = :fuel,
                     InsuranceExpiry = :insurance, Status = :status
                 WHERE VehicleID = :id'
            );
            $stmt->bindValue(':id', (int) $data['id'], PDO::PARAM_INT);
        }

        $stmt->execute([
            ':number' => $data['license_plate'],
            ':type' => $data['vehicle_type'] ?: ($data['make'] ?: 'Bus'),
            ':capacity' => int_or_zero($data['seating_capacity'] ?? null),
            ':depot' => $depotId,
            ':make' => $data['make'] ?? null,
            ':model' => $data['model'] ?? null,
            ':year' => int_or_null($data['year'] ?? null),
            ':fuel' => $data['fuel_type'] ?: 'Diesel',
            ':insurance' => date_or_null($data['insurance_expiry'] ?? null),
            ':status' => $status,
        ] + ($method === 'PUT' ? [':id' => (int) $data['id']] : []));
        notify('Vehicle Saved', 'Vehicle information was saved successfully.', 'success');
        json_response(['success' => true, 'message' => 'Vehicle saved.']);
    }

    if ($method === 'DELETE') {
        delete_record('Vehicle', 'VehicleID');
    }

    method_not_allowed();
}

function schedules(string $method): void
{
    $pdo = db();

    if ($method === 'GET') {
        $sql = "SELECT s.ScheduleID AS id,
                       CONCAT('SCH-', LPAD(s.ScheduleID, 3, '0')) AS schedule_code,
                       s.RouteID AS route_id,
                       s.DriverID AS driver_id,
                       s.VehicleID AS vehicle_id,
                       DATE(s.EstimatedStartTime) AS schedule_date,
                       COALESCE(s.ScheduleType, 'One-off') AS schedule_type,
                       TIME(s.EstimatedStartTime) AS departure_time,
                       TIME(s.EstimatedEndTime) AS expected_arrival_time,
                       s.Remarks AS remarks,
                       s.Status AS status,
                       r.Origin AS origin,
                       r.Destination AS destination,
                       r.RouteName AS route_name,
                       d.FullName AS driver_name,
                       v.VehicleNumber AS license_plate,
                       CONCAT('VEH-', LPAD(v.VehicleID, 3, '0')) AS vehicle_code
                FROM Schedule s
                JOIN Route r ON r.RouteID = s.RouteID
                JOIN Driver d ON d.DriverID = s.DriverID
                JOIN Vehicle v ON v.VehicleID = s.VehicleID
                ORDER BY s.EstimatedStartTime DESC";
        json_response(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['route_id', 'driver_id', 'vehicle_id', 'schedule_date', 'departure_time']);
        validate_fk('Route', 'RouteID', (int) $data['route_id']);
        validate_fk('Driver', 'DriverID', (int) $data['driver_id']);
        validate_fk('Vehicle', 'VehicleID', (int) $data['vehicle_id']);

        $status = allowed($data['status'] ?? null, ['Scheduled', 'In Progress', 'Completed', 'Cancelled', 'Delayed'], 'Scheduled');
        $start = date_time($data['schedule_date'], $data['departure_time']);
        $end = date_time($data['schedule_date'], $data['expected_arrival_time'] ?: $data['departure_time']);

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO Schedule
                 (RouteID, VehicleID, DriverID, EstimatedStartTime, EstimatedEndTime, SequenceOrder, Status, ScheduleType, Remarks)
                 VALUES (:route, :vehicle, :driver, :start, :end, :sequence, :status, :type, :remarks)'
            );
        } else {
            require_fields($data, ['id']);
            $stmt = $pdo->prepare(
                'UPDATE Schedule
                 SET RouteID = :route, VehicleID = :vehicle, DriverID = :driver,
                     EstimatedStartTime = :start, EstimatedEndTime = :end,
                     SequenceOrder = :sequence, Status = :status, ScheduleType = :type, Remarks = :remarks
                 WHERE ScheduleID = :id'
            );
            $stmt->bindValue(':id', (int) $data['id'], PDO::PARAM_INT);
        }

        $stmt->execute([
            ':route' => (int) $data['route_id'],
            ':vehicle' => (int) $data['vehicle_id'],
            ':driver' => (int) $data['driver_id'],
            ':start' => $start,
            ':end' => $end,
            ':sequence' => 1,
            ':status' => $status,
            ':type' => $data['schedule_type'] ?: 'One-off',
            ':remarks' => $data['remarks'] ?? null,
        ] + ($method === 'PUT' ? [':id' => (int) $data['id']] : []));
        notify('Schedule Saved', 'Schedule was saved successfully.', 'success');
        json_response(['success' => true, 'message' => 'Schedule saved.']);
    }

    if ($method === 'DELETE') {
        delete_record('Schedule', 'ScheduleID');
    }

    method_not_allowed();
}

function fuel_logs(string $method): void
{
    $pdo = db();

    if ($method === 'GET') {
        $sql = "SELECT f.FuelUsageID AS id,
                       f.VehicleID AS vehicle_id,
                       f.DriverID AS driver_id,
                       f.FuelDate AS fuel_date,
                       f.Liters AS quantity_liters,
                       f.Amount AS fuel_cost,
                       f.OdometerRead AS odometer_reading,
                       f.TripID AS trip_id,
                       v.VehicleNumber AS license_plate,
                       CONCAT('VEH-', LPAD(v.VehicleID, 3, '0')) AS vehicle_code
                FROM FuelLog f
                JOIN Vehicle v ON v.VehicleID = f.VehicleID
                ORDER BY f.FuelDate DESC, f.FuelUsageID DESC";
        json_response(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['vehicle_id', 'fuel_date', 'quantity_liters', 'fuel_cost']);
        validate_fk('Vehicle', 'VehicleID', (int) $data['vehicle_id']);
        $driverId = first_id('Driver', 'DriverID');

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO FuelLog (VehicleID, DriverID, FuelDate, Liters, Amount, OdometerRead, TripID)
                 VALUES (:vehicle, :driver, :date, :liters, :amount, :odometer, :trip)'
            );
        } else {
            require_fields($data, ['id']);
            $stmt = $pdo->prepare(
                'UPDATE FuelLog
                 SET VehicleID = :vehicle, DriverID = :driver, FuelDate = :date,
                     Liters = :liters, Amount = :amount, OdometerRead = :odometer, TripID = :trip
                 WHERE FuelUsageID = :id'
            );
            $stmt->bindValue(':id', (int) $data['id'], PDO::PARAM_INT);
        }

        $stmt->execute([
            ':vehicle' => (int) $data['vehicle_id'],
            ':driver' => $driverId,
            ':date' => date_or_null($data['fuel_date']),
            ':liters' => decimal_or_null($data['quantity_liters']),
            ':amount' => decimal_or_null($data['fuel_cost']),
            ':odometer' => decimal_or_null($data['odometer_reading'] ?? null),
            ':trip' => int_or_null($data['trip_id'] ?? null),
        ] + ($method === 'PUT' ? [':id' => (int) $data['id']] : []));
        notify('Fuel Log Saved', 'Fuel record was saved successfully.', 'success');
        json_response(['success' => true, 'message' => 'Fuel log saved.']);
    }

    if ($method === 'DELETE') {
        delete_record('FuelLog', 'FuelUsageID');
    }

    method_not_allowed();
}

function maintenance_logs(string $method): void
{
    $pdo = db();

    if ($method === 'GET') {
        $sql = "SELECT m.MaintenanceID AS id,
                       m.VehicleID AS vehicle_id,
                       m.MaintenanceDate AS maintenance_date,
                       m.Type AS maintenance_type,
                       m.Description AS description,
                       m.Cost AS cost,
                       m.ServiceCenter AS service_center,
                       m.NextServiceMileage AS next_service_mileage,
                       m.NextDueDate AS next_service_date,
                       m.MaintenanceStatus AS maintenance_status,
                       v.VehicleNumber AS license_plate,
                       CONCAT('VEH-', LPAD(v.VehicleID, 3, '0')) AS vehicle_code
                FROM MaintenanceLog m
                JOIN Vehicle v ON v.VehicleID = m.VehicleID
                ORDER BY m.MaintenanceDate DESC, m.MaintenanceID DESC";
        json_response(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['vehicle_id', 'maintenance_date', 'maintenance_type']);
        validate_fk('Vehicle', 'VehicleID', (int) $data['vehicle_id']);

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO MaintenanceLog
                 (VehicleID, MaintenanceDate, Type, Description, Cost, NextDueDate, MaintenanceStatus, ServiceCenter, CreatedBy, NextServiceMileage)
                 VALUES (:vehicle, :date, :type, :description, :cost, :next_due, :status, :center, :created_by, :mileage)'
            );
        } else {
            require_fields($data, ['id']);
            $stmt = $pdo->prepare(
                'UPDATE MaintenanceLog
                 SET VehicleID = :vehicle, MaintenanceDate = :date, Type = :type,
                     Description = :description, Cost = :cost, NextDueDate = :next_due,
                     MaintenanceStatus = :status, ServiceCenter = :center, CreatedBy = :created_by,
                     NextServiceMileage = :mileage
                 WHERE MaintenanceID = :id'
            );
            $stmt->bindValue(':id', (int) $data['id'], PDO::PARAM_INT);
        }

        $stmt->execute([
            ':vehicle' => (int) $data['vehicle_id'],
            ':date' => date_or_null($data['maintenance_date']),
            ':type' => $data['maintenance_type'],
            ':description' => $data['description'] ?? null,
            ':cost' => decimal_or_null($data['cost'] ?? null),
            ':next_due' => date_or_null($data['next_service_date'] ?? null),
            ':status' => allowed($data['maintenance_status'] ?? null, ['Scheduled', 'In Progress', 'Completed', 'Cancelled'], 'Scheduled'),
            ':center' => $data['service_center'] ?? null,
            ':created_by' => current_user_name(),
            ':mileage' => decimal_or_null($data['next_service_mileage'] ?? null),
        ] + ($method === 'PUT' ? [':id' => (int) $data['id']] : []));
        notify('Maintenance Saved', 'Maintenance record was saved successfully.', 'success');
        json_response(['success' => true, 'message' => 'Maintenance log saved.']);
    }

    if ($method === 'DELETE') {
        delete_record('MaintenanceLog', 'MaintenanceID');
    }

    method_not_allowed();
}

function active_trips(string $method): void
{
    if ($method !== 'GET') method_not_allowed();
    $sql = "SELECT s.ScheduleID AS id,
                   CONCAT('TRP-', LPAD(s.ScheduleID, 3, '0')) AS trip_code,
                   s.RouteID AS route_id,
                   r.RouteName AS route_name,
                   r.Origin AS origin,
                   r.Destination AS destination,
                   r.Distance AS distance_km,
                   COALESCE(stop_data.stops, '') AS stops,
                   COALESCE(stop_data.route_points, '') AS route_points,
                   d.FullName AS driver_name,
                   v.VehicleNumber AS license_plate,
                   CONCAT('VEH-', LPAD(v.VehicleID, 3, '0')) AS vehicle_code,
                   s.EstimatedStartTime AS scheduled_start,
                   s.EstimatedEndTime AS scheduled_end,
                   TIME(s.EstimatedStartTime) AS departure_time,
                   TIME(s.EstimatedEndTime) AS expected_arrival_time,
                   s.Status AS status
            FROM Schedule s
            JOIN Route r ON r.RouteID = s.RouteID
            JOIN Driver d ON d.DriverID = s.DriverID
            JOIN Vehicle v ON v.VehicleID = s.VehicleID
            LEFT JOIN (
                SELECT rs.RouteID,
                       GROUP_CONCAT(st.StopName ORDER BY rs.StopOrder SEPARATOR ', ') AS stops,
                       GROUP_CONCAT(CONCAT(st.StopName, '|', st.Latitude, '|', st.Longitude) ORDER BY rs.StopOrder SEPARATOR ';;') AS route_points
                FROM RouteStop rs
                JOIN Stop st ON st.StopID = rs.StopID
                GROUP BY rs.RouteID
            ) stop_data ON stop_data.RouteID = r.RouteID
            WHERE s.Status IN ('Scheduled', 'In Progress', 'Delayed')
            ORDER BY s.EstimatedStartTime
            LIMIT 8";
    json_response(['success' => true, 'data' => db()->query($sql)->fetchAll()]);
}

function reports(string $method): void
{
    if ($method !== 'GET') method_not_allowed();

    $type = $_GET['type'] ?? 'Route Performance';
    $from = date_or_null($_GET['from'] ?? null);
    $to = date_or_null($_GET['to'] ?? null);
    $data = report_payload($type, $from, $to);

    if (($_GET['format'] ?? '') === 'pdf') {
        $pdf = simple_pdf($data['title'], $data['lines']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="srmss-report.pdf"');
        echo $pdf;
        exit;
    }

    $stmt = db()->prepare('INSERT INTO Report (ReportType, GeneratedBy, Status) VALUES (:type, :user, :status)');
    $stmt->execute([':type' => $type ?: 'Route Performance', ':user' => first_id('User', 'UserID'), ':status' => 'Generated']);
    notify('Report Generated', "{$type} report was generated.", 'success');
    json_response(['success' => true, 'data' => $data]);
}

function report_payload(string $type, ?string $from, ?string $to): array
{
    $pdo = db();
    $type = $type ?: 'Route Performance';
    $params = report_date_params($from, $to);
    $scheduleDateSql = schedule_date_sql($from, $to);
    $fuelDateSql = dated_column_sql('f.FuelDate', $from, $to);
    $maintenanceDateSql = dated_column_sql('m.MaintenanceDate', $from, $to);

    if ($type === 'Trip Completion') {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(s.Status, ''), 'Unspecified') AS label,
                    COUNT(s.ScheduleID) AS trips,
                    SUM(s.Status = 'Completed') AS completed,
                    SUM(s.Status IN ('Scheduled','In Progress','Delayed')) AS active,
                    ROUND(AVG(COALESCE(ts.DelayMinutes, 0)), 1) AS average_delay
             FROM Schedule s
             LEFT JOIN TripStatus ts ON ts.ScheduleID = s.ScheduleID
             WHERE 1=1 {$scheduleDateSql}
             GROUP BY s.Status
             ORDER BY FIELD(s.Status, 'Completed', 'In Progress', 'Scheduled', 'Delayed', 'Cancelled'), s.Status"
        );
    } elseif ($type === 'Driver Performance') {
        $stmt = $pdo->prepare(
            "SELECT d.FullName AS label,
                    COUNT(s.ScheduleID) AS trips,
                    SUM(s.Status = 'Completed') AS completed,
                    SUM(s.Status IN ('Scheduled','In Progress','Delayed')) AS active,
                    ROUND(AVG(COALESCE(ts.DelayMinutes, 0)), 1) AS average_delay
             FROM Driver d
             LEFT JOIN Schedule s ON s.DriverID = d.DriverID {$scheduleDateSql}
             LEFT JOIN TripStatus ts ON ts.ScheduleID = s.ScheduleID
             GROUP BY d.DriverID, d.FullName
             ORDER BY d.FullName"
        );
    } elseif ($type === 'Vehicle Utilization') {
        $stmt = $pdo->prepare(
            "SELECT v.VehicleNumber AS label,
                    COUNT(s.ScheduleID) AS trips,
                    SUM(s.Status = 'Completed') AS completed,
                    SUM(s.Status IN ('Scheduled','In Progress','Delayed')) AS active,
                    ROUND(COALESCE(SUM(r.Distance), 0), 1) AS distance_km
             FROM Vehicle v
             LEFT JOIN Schedule s ON s.VehicleID = v.VehicleID {$scheduleDateSql}
             LEFT JOIN Route r ON r.RouteID = s.RouteID
             GROUP BY v.VehicleID, v.VehicleNumber
             ORDER BY v.VehicleNumber"
        );
    } elseif ($type === 'Fuel Analysis') {
        $stmt = $pdo->prepare(
            "SELECT v.VehicleNumber AS label,
                    COUNT(f.FuelUsageID) AS trips,
                    SUM(f.Liters) AS completed,
                    SUM(f.Amount) AS active,
                    ROUND(SUM(f.Amount) / NULLIF(SUM(f.Liters), 0), 2) AS cost_per_liter
             FROM Vehicle v
             LEFT JOIN FuelLog f ON f.VehicleID = v.VehicleID {$fuelDateSql}
             GROUP BY v.VehicleID, v.VehicleNumber
             ORDER BY v.VehicleNumber"
        );
    } elseif ($type === 'Maintenance Cost') {
        $stmt = $pdo->prepare(
            "SELECT v.VehicleNumber AS label,
                    COUNT(m.MaintenanceID) AS trips,
                    SUM(m.MaintenanceStatus = 'Completed') AS completed,
                    SUM(m.Cost) AS active,
                    MAX(m.NextDueDate) AS next_due
             FROM Vehicle v
             LEFT JOIN MaintenanceLog m ON m.VehicleID = v.VehicleID {$maintenanceDateSql}
             GROUP BY v.VehicleID, v.VehicleNumber
             ORDER BY v.VehicleNumber"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT r.RouteName AS label,
                    COUNT(s.ScheduleID) AS trips,
                    SUM(s.Status = 'Completed') AS completed,
                    SUM(s.Status IN ('Scheduled','In Progress','Delayed')) AS active,
                    ROUND(AVG(COALESCE(ts.DelayMinutes, 0)), 1) AS average_delay
             FROM Route r
             LEFT JOIN Schedule s ON s.RouteID = r.RouteID {$scheduleDateSql}
             LEFT JOIN TripStatus ts ON ts.ScheduleID = s.ScheduleID
             GROUP BY r.RouteID, r.RouteName
             ORDER BY r.RouteName"
        );
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['trips'] = (int) ($row['trips'] ?? 0);
        $row['completed'] = (float) ($row['completed'] ?? 0);
        $row['active'] = (float) ($row['active'] ?? 0);
    }
    unset($row);

    $totalTrips = array_sum(array_map(fn($r) => (int) $r['trips'], $rows));
    $completed = array_sum(array_map(fn($r) => (float) $r['completed'], $rows));
    $completion = $totalTrips ? round(($completed / $totalTrips) * 100, 1) : 0;
    $delayedStmt = $pdo->prepare("SELECT COUNT(*) FROM Schedule s WHERE s.Status = 'Delayed' {$scheduleDateSql}");
    $delayedStmt->execute($params);
    $delayedTrips = (int) $delayedStmt->fetchColumn();

    $lines = report_lines($type, $from, $to, $totalTrips, $completed, $completion, $rows);

    return [
        'title' => $type,
        'summary' => [
            'total_trips' => $totalTrips,
            'completed_trips' => $completed,
            'completion_rate' => $completion,
            'delayed_trips' => $delayedTrips,
            'vehicle_count' => (int) $pdo->query('SELECT COUNT(*) FROM Vehicle')->fetchColumn(),
        ],
        'rows' => $rows,
        'lines' => $lines,
    ];
}

function report_date_params(?string $from, ?string $to): array
{
    $params = [];
    if ($from) $params[':from'] = $from;
    if ($to) $params[':to'] = $to;
    return $params;
}

function schedule_date_sql(?string $from, ?string $to): string
{
    return dated_column_sql('DATE(s.EstimatedStartTime)', $from, $to);
}

function dated_column_sql(string $column, ?string $from, ?string $to): string
{
    $sql = '';
    if ($from) $sql .= " AND {$column} >= :from";
    if ($to) $sql .= " AND {$column} <= :to";
    return $sql;
}

function report_lines(string $type, ?string $from, ?string $to, int $totalTrips, float $completed, float $completion, array $rows): array
{
    $lines = [
        "Report Type: {$type}",
        'Generated: ' . date('Y-m-d H:i'),
        'Period: ' . (($from ?: 'All dates') . ' to ' . ($to ?: 'All dates')),
        "Total Records: {$totalTrips}",
        "Completed: {$completed}",
        "Completion Rate: {$completion}%",
        '',
        'Category | Records | Completed/Qty | Active/Cost | Rate'
    ];
    foreach ($rows as $row) {
        $records = (int) ($row['trips'] ?? 0);
        $done = (float) ($row['completed'] ?? 0);
        $rate = $records ? round(($done / $records) * 100, 1) : 0;
        $lines[] = "{$row['label']} | {$records} | {$done} | " . (float) ($row['active'] ?? 0) . " | {$rate}%";
    }
    return $lines;
}

function notifications(string $method): void
{
    $pdo = db();
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT NotificationID AS id, Title AS title, Message AS message, Type AS type, IsRead AS is_read, CreatedAt AS created_at FROM Notification ORDER BY CreatedAt DESC LIMIT 10")->fetchAll();
        json_response(['success' => true, 'data' => $rows]);
    }
    if ($method === 'PUT') {
        $pdo->exec('UPDATE Notification SET IsRead = 1');
        json_response(['success' => true, 'message' => 'Notifications marked as read.']);
    }
    method_not_allowed();
}

function profile(string $method): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $userId = (int) ($_SESSION['srmss_user']['id'] ?? 1);
    $pdo = db();

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT u.UserID AS id, u.FullName AS full_name, u.Email AS email, u.Phone AS phone, u.Status AS status, u.ProfileImage AS profile_image, r.RoleName AS role FROM User u JOIN Role r ON r.RoleID = u.RoleID WHERE u.UserID = :id");
        $stmt->execute([':id' => $userId]);
        json_response(['success' => true, 'data' => $stmt->fetch()]);
    }

    if ($method === 'PUT') {
        $data = clean_data(request_data());
        require_fields($data, ['full_name', 'email']);
        $stmt = $pdo->prepare('UPDATE User SET FullName = :name, Email = :email, Phone = :phone, ProfileImage = :image WHERE UserID = :id');
        $stmt->execute([
            ':name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'] ?? null,
            ':image' => $data['profile_image'] ?? null,
            ':id' => $userId,
        ]);
        $_SESSION['srmss_user']['name'] = $data['full_name'];
        $_SESSION['srmss_user']['email'] = $data['email'];
        notify('Profile Updated', 'Your profile changes were saved.', 'success');
        json_response(['success' => true, 'message' => 'Profile saved.']);
    }

    method_not_allowed();
}

function users(string $method): void
{
    $pdo = db();
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT u.UserID AS id, u.FullName AS full_name, u.Email AS email, u.Phone AS phone, u.Status AS status, r.RoleName AS role FROM User u JOIN Role r ON r.RoleID = u.RoleID ORDER BY u.UserID")->fetchAll();
        json_response(['success' => true, 'data' => $rows]);
    }
    if ($method === 'POST') {
        $data = clean_data(request_data());
        require_fields($data, ['full_name', 'email', 'password', 'role']);
        $roleId = role_id((string) $data['role']);
        $stmt = $pdo->prepare('INSERT INTO User (FullName, Email, Phone, PasswordHash, Status, RoleID) VALUES (:name, :email, :phone, :password, :status, :role)');
        $stmt->execute([
            ':name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'] ?? null,
            ':password' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            ':status' => allowed($data['status'] ?? null, ['Active', 'Inactive', 'Suspended'], 'Active'),
            ':role' => $roleId,
        ]);
        notify('User Added', 'A new user account was created.', 'success');
        json_response(['success' => true, 'message' => 'User added.']);
    }
    method_not_allowed();
}

function dashboard(): void
{
    $pdo = db();
    json_response([
        'success' => true,
        'data' => [
            'routes' => (int) $pdo->query("SELECT COUNT(*) FROM Route WHERE Status = 'Active'")->fetchColumn(),
            'drivers' => (int) $pdo->query("SELECT COUNT(*) FROM Driver WHERE Status = 'Active'")->fetchColumn(),
            'vehicles' => (int) $pdo->query('SELECT COUNT(*) FROM Vehicle')->fetchColumn(),
            'schedules_today' => (int) $pdo->query('SELECT COUNT(*) FROM Schedule WHERE DATE(EstimatedStartTime) = CURDATE()')->fetchColumn(),
            'pending_schedules' => (int) $pdo->query("SELECT COUNT(*) FROM Schedule WHERE Status IN ('Scheduled', 'Delayed')")->fetchColumn(),
            'maintenance_due' => (int) $pdo->query('SELECT COUNT(*) FROM MaintenanceLog WHERE NextDueDate IS NOT NULL AND NextDueDate <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn(),
        ],
    ]);
}

function delete_record(string $table, string $idColumn): void
{
    $id = $_GET['id'] ?? request_data()['id'] ?? null;
    if (!$id) json_response(['success' => false, 'message' => 'Missing id.'], 422);

    delete_dependencies($table, (int) $id);
    $stmt = db()->prepare("DELETE FROM {$table} WHERE {$idColumn} = :id");
    $stmt->bindValue(':id', (int) $id, PDO::PARAM_INT);
    $stmt->execute();
    notify('Record Deleted', "{$table} record was deleted.", 'info');
    json_response(['success' => true, 'message' => 'Record deleted.']);
}

function delete_dependencies(string $table, int $id): void
{
    $pdo = db();
    if ($table === 'Schedule') {
        $pdo->prepare('DELETE FROM TripStatus WHERE ScheduleID = :id')->execute([':id' => $id]);
        return;
    }
    if ($table === 'Route') {
        $stmt = $pdo->prepare('SELECT ScheduleID FROM Schedule WHERE RouteID = :id');
        $stmt->execute([':id' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $scheduleId) {
            delete_dependencies('Schedule', (int) $scheduleId);
        }
        $pdo->prepare('DELETE FROM Schedule WHERE RouteID = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM RouteStop WHERE RouteID = :id')->execute([':id' => $id]);
        return;
    }
    if ($table === 'Driver') {
        $stmt = $pdo->prepare('SELECT ScheduleID FROM Schedule WHERE DriverID = :id');
        $stmt->execute([':id' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $scheduleId) {
            delete_dependencies('Schedule', (int) $scheduleId);
        }
        $pdo->prepare('DELETE FROM Schedule WHERE DriverID = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM FuelLog WHERE DriverID = :id')->execute([':id' => $id]);
        return;
    }
    if ($table === 'Vehicle') {
        $stmt = $pdo->prepare('SELECT ScheduleID FROM Schedule WHERE VehicleID = :id');
        $stmt->execute([':id' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $scheduleId) {
            delete_dependencies('Schedule', (int) $scheduleId);
        }
        $pdo->prepare('DELETE FROM Schedule WHERE VehicleID = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM FuelLog WHERE VehicleID = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM MaintenanceLog WHERE VehicleID = :id')->execute([':id' => $id]);
    }
}

function validate_fk(string $table, string $column, int $id): void
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id");
    $stmt->execute([':id' => $id]);
    if ((int) $stmt->fetchColumn() === 0) {
        json_response(['success' => false, 'message' => "Invalid {$table} reference."], 422);
    }
}

function role_id(string $role): int
{
    $stmt = db()->prepare('SELECT RoleID FROM Role WHERE RoleName = :role LIMIT 1');
    $stmt->execute([':role' => $role]);
    $id = (int) $stmt->fetchColumn();
    if (!$id) json_response(['success' => false, 'message' => 'Invalid role.'], 422);
    return $id;
}

function allowed(?string $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

function date_time(?string $date, ?string $time): string
{
    $time = $time ?: '00:00';
    if (substr_count($time, ':') === 1) $time .= ':00';
    return trim(($date ?: date('Y-m-d')) . ' ' . $time);
}

function date_or_null($value): ?string
{
    return $value ? (string) $value : null;
}

function decimal_or_null($value): ?string
{
    return is_numeric($value) ? (string) $value : null;
}

function int_or_null($value): ?int
{
    return is_numeric($value) ? (int) $value : null;
}

function int_or_zero($value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function notify(string $title, string $message, string $type = 'info'): void
{
    $stmt = db()->prepare('INSERT INTO Notification (Title, Message, Type) VALUES (:title, :message, :type)');
    $stmt->execute([':title' => $title, ':message' => $message, ':type' => allowed($type, ['success', 'warning', 'info', 'danger'], 'info')]);
}

function current_user_name(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    return (string) ($_SESSION['srmss_user']['name'] ?? 'Admin User');
}

function simple_pdf(string $title, array $lines): string
{
    $ops = [];
    $ops[] = '0.08 0.23 0.45 rg 0 752 612 40 re f';
    $ops[] = 'BT /F1 18 Tf 42 766 Td 1 1 1 rg (' . pdf_text($title) . ') Tj ET';
    $ops[] = 'BT /F1 9 Tf 42 735 Td 0.25 0.25 0.25 rg (Generated by Schedulix SRMSS) Tj ET';

    $y = 710;
    foreach (array_slice($lines, 0, 6) as $line) {
        $ops[] = 'BT /F1 10 Tf 42 ' . $y . ' Td 0 0 0 rg (' . pdf_text((string) $line, 95) . ') Tj ET';
        $y -= 16;
    }

    $y -= 8;
    $ops[] = '0.93 0.95 0.98 rg 38 ' . ($y - 8) . ' 536 24 re f';
    $ops[] = '0.55 0.60 0.68 RG 38 ' . ($y - 8) . ' 536 24 re S';
    $ops[] = 'BT /F1 9 Tf 46 ' . $y . ' Td 0.08 0.23 0.45 rg (Category) Tj 196 0 Td (Records) Tj 72 0 Td (Completed/Qty) Tj 94 0 Td (Active/Cost) Tj 86 0 Td (Rate) Tj ET';
    $y -= 24;

    foreach (array_slice($lines, 8, 24) as $index => $line) {
        $parts = array_map('trim', explode('|', (string) $line));
        while (count($parts) < 5) $parts[] = '';
        if ($index % 2 === 0) {
            $ops[] = '0.98 0.99 1 rg 38 ' . ($y - 8) . ' 536 22 re f';
        }
        $ops[] = '0.86 0.88 0.92 RG 38 ' . ($y - 8) . ' 536 22 re S';
        $ops[] = 'BT /F1 8 Tf 46 ' . $y . ' Td 0.08 0.08 0.08 rg (' . pdf_text($parts[0], 30) . ') Tj 196 0 Td (' . pdf_text($parts[1], 12) . ') Tj 72 0 Td (' . pdf_text($parts[2], 14) . ') Tj 94 0 Td (' . pdf_text($parts[3], 14) . ') Tj 86 0 Td (' . pdf_text($parts[4], 12) . ') Tj ET';
        $y -= 22;
        if ($y < 70) break;
    }
    $ops[] = 'BT /F1 8 Tf 42 36 Td 0.35 0.35 0.35 rg (Displayed report data and exported PDF are generated from the same filtered database query.) Tj ET';
    $content = implode("\n", $ops);
    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}

function pdf_text(string $text, int $limit = 120): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], substr($text, 0, $limit));
}

function method_not_allowed(): void
{
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
