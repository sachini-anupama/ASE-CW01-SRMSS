CREATE DATABASE IF NOT EXISTS srmss_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE srmss_db;

CREATE TABLE Role (
    RoleID      INT             NOT NULL AUTO_INCREMENT,
    RoleName    VARCHAR(50)     NOT NULL UNIQUE,
    PRIMARY KEY (RoleID)
);

CREATE TABLE Depot (
    DepotID         INT             NOT NULL AUTO_INCREMENT,
    DepotName       VARCHAR(100)    NOT NULL,
    Location        VARCHAR(200)    NOT NULL,
    ContactNumber   VARCHAR(20)     NOT NULL,
    PRIMARY KEY (DepotID)
);

CREATE TABLE User (
    UserID          INT             NOT NULL AUTO_INCREMENT,
    FullName        VARCHAR(100)    NOT NULL,
    Email           VARCHAR(150)    NOT NULL UNIQUE,
    Phone           VARCHAR(20),
    PasswordHash    VARCHAR(255)    NOT NULL,
    Status          ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
    RoleID          INT             NOT NULL,
    CreatedDate     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (UserID),
    CONSTRAINT fk_user_role FOREIGN KEY (RoleID) REFERENCES Role(RoleID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE Vehicle (
    VehicleID       INT             NOT NULL AUTO_INCREMENT,
    VehicleNumber   VARCHAR(20)     NOT NULL UNIQUE,
    Type            VARCHAR(50)     NOT NULL,
    Capacity        INT             NOT NULL,
    DepotID         INT             NOT NULL,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (VehicleID),
    CONSTRAINT fk_vehicle_depot FOREIGN KEY (DepotID) REFERENCES Depot(DepotID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE Driver (
    DriverID        INT             NOT NULL AUTO_INCREMENT,
    FullName        VARCHAR(100)    NOT NULL,
    LicenseNumber   VARCHAR(50)     NOT NULL UNIQUE,
    Phone           VARCHAR(20)     NOT NULL,
    Address         VARCHAR(255),
    Status          ENUM('Active', 'Inactive', 'On Leave') NOT NULL DEFAULT 'Active',
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (DriverID)
);

CREATE TABLE FuelLog (
    FuelUsageID     INT             NOT NULL AUTO_INCREMENT,
    VehicleID       INT             NOT NULL,
    DriverID        INT             NOT NULL,
    FuelDate        DATE            NOT NULL,
    Liters          DECIMAL(8,2)    NOT NULL,
    Amount          DECIMAL(10,2)   NOT NULL,
    OdometerRead    DECIMAL(10,2),
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (FuelUsageID),
    CONSTRAINT fk_fuellog_vehicle FOREIGN KEY (VehicleID) REFERENCES Vehicle(VehicleID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_fuellog_driver FOREIGN KEY (DriverID) REFERENCES Driver(DriverID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE MaintenanceLog (
    MaintenanceID       INT             NOT NULL AUTO_INCREMENT,
    VehicleID           INT             NOT NULL,
    MaintenanceDate     DATE            NOT NULL,
    Type                VARCHAR(100)    NOT NULL,
    Description         TEXT,
    Cost                DECIMAL(10,2),
    NextDueDate         DATE,
    MaintenanceStatus   ENUM('Scheduled', 'In Progress', 'Completed', 'Cancelled')
                        NOT NULL DEFAULT 'Scheduled',
    ServiceCenter       VARCHAR(150),
    CreatedBy           VARCHAR(100),
    CreatedAt           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MaintenanceID),
    CONSTRAINT fk_maintenance_vehicle FOREIGN KEY (VehicleID) REFERENCES Vehicle(VehicleID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE Stop (
    StopID      INT             NOT NULL AUTO_INCREMENT,
    StopName    VARCHAR(150)    NOT NULL,
    Latitude    DECIMAL(10,7)   NOT NULL,
    Longitude   DECIMAL(10,7)   NOT NULL,
    PRIMARY KEY (StopID)
);

CREATE TABLE Route (
    RouteID     INT             NOT NULL AUTO_INCREMENT,
    RouteName   VARCHAR(150)    NOT NULL,
    Origin      VARCHAR(150)    NOT NULL,
    Destination VARCHAR(150)    NOT NULL,
    Distance    DECIMAL(8,2),
    Status      ENUM('Active', 'Inactive', 'Under Review') NOT NULL DEFAULT 'Active',
    CreatedAt   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (RouteID)
);

CREATE TABLE RouteStop (
    RouteStopID             INT             NOT NULL AUTO_INCREMENT,
    RouteID                 INT             NOT NULL,
    StopID                  INT             NOT NULL,
    StopOrder               INT             NOT NULL,
    EstimatedArrivalTime    TIME,
    TripFee                 DECIMAL(8,2),
    PRIMARY KEY (RouteStopID),
    UNIQUE KEY uq_route_stop_order (RouteID, StopOrder),
    CONSTRAINT fk_routestop_route FOREIGN KEY (RouteID) REFERENCES Route(RouteID)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_routestop_stop FOREIGN KEY (StopID) REFERENCES Stop(StopID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


CREATE TABLE Schedule (
    ScheduleID          INT             NOT NULL AUTO_INCREMENT,
    RouteID             INT             NOT NULL,
    VehicleID           INT             NOT NULL,
    DriverID            INT             NOT NULL,
    EstimatedStartTime  DATETIME        NOT NULL,
    EstimatedEndTime    DATETIME        NOT NULL,
    SequenceOrder       INT,
    Status              ENUM('Scheduled', 'In Progress', 'Completed',
                             'Cancelled', 'Delayed')
                        NOT NULL DEFAULT 'Scheduled',
    PRIMARY KEY (ScheduleID),
    CONSTRAINT fk_schedule_route   FOREIGN KEY (RouteID)   REFERENCES Route(RouteID)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedule_vehicle FOREIGN KEY (VehicleID) REFERENCES Vehicle(VehicleID)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedule_driver  FOREIGN KEY (DriverID)  REFERENCES Driver(DriverID)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE TripStatus (
    TripStatusID    INT             NOT NULL AUTO_INCREMENT,
    ScheduleID      INT             NOT NULL,
    Status          ENUM('On Time', 'Delayed', 'Completed', 'Cancelled')
                    NOT NULL DEFAULT 'On Time',
    ActualDeparture DATETIME,
    ActualArrival   DATETIME,
    DelayMinutes    INT             DEFAULT 0,
    PRIMARY KEY (TripStatusID),
    CONSTRAINT fk_tripstatus_schedule FOREIGN KEY (ScheduleID) REFERENCES Schedule(ScheduleID)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE Report (
    ReportID        INT             NOT NULL AUTO_INCREMENT,
    ReportType      VARCHAR(100)    NOT NULL,
    GeneratedDate   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    GeneratedBy     INT             NOT NULL,
    Status          ENUM('Generated', 'Pending', 'Failed') NOT NULL DEFAULT 'Generated',
    PRIMARY KEY (ReportID),
    CONSTRAINT fk_report_user FOREIGN KEY (GeneratedBy) REFERENCES User(UserID)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


INSERT INTO Role (RoleName) VALUES
('Admin'),
('Supervisor'),
('Driver'),
('Clerk');


INSERT INTO Depot (DepotName, Location, ContactNumber) VALUES
('Colombo Central Depot', 'Colombo 10', '0112345678'),
('Kandy Depot', 'Kandy City', '0812345678'),
('Galle Depot', 'Galle Fort Area', '0912345678');


INSERT INTO User (FullName, Email, Phone, PasswordHash, Status, RoleID) VALUES
('Kamal Perera', 'kamal@srmss.lk', '0771234567', 'hashed_password_1', 'Active', 1),
('Nimal Silva',  'nimal@srmss.lk', '0712345678', 'hashed_password_2', 'Active', 2),
('Sunil Bandara','sunil@srmss.lk', '0761234567', 'hashed_password_3', 'Active', 4);


INSERT INTO Vehicle (VehicleNumber, Type, Capacity, DepotID) VALUES
('NB-1234', 'Bus',      52, 1),
('NB-5678', 'Mini Bus', 28, 1),
('NC-9999', 'Bus',      52, 2);


INSERT INTO Driver (FullName, LicenseNumber, Phone, Address, Status) VALUES
('Aravinda Rajapaksa', 'LIC-001-2020', '0776543210', 'No 5, Galle Road, Colombo', 'Active'),
('Chaminda Fernando',  'LIC-002-2021', '0777654321', 'No 12, Kandy Road, Kandy',  'Active'),
('Roshan Wickrama',    'LIC-003-2019', '0778765432', 'No 3, Marine Drive, Galle', 'Active');


INSERT INTO Stop (StopName, Latitude, Longitude) VALUES
('Colombo Fort',    6.9344000, 79.8428000),
('Pettah',         6.9404000, 79.8574000),
('Maradana',       6.9210000, 79.8637000),
('Nugegoda',       6.8742000, 79.8897000),
('Maharagama',     6.8480000, 79.9270000);


INSERT INTO Route (RouteName, Origin, Destination, Distance, Status) VALUES
('Colombo Fort - Maharagama', 'Colombo Fort', 'Maharagama', 14.5, 'Active'),
('Colombo Fort - Kandy',      'Colombo Fort', 'Kandy',      115.0,'Active');


INSERT INTO RouteStop (RouteID, StopID, StopOrder, EstimatedArrivalTime, TripFee) VALUES
(1, 1, 1, '06:00:00', 0.00),
(1, 2, 2, '06:10:00', 15.00),
(1, 3, 3, '06:20:00', 20.00),
(1, 4, 4, '06:35:00', 35.00),
(1, 5, 5, '06:50:00', 45.00);


INSERT INTO Schedule (RouteID, VehicleID, DriverID, EstimatedStartTime, EstimatedEndTime, SequenceOrder, Status) VALUES
(1, 1, 1, '2025-06-01 06:00:00', '2025-06-01 06:50:00', 1, 'Scheduled'),
(1, 2, 2, '2025-06-01 07:00:00', '2025-06-01 07:50:00', 2, 'Scheduled'),
(2, 3, 3, '2025-06-01 08:00:00', '2025-06-01 11:30:00', 1, 'Scheduled');


INSERT INTO TripStatus (ScheduleID, Status, ActualDeparture, ActualArrival, DelayMinutes) VALUES
(1, 'Completed', '2025-06-01 06:02:00', '2025-06-01 06:55:00', 5),
(2, 'On Time',   '2025-06-01 07:00:00', NULL, 0);


INSERT INTO FuelLog (VehicleID, DriverID, FuelDate, Liters, Amount, OdometerRead) VALUES
(1, 1, '2025-06-01', 45.50, 13650.00, 12450.00),
(2, 2, '2025-06-01', 28.00,  8400.00, 8750.00);


INSERT INTO MaintenanceLog (VehicleID, MaintenanceDate, Type, Description, Cost, NextDueDate, MaintenanceStatus, ServiceCenter, CreatedBy) VALUES
(1, '2025-05-15', 'Routine Service', 'Oil change and filter replacement', 8500.00, '2025-08-15', 'Completed', 'Lanka Motors', 'Kamal Perera'),
(3, '2025-05-20', 'Tyre Replacement', 'Front two tyres replaced',         25000.00, '2027-05-20', 'Completed', 'Ceat Tyres',   'Nimal Silva');


INSERT INTO Report (ReportType, GeneratedBy, Status) VALUES
('Monthly Trip Report',      1, 'Generated'),
('Fuel Consumption Report',  1, 'Generated'),
('Maintenance Summary',      2, 'Generated');

CREATE OR REPLACE VIEW vw_ScheduleDetails AS
SELECT
    s.ScheduleID,
    r.RouteName,
    r.Origin,
    r.Destination,
    v.VehicleNumber,
    v.Type AS VehicleType,
    d.FullName   AS DriverName,
    d.LicenseNumber,
    s.EstimatedStartTime,
    s.EstimatedEndTime,
    s.Status
FROM Schedule s
JOIN Route   r ON s.RouteID   = r.RouteID
JOIN Vehicle v ON s.VehicleID = v.VehicleID
JOIN Driver  d ON s.DriverID  = d.DriverID;

CREATE OR REPLACE VIEW vw_TripStatus AS
SELECT
    ts.TripStatusID,
    sd.RouteName,
    sd.VehicleNumber,
    sd.DriverName,
    sd.EstimatedStartTime,
    ts.ActualDeparture,
    ts.ActualArrival,
    ts.Status,
    ts.DelayMinutes
FROM TripStatus ts
JOIN vw_ScheduleDetails sd ON ts.ScheduleID = sd.ScheduleID;

CREATE OR REPLACE VIEW vw_FuelSummary AS
SELECT
    v.VehicleNumber,
    v.Type,
    SUM(fl.Liters) AS TotalLiters,
    SUM(fl.Amount) AS TotalCost,
    COUNT(fl.FuelUsageID) AS RefillCount
FROM Vehicle v
LEFT JOIN FuelLog fl ON v.VehicleID = fl.VehicleID
GROUP BY v.VehicleID, v.VehicleNumber, v.Type;


SHOW TABLES;

SELECT 'Database setup complete!' AS Message;
