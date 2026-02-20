<?php
// config/database.php - Centralized Database Configuration
// Updated: 2026-01-15

class Database {
    private static $instance = null;
    private $connection;
    private $is_connected = false;

    private $host = "localhost";
    private $username = "root";
    private $password = "";
    private $database = "bec_equipment_db";

    private function __construct() {
        try {
            $this->connection = new mysqli($this->host, $this->username, $this->password, $this->database);
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            $this->connection->set_charset("utf8mb4");
            $this->connection->query("SET time_zone = '+08:00';");
            $this->is_connected = true;
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection failed. Please contact support.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function __destruct() {
        if ($this->connection && $this->is_connected) {
            try {
                if ($this->connection instanceof mysqli) {
                    $this->connection->close();
                }
            } catch (Throwable $e) {
                // Connection already closed or invalid, ignore
            }
            $this->is_connected = false;
        }
    }
}

// Helper function for backwards compatibility
function getDBConnection() {
    return Database::getInstance()->getConnection();
}

// ============================================
// EQUIPMENT FUNCTIONS
// ============================================

function getAllEquipment() {
    $conn = getDBConnection();
    $sql = "SELECT e.*, c.category_name
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status != 'deleted'
            ORDER BY e.equipment_name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getAllCategories() {
    $conn = getDBConnection();
    $sql = "SELECT * FROM categories ORDER BY category_name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getEquipmentById($equipment_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT e.*, c.category_name
                            FROM equipment e
                            LEFT JOIN categories c ON e.category_id = c.category_id
                            WHERE e.equipment_id = ?");
    $stmt->bind_param("s", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $equipment = $result->fetch_assoc();
    $stmt->close();
    return $equipment;
}

/**
 * Get admin inventory data from hardcoded arrays
 */
function getAdminInventoryData() {
    // Try to load from JSON file first
    $inventoryFile = '../data/inventory.json';
    if (file_exists($inventoryFile)) {
        $jsonData = file_get_contents($inventoryFile);
        if ($jsonData !== false) {
            $data = json_decode($jsonData, true);
            if ($data && is_array($data) && !empty($data)) {
                return $data;
            }
        }
    }

    // Fall back to hardcoded data if JSON file doesn't exist or is empty
    // Include the hardcoded inventory data from admin_inventory.php
    // We'll duplicate the data here to avoid including the entire file

    $ACData = [];
    // Main Campus (41 units)
    for ($i = 0; $i < 41; $i++) {
        $ACData[] = [
            'id' => $i + 1,
            'propertyNo' => 'A-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => "Main Campus",
            'building' => $i < 2 ? "Building 1" : ($i < 3 ? "Building 2" : ($i < 11 ? "Building 3" : ($i < 26 ? "Building 4" : ($i < 33 ? "Building 5" : ($i < 36 ? "Building 6" : ($i < 38 ? "Building 9" : "Building 22")))))),
            'buildingName' => $i < 2 ? "Gymnasium" : ($i < 3 ? "Faculty & Student Center" : ($i < 11 ? "Learning Resource Building" : ($i < 26 ? "Diamond Building" : ($i < 33 ? "TLE Building" : ($i < 36 ? "Canteen & Support Services" : ($i < 38 ? "Temporary Building 2" : "Temporary Building")))))),
            'room' => "Room " . ($i + 1),
            'article' => ["Carrier", "Panasonic", "Kolin"][$i % 3],
            'qty' => 1,
            'status' => $i % 10 === 0 ? "New" : "Active"
        ];
    }
    // Annex 1 Campus (72 units)
    for ($i = 0; $i < 72; $i++) {
        $ACData[] = [
            'id' => $i + 42,
            'propertyNo' => 'A-0825-' . str_pad($i + 42, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 1 Campus",
            'building' => $i < 9 ? "Building 12" : ($i < 59 ? "Building 13" : ($i < 64 ? "Building 15" : ($i < 69 ? "Building 17" : "Building 18"))),
            'buildingName' => $i < 9 ? "Admin Services Building" : ($i < 59 ? "BEC Skills Training Center" : ($i < 64 ? "Pre-school Building" : ($i < 69 ? "Grade School Building 1" : "Grade School Building 2"))),
            'room' => "Room " . ($i + 1),
            'article' => ["Carrier", "Panasonic", "Kolin"][$i % 3],
            'qty' => 1,
            'status' => $i % 8 === 0 ? "New" : "Active"
        ];
    }
    // Annex 2 Campus (59 units)
    for ($i = 0; $i < 59; $i++) {
        $ACData[] = [
            'id' => $i + 114,
            'propertyNo' => 'A-0825-' . str_pad($i + 114, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 2 Campus",
            'building' => $i < 16 ? "Building 21" : ($i < 27 ? "SPC Building" : "GA Building"),
            'buildingName' => $i < 16 ? "Annex 2 Temporary Building" : ($i < 27 ? "SPC Bldg. TESDA" : "GA Bldg. - SHS"),
            'room' => "Classroom " . ($i + 127),
            'article' => ["Carrier", "Panasonic", "Kelvinator"][$i % 3],
            'qty' => 1,
            'status' => $i % 7 === 0 ? "New" : "Active"
        ];
    }

    $TVData = [];
    // Main Campus (20 TVs)
    for ($i = 0; $i < 20; $i++) {
        $TVData[] = [
            'id' => $i + 1,
            'propertyNo' => 'T-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => "Main Campus",
            'building' => $i < 2 ? "Building 3" : ($i < 10 ? "Building 4" : ($i < 19 ? "Building 7" : "Building 9")),
            'buildingName' => $i < 2 ? "Learning Resource Building" : ($i < 10 ? "Diamond Building" : ($i < 19 ? "Old HS Building" : "Temporary Building 2")),
            'room' => "Room " . ($i + 100),
            'article' => ["TCL", "Skyworth", "Prestiz"][$i % 3],
            'qty' => 1,
            'status' => $i % 6 === 0 ? "New" : "Active"
        ];
    }
    // Annex 1 Campus (42 TVs)
    for ($i = 0; $i < 42; $i++) {
        $TVData[] = [
            'id' => $i + 21,
            'propertyNo' => 'T-0825-' . str_pad($i + 21, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 1 Campus",
            'building' => $i < 28 ? "Building 13" : ($i < 31 ? "Building 15" : "Building 17"),
            'buildingName' => $i < 28 ? "BEC Skills Training Center" : ($i < 31 ? "Pre-school Building" : "Grade School Building 1"),
            'room' => "BEC Skills Training Center/Room " . ($i + 100),
            'article' => ["TCL", "Skyworth", "Samsung"][$i % 3],
            'qty' => 1,
            'status' => $i % 8 === 0 ? "New" : "Active"
        ];
    }
    // Annex 2 Campus (26 TVs)
    for ($i = 0; $i < 26; $i++) {
        $TVData[] = [
            'id' => $i + 60,
            'propertyNo' => 'T-0825-' . str_pad($i + 60, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 2 Campus",
            'building' => $i < 8 ? "Building 21" : ($i < 17 ? "SPC Building" : "GA Building"),
            'buildingName' => $i < 8 ? "Annex 2 Temporary Building" : ($i < 17 ? "SPC Bldg. TESDA" : "GA Bldg. - SHS"),
            'room' => "Classroom " . ($i + 127),
            'article' => ["TCL", "Skyworth", "Prestiz"][$i % 3],
            'qty' => 1,
            'status' => $i % 5 === 0 ? "New" : "Active"
        ];
    }

    $FanData = [];
    // Main Campus (118 fans)
    for ($i = 0; $i < 118; $i++) {
        $FanData[] = [
            'id' => $i + 1,
            'propertyNo' => ($i % 3 === 0 ? 'CF' : ($i % 3 === 1 ? 'SF' : 'IF')) . '-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => "Main Campus",
            'building' => ["Main Gate", "Building 1", "Building 2", "Building 3", "Building 4", "Building 5", "Building 6", "Building 7", "Building 9", "Building 22"][$i % 10],
            'buildingName' => ["Main Gate", "Gymnasium", "Faculty & Student Center", "Learning Resource Building", "Diamond Building", "TLE Building", "Canteen", "Old HS Building", "Temporary Building 2", "Temporary Building"][$i % 10],
            'room' => "Room " . ($i + 1),
            'type' => $i % 3 === 0 ? "Ceiling Fan" : ($i % 3 === 1 ? "Stand Fan" : "Industrial Fan"),
            'qty' => 1,
            'status' => $i % 12 === 0 ? "Not Working" : "Active"
        ];
    }
    // Annex 1 Campus (102 fans)
    for ($i = 0; $i < 102; $i++) {
        $FanData[] = [
            'id' => $i + 119,
            'propertyNo' => ($i % 3 === 0 ? 'CF' : ($i % 3 === 1 ? 'SF' : 'RF')) . '-0825-' . str_pad($i + 119, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 1 Campus",
            'building' => $i < 70 ? "Building 13" : ($i < 80 ? "Building 15" : "Building 17"),
            'buildingName' => $i < 70 ? "BEC Skills Training Center" : ($i < 80 ? "Pre-school Building" : "Grade School Building 1"),
            'room' => "Room " . ($i + 100),
            'type' => $i % 3 === 0 ? "Ceiling Fan" : ($i % 3 === 1 ? "Stand Fan" : "Rotary Fan"),
            'qty' => 1,
            'status' => $i % 10 === 0 ? "Not Working" : "Active"
        ];
    }
    // Annex 2 Campus (62 fans)
    for ($i = 0; $i < 62; $i++) {
        $FanData[] = [
            'id' => $i + 221,
            'propertyNo' => ($i % 2 === 0 ? 'CF' : 'SF') . '-0825-' . str_pad($i + 221, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 2 Campus",
            'building' => $i < 32 ? "Building 21" : ($i < 45 ? "SPC Building" : "GA Building"),
            'buildingName' => $i < 32 ? "Annex 2 Temporary Building" : ($i < 45 ? "SPC Bldg. TESDA" : "GA Bldg. - SHS"),
            'room' => "Room " . ($i + 127),
            'type' => $i % 2 === 0 ? "Ceiling Fan" : "Stand Fan",
            'qty' => 1,
            'status' => $i % 9 === 0 ? "Not Working" : "Active"
        ];
    }

    $WhiteboardData = [];
    for ($i = 0; $i < 52; $i++) {
        $WhiteboardData[] = [
            'id' => $i + 1,
            'propertyNo' => 'WB-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => $i < 9 ? "Main Campus" : ($i < 40 ? "Annex 1 Campus" : "Annex 2 Campus"),
            'building' => $i < 3 ? "Building 2" : ($i < 6 ? "Building 4" : ($i < 9 ? "Building 5" : ($i < 30 ? "Building 13" : ($i < 34 ? "Building 18" : ($i < 40 ? "SPC Building" : "GA Building"))))),
            'buildingName' => $i < 3 ? "Faculty & Student Center" : ($i < 6 ? "Diamond Building" : ($i < 9 ? "TLE Building" : ($i < 30 ? "BEC Skills Training Center" : ($i < 34 ? "Grade School Building 2" : ($i < 40 ? "SPC Bldg. TESDA" : "GA Bldg. - SHS"))))),
            'room' => "Room " . ($i + 1),
            'size' => $i % 3 === 0 ? "Big" : ($i % 3 === 1 ? "Medium" : "Small"),
            'classification' => $i % 4 === 0 ? "Glassboard" : "Whiteboard",
            'qty' => 1,
            'status' => $i % 15 === 0 ? "Broken" : ($i % 11 === 0 ? "Faded" : ($i % 7 === 0 ? "New" : "Active"))
        ];
    }

    $LockerData = [];
    for ($i = 0; $i < 71; $i++) {
        $LockerData[] = [
            'id' => $i + 1,
            'propertyNo' => 'L-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => $i < 18 ? "Main Campus" : "Annex 1 Campus",
            'building' => $i < 6 ? "Main Gate" : ($i < 18 ? "Building 2" : "Building 13"),
            'buildingName' => $i < 6 ? "Main Gate" : ($i < 18 ? "Faculty & Student Center / Learning Resource Building" : "BEC Skills Training Center"),
            'room' => $i < 6 ? "Corridor" : ($i < 11 ? "College Faculty Office" : ($i < 18 ? "Junior HS Faculty Office" : "Locker & Lavatory Area")),
            'slots' => $i % 4 === 0 ? "15 (3x5)" : ($i % 4 === 1 ? "12 (3x4)" : "6 (3x2)"),
            'type' => $i % 3 === 0 ? "Steel-Grey" : ($i % 3 === 1 ? "Steel-White" : "Green"),
            'qty' => 1,
            'status' => "Active"
        ];
    }

    $OfficeChairData = [];
    for ($i = 0; $i < 108; $i++) {
        $OfficeChairData[] = [
            'id' => $i + 1,
            'propertyNo' => 'OC-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => $i < 24 ? "Main Campus" : ($i < 59 ? "Annex 1 Campus" : "Annex 2 Campus"),
            'building' => $i < 7 ? "Building 1" : ($i < 11 ? "Building 2" : ($i < 12 ? "Building 4" : ($i < 24 ? "Building 6" : ($i < 43 ? "Building 12" : ($i < 55 ? "Building 13" : ($i < 59 ? "Building 17" : "SPC Building")))))),
            'buildingName' => $i < 7 ? "Gymnasium" : ($i < 11 ? "Faculty & Student Center" : ($i < 12 ? "Diamond Building" : ($i < 24 ? "Canteen & Support Services" : ($i < 43 ? "Admin Services Building" : ($i < 55 ? "BEC Skills Training Center" : ($i < 59 ? "Grade School Building 1" : "SPC Bldg. TESDA")))))),
            'room' => "Office " . ($i + 1),
            'type' => $i % 5 === 0 ? "Executive" : "Ordinary",
            'color' => $i % 20 === 0 ? "White" : "Black",
            'qty' => 1,
            'status' => $i % 25 === 0 ? "Damaged" : "Active"
        ];
    }

    $ComputerData = [];
    // Computer Laboratory - Annex 1 Campus Building 13 (40 units) - BEC Skills Training Center 102
    for ($i = 0; $i < 40; $i++) {
        $ComputerData[] = [
            'id' => $i + 1,
            'propertyNo' => 'PC-0825-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 1 Campus",
            'building' => "BEC Skills Training Center",
            'buildingName' => "BEC Skills Training Center",
            'room' => "BEC Skills Training Center 102",
            'article' => ["HP", "Dell", "Lenovo", "Acer", "ASUS"][$i % 5],
            'model' => ["Pavilion", "Inspiron", "ThinkCentre", "Aspire", "VivoBook"][$i % 5],
            'serialNo' => 'SN' . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'qty' => 1,
            'status' => $i % 15 === 0 ? "Not Working" : ($i % 20 === 0 ? "Under Repair" : "Active"),
            'remarks' => $i % 15 === 0 ? "Not Working - needs repair" : ""
        ];
    }
    // Software Laboratory - Annex 1 Campus Building 13 (40 units) - BEC Skills Training Center 203
    for ($i = 0; $i < 40; $i++) {
        $ComputerData[] = [
            'id' => $i + 41,
            'propertyNo' => 'PC-0825-' . str_pad($i + 41, 4, '0', STR_PAD_LEFT),
            'campus' => "Annex 1 Campus",
            'building' => "BEC Skills Training Center",
            'buildingName' => "BEC Skills Training Center",
            'room' => "BEC Skills Training Center 203",
            'article' => ["HP", "Dell", "Lenovo", "Acer", "ASUS"][$i % 5],
            'model' => ["EliteDesk", "OptiPlex", "IdeaCentre", "Veriton", "ExpertCenter"][$i % 5],
            'serialNo' => 'SN' . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'qty' => 1,
            'status' => $i % 18 === 0 ? "Not Working" : ($i % 25 === 0 ? "Under Repair" : "Active"),
            'remarks' => $i % 18 === 0 ? "Not Working - needs repair" : ""
        ];
    }

    return [
        'airConditioners' => $ACData,
        'televisions' => $TVData,
        'fans' => $FanData,
        'whiteboards' => $WhiteboardData,
        'lockers' => $LockerData,
        'officeChairs' => $OfficeChairData,
        'computers' => $ComputerData
    ];
}

/**
 * Save admin inventory data to JSON file
 */
function saveAdminInventoryData($inventoryData) {
    $inventoryFile = '../data/inventory.json';

    // Ensure the data directory exists
    $dataDir = dirname($inventoryFile);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    // Save the data to JSON file
    $jsonData = json_encode($inventoryData, JSON_PRETTY_PRINT);
    if (file_put_contents($inventoryFile, $jsonData) !== false) {
        return true;
    }

    return false;
}

/**
 * Update a specific inventory item
 */
function updateInventoryItem($itemId, $category, $updatedData) {
    $inventoryData = getAdminInventoryData();

    if (!isset($inventoryData[$category])) {
        return false;
    }

    // Find and update the item
    $found = false;
    foreach ($inventoryData[$category] as &$item) {
        if ($item['id'] == $itemId) {
            // Update only the fields that were provided
            foreach ($updatedData as $key => $value) {
                $item[$key] = $value;
            }
            $found = true;
            break;
        }
    }

    if ($found) {
        // Save the updated data
        return saveAdminInventoryData($inventoryData);
    }

    return false;
}

/**
 * Delete an inventory item
 */
function deleteInventoryItem($itemId, $category) {
    $inventoryData = getAdminInventoryData();

    if (!isset($inventoryData[$category])) {
        return false;
    }

    // Find and remove the item
    $inventoryData[$category] = array_filter($inventoryData[$category], function($item) use ($itemId) {
        return $item['id'] != $itemId;
    });

    // Re-index the array
    $inventoryData[$category] = array_values($inventoryData[$category]);

    // Save the updated data
    return saveAdminInventoryData($inventoryData);
}

/**
 * Add a new inventory item
 */
function addInventoryItem($category, $itemData) {
    $inventoryData = getAdminInventoryData();

    if (!isset($inventoryData[$category])) {
        return false;
    }

    // Generate a new ID (find the max ID and increment)
    $maxId = 0;
    foreach ($inventoryData[$category] as $item) {
        if ($item['id'] > $maxId) {
            $maxId = $item['id'];
        }
    }

    $itemData['id'] = $maxId + 1;
    $inventoryData[$category][] = $itemData;

    // Save the updated data
    return saveAdminInventoryData($inventoryData);
}

function getAvailableEquipment() {
    $conn = getDBConnection();

    // Query equipment from database that are available or active
    $sql = "SELECT e.equipment_id, e.equipment_name, e.asset_tag, e.location,
                   e.status, e.quantity, e.description,
                   c.category_name,
                   CASE
                       WHEN e.location LIKE '%Main%' THEN 'Main Campus'
                       WHEN e.location LIKE '%Annex 1%' THEN 'Annex 1 Campus'
                       WHEN e.location LIKE '%Annex 2%' THEN 'Annex 2 Campus'
                       ELSE 'Main Campus'
                   END as campus,
                   SUBSTRING_INDEX(SUBSTRING_INDEX(e.location, ' - ', 1), ' ', -1) as building,
                   SUBSTRING_INDEX(e.location, ' - ', -1) as room
            FROM equipment e
            LEFT JOIN categories c ON e.category_id = c.category_id
            WHERE e.status IN ('available', 'active')
            AND e.quantity > 0
            ORDER BY c.category_name ASC, e.equipment_name ASC";

    $result = $conn->query($sql);
    $availableEquipment = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $availableEquipment[] = [
                'equipment_id' => $row['equipment_id'],
                'equipment_name' => $row['equipment_name'],
                'asset_tag' => $row['asset_tag'],
                'location' => $row['location'],
                'category_name' => $row['category_name'] ?? 'Uncategorized',
                'campus' => $row['campus'],
                'building' => $row['building'],
                'room' => $row['room'],
                'status' => $row['status'],
                'qty' => $row['quantity'],
                'remarks' => $row['description'] ?? ''
            ];
        }
    }

    return $availableEquipment;
}

function getAvailableEquipmentForReservation() {
    return getAvailableEquipment();
}

function updateEquipment($equipment_id, $data) {
    $conn = getDBConnection();
    $updates = [];
    $types = "";
    $values = [];
    
    foreach ($data as $key => $value) {
        $updates[] = "$key = ?";
        $types .= "s";
        $values[] = $value;
    }
    
    $values[] = $equipment_id;
    $types .= "s";
    
    $sql = "UPDATE equipment SET " . implode(", ", $updates) . " WHERE equipment_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// ============================================
// DEFECT REPORT FUNCTIONS
// ============================================

function addDefectReport($data) {
    $conn = getDBConnection();

    // Handle array fields that need to be stored as JSON
    if (isset($data['defect_photos']) && is_array($data['defect_photos'])) {
        $data['defect_photos'] = json_encode($data['defect_photos']);
    }

    // Build dynamic INSERT query
    $fields = array_keys($data);
    $placeholders = array_fill(0, count($fields), '?');
    $types = str_repeat('s', count($fields));

    $sql = "INSERT INTO defect_reports (" . implode(', ', $fields) . ", report_date)
            VALUES (" . implode(', ', $placeholders) . ", NOW())";

    $stmt = $conn->prepare($sql);
    $values = array_values($data);
    $stmt->bind_param($types, ...$values);

    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function getDefectReportById($report_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag 
                            FROM defect_reports dr 
                            JOIN equipment e ON dr.equipment_id = e.equipment_id 
                            WHERE dr.report_id = ?");
    $stmt->bind_param("s", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();
    $stmt->close();
    return $report;
}

function getReportByIdPublic($report_id) {
    return getDefectReportById($report_id);
}

function getUserDefectReports($user_id) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            WHERE dr.reported_by = ?
                            ORDER BY dr.report_date DESC");
    $stmt->bind_param("s", $user_id);

    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $reports;
}

function updateDefectReport($report_id, $data) {
    $conn = getDBConnection();
    $updates = [];
    $types = "";
    $values = [];
    
    foreach ($data as $key => $value) {
        $updates[] = "$key = ?";
        $types .= "s";
        $values[] = $value;
    }
    
    $values[] = $report_id;
    $types .= "s";
    
    $sql = "UPDATE defect_reports SET " . implode(", ", $updates) . " WHERE report_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function getDefectReportsWithFilters($status = 'all', $priority = 'all', $search = '') {
    $conn = getDBConnection();

    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag, c.category_name,
            mt.fullname as technician_name
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            LEFT JOIN categories c ON e.category_id = c.category_id
            LEFT JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            WHERE 1=1";

    $params = [];
    $types = "";

    if ($status !== 'all') {
        $sql .= " AND dr.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($priority !== 'all') {
        $sql .= " AND dr.priority = ?";
        $params[] = $priority;
        $types .= "s";
    }

    if (!empty($search)) {
        $sql .= " AND (dr.report_id LIKE ? OR e.equipment_name LIKE ? OR c.category_name LIKE ? OR dr.issue_description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ssss";
    }

    $sql .= " ORDER BY dr.report_date DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $reports;
}

// ============================================
// DEFECT MONITORING FUNCTIONS
// ============================================

/**
 * Get defect reports grouped by category
 */
function getDefectReportsByCategory($category_id = null, $status_filter = 'all') {
    $conn = getDBConnection();

    $sql = "SELECT c.category_name,
            COUNT(dr.report_id) as total_defects,
            COUNT(CASE WHEN dr.status IN ('reported', 'assigned', 'in_progress') THEN 1 END) as pending_defects,
            COUNT(CASE WHEN dr.status = 'completed' THEN 1 END) as resolved_defects,
            COUNT(CASE WHEN dr.priority = 'critical' THEN 1 END) as critical_defects,
            COUNT(CASE WHEN dr.priority = 'high' THEN 1 END) as high_defects,
            MAX(dr.report_date) as last_defect_date
            FROM categories c
            LEFT JOIN equipment e ON c.category_id = e.category_id
            LEFT JOIN defect_reports dr ON e.equipment_id = dr.equipment_id";

    $params = [];
    $types = "";

    if ($category_id) {
        $sql .= " WHERE c.category_id = ?";
        $params[] = $category_id;
        $types .= "i";
    }

    if ($status_filter !== 'all') {
        $where_clause = $category_id ? " AND" : " WHERE";
        if ($status_filter === 'pending') {
            $sql .= "$where_clause dr.status IN ('reported', 'assigned', 'in_progress')";
        } elseif ($status_filter === 'resolved') {
            $sql .= "$where_clause dr.status = 'completed'";
        } else {
            $sql .= "$where_clause dr.status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }
    }

    $sql .= " GROUP BY c.category_id, c.category_name ORDER BY c.category_name ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $reports;
}

/**
 * Get equipment defect statistics by category
 */
function getEquipmentDefectStats() {
    $conn = getDBConnection();

    $sql = "SELECT c.category_name,
            COUNT(DISTINCT e.equipment_id) as total_equipment,
            COUNT(DISTINCT CASE WHEN e.status = 'defective' THEN e.equipment_id END) as defective_equipment,
            COUNT(dr.report_id) as total_defects,
            ROUND(AVG(CASE WHEN dr.status = 'completed'
                          THEN DATEDIFF(dr.completion_date, dr.report_date) END), 1) as avg_resolution_days
            FROM categories c
            LEFT JOIN equipment e ON c.category_id = e.category_id
            LEFT JOIN defect_reports dr ON e.equipment_id = dr.equipment_id
            GROUP BY c.category_id, c.category_name
            ORDER BY total_defects DESC, category_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get defect trends by category over time
 */
function getDefectTrendsByCategory($days = 30) {
    $conn = getDBConnection();

    $sql = "SELECT c.category_name,
            DATE(dr.report_date) as report_date,
            COUNT(dr.report_id) as defect_count
            FROM categories c
            LEFT JOIN equipment e ON c.category_id = e.category_id
            LEFT JOIN defect_reports dr ON e.equipment_id = dr.equipment_id
            WHERE dr.report_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY c.category_id, c.category_name, DATE(dr.report_date)
            ORDER BY c.category_name ASC, report_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $result = $stmt->get_result();
    $trends = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $trends;
}

// ============================================
// RESERVATION FUNCTIONS
// ============================================

function addReservation($data) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("INSERT INTO reservations 
        (reservation_id, equipment_id, user_id, start_date, end_date, purpose, status, request_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stmt->bind_param("sssssss",
        $data['reservation_id'],
        $data['equipment_id'],
        $data['user_id'],
        $data['start_date'],
        $data['end_date'],
        $data['purpose'],
        $data['status']
    );
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function checkReservationConflict($equipment_id, $start_date, $end_date) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                            FROM reservations 
                            WHERE equipment_id = ? 
                            AND status IN ('pending', 'approved', 'active') 
                            AND NOT (end_date < ? OR start_date > ?)");
    
    $stmt->bind_param("sss", $equipment_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    return $count > 0;
}

function getUserReservations($user_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT r.*, e.equipment_name, e.asset_tag 
                            FROM reservations r 
                            JOIN equipment e ON r.equipment_id = e.equipment_id 
                            WHERE r.user_id = ? 
                            ORDER BY r.request_date DESC");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $reservations;
}

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

if (!function_exists('addNotification')) {
    function addNotification($user_id, $message, $type, $related_id = null) {
        $conn = getDBConnection();
        $notification_id = 'NOT-' . uniqid();

        $stmt = $conn->prepare("INSERT INTO notifications
            (notification_id, user_id, message, type, related_id, created_date, is_read)
            VALUES (?, ?, ?, ?, ?, NOW(), 0)");

        $stmt->bind_param("sssss", $notification_id, $user_id, $message, $type, $related_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result ? $notification_id : false;
    }
}

// ============================================
// TECHNICIAN FUNCTIONS
// ============================================

function getAvailableTechnicians() {
    $conn = getDBConnection();
    $sql = "SELECT technician_id, fullname, specialization, status 
            FROM maintenance_technicians 
            WHERE status = 'active' 
            ORDER BY fullname ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getTechnicianStatistics($technician_id) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT
        COUNT(CASE WHEN status IN ('assigned', 'in_progress') AND assigned_to = ? THEN 1 END) as assigned_tasks,
        COUNT(CASE WHEN status = 'reported' AND assigned_to IS NULL THEN 1 END) as unassigned_tasks,
        COUNT(CASE WHEN status = 'in_progress' AND assigned_to = ? THEN 1 END) as in_progress,
        COUNT(CASE WHEN status = 'completed' AND DATE(completion_date) = CURDATE() AND assigned_to = ? THEN 1 END) as completed_today,
        COUNT(CASE WHEN status = 'completed' AND assigned_to = ? THEN 1 END) as total_completed
        FROM defect_reports");

    $stmt->bind_param("ssss", $technician_id, $technician_id, $technician_id, $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();

    // Calculate pending tasks as assigned + unassigned
    $stats['pending_tasks'] = $stats['assigned_tasks'] + $stats['unassigned_tasks'];

    return $stats;
}

function getAssignedTasks($technician_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag, e.location,
                            CASE WHEN dr.assigned_to IS NULL THEN 'unassigned' ELSE 'assigned' END as task_type
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            WHERE (dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress'))
                            OR (dr.assigned_to IS NULL AND dr.status = 'reported')
                            ORDER BY dr.priority DESC, dr.assigned_date ASC, dr.report_date ASC");
    $stmt->bind_param("s", $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}

function getTechnicianWorkHistory($technician_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            WHERE dr.assigned_to = ?
                            AND dr.status = 'completed'
                            ORDER BY dr.completion_date DESC");
    $stmt->bind_param("s", $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

function getCompletedWorkForVerification() {
    $conn = getDBConnection();
    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag,
            mt.fullname as technician_name
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            WHERE dr.status = 'completed'
            ORDER BY dr.completion_date DESC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getUnassignedReports() {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            WHERE dr.status = 'reported' AND dr.assigned_to IS NULL
                            ORDER BY dr.priority DESC, dr.report_date ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $reports;
}

function getAvailableTasks() {
    // Wrapper to return unassigned defect reports, with normalized photos field
    $reports = getUnassignedReports();
    foreach ($reports as &$r) {
        if (isset($r['defect_photos']) && !empty($r['defect_photos'])) {
            $decoded = json_decode($r['defect_photos'], true);
            $r['photos'] = is_array($decoded) ? $decoded : [];
        } else {
            $r['photos'] = [];
        }
    }
    return $reports;
}

function getRecentAssignedTasks($technician_id, $limit = 5) {
    $conn = getDBConnection();
    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag, e.location
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            WHERE dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress', 'completed')
            ORDER BY dr.assigned_date DESC, dr.report_date DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $limit = (int)$limit;
    $stmt->bind_param("si", $technician_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $tasks;
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function getPriorityClass($priority) {
    $classes = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'secondary'
    ];
    return $classes[$priority] ?? 'secondary';
}

function getStatusClass($status) {
    $classes = [
        'reported' => 'warning',
        'assigned' => 'info',
        'in_progress' => 'primary',
        'completed' => 'success',
        'verified' => 'success',
        'closed' => 'secondary',
        'available' => 'success',
        'in-use' => 'primary',
        'maintenance' => 'warning',
        'defective' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}

function getReservationStatusClass($status) {
    $classes = [
        'pending' => 'warning',
        'approved' => 'success',
        'active' => 'primary',
        'completed' => 'secondary',
        'rejected' => 'danger',
        'cancelled' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}



// ============================================
// USER AUTHENTICATION FUNCTIONS
// ============================================

/**
 * Verify user login credentials
 * @param string $email
 * @param string $password
 * @param string $role (admin, handler, technician, faculty, student)
 * @return array|false User data or false
 */
function authenticateUser($email, $password, $role) {
    $conn = getDBConnection();

    // Query the unified users table
    $sql = "SELECT * FROM `users`
            WHERE email = ? AND role = ? AND status = 'active'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false;
    }

    $user = $result->fetch_assoc();

    // Verify password
    if (password_verify($password, $user['password'])) {
        // Update last login - use user_id since that's the primary key in users table
        $updateSql = "UPDATE `users` SET last_login = NOW() WHERE user_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("s", $user['user_id']);
        $updateStmt->execute();

        return $user;
    }

    return false;
}

/**
 * Get user by ID and role
 */
function getUserById($user_id, $role) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_field' => 'admin_id'],
        'technician' => ['table' => 'maintenance_technicians', 'id_field' => 'technician_id'],
        'faculty' => ['table' => 'faculty_members', 'id_field' => 'faculty_id'],
        'student' => ['table' => 'students', 'id_field' => 'student_id']
    ];

    if (!isset($roleTableMap[$role])) {
        return null;
    }

    $config = $roleTableMap[$role];
    $sql = "SELECT * FROM `{$config['table']}` WHERE {$config['id_field']} = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

/**
 * Create new user account
 */
function createUser($role, $userData) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_prefix' => 'ADM'],
        'technician' => ['table' => 'maintenance_technicians', 'id_prefix' => 'TEC'],
        'faculty' => ['table' => 'faculty_members', 'id_prefix' => 'FAC'],
        'student' => ['table' => 'students', 'id_prefix' => 'STU']
    ];

    if (!isset($roleTableMap[$role])) {
        return false;
    }

    $config = $roleTableMap[$role];
    $table = $config['table'];
    $idField = $role . '_id';

    // Generate ID
    if (!isset($userData[$idField])) {
        $userData[$idField] = $config['id_prefix'] . '-' . strtoupper(substr(uniqid(), -6));
    }

    // Hash password
    if (isset($userData['password'])) {
        $userData['password'] = password_hash($userData['password'], PASSWORD_BCRYPT);
    }

    // Build INSERT query
    $fields = array_keys($userData);
    $placeholders = array_fill(0, count($fields), '?');

    $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

    $stmt = $conn->prepare($sql);

    // Bind parameters dynamically
    $types = str_repeat('s', count($fields));
    $values = array_values($userData);
    $stmt->bind_param($types, ...$values);

    return $stmt->execute();
}

/**
 * Update user information
 */
function updateUser($user_id, $role, $updateData) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_field' => 'admin_id'],
        'technician' => ['table' => 'maintenance_technicians', 'id_field' => 'technician_id'],
        'faculty' => ['table' => 'faculty_members', 'id_field' => 'faculty_id'],
        'student' => ['table' => 'students', 'id_field' => 'student_id']
    ];

    if (!isset($roleTableMap[$role])) {
        return false;
    }

    $config = $roleTableMap[$role];

    // Hash password if being updated
    if (isset($updateData['password'])) {
        $updateData['password'] = password_hash($updateData['password'], PASSWORD_BCRYPT);
    }

    // Build UPDATE query
    $sets = [];
    $values = [];
    foreach ($updateData as $field => $value) {
        $sets[] = "$field = ?";
        $values[] = $value;
    }
    $values[] = $user_id;

    $sql = "UPDATE `{$config['table']}` SET " . implode(', ', $sets) .
           " WHERE {$config['id_field']} = ?";

    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($values));
    $stmt->bind_param($types, ...$values);

    return $stmt->execute();
}

/**
 * Get all users by role
 */
function getAllUsersByRole($role) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => 'admins',
        'technician' => 'maintenance_technicians',
        'faculty' => 'faculty_members',
        'student' => 'students'
    ];

    if (!isset($roleTableMap[$role])) {
        return [];
    }

    $table = $roleTableMap[$role];
    $sql = "SELECT * FROM `$table` ORDER BY fullname";

    $result = $conn->query($sql);

    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}

/**
 * Check if username exists
 */
function usernameExists($username, $excludeUserId = null) {
    $conn = getDBConnection();

    $tables = ['admins', 'maintenance_technicians',
               'faculty_members', 'students'];

    foreach ($tables as $table) {
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE username = ?";

        if ($excludeUserId) {
            $sql .= " AND (admin_id != ? OR technician_id != ? OR faculty_id != ? OR student_id != ?)";
        }

        $stmt = $conn->prepare($sql);

        if ($excludeUserId) {
            $stmt->bind_param("ssssss", $username, $excludeUserId, $excludeUserId,
                            $excludeUserId, $excludeUserId, $excludeUserId);
        } else {
            $stmt->bind_param("s", $username);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Check if email exists
 */
function emailExists($email, $excludeUserId = null) {
    $conn = getDBConnection();

    $tables = ['admins', 'maintenance_technicians',
               'faculty_members', 'students'];

    foreach ($tables as $table) {
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE email = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Log user activity (optional security feature)
 */
function logActivity($user_id, $user_role, $action_type, $description = '') {
    $conn = getDBConnection();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $sql = "INSERT INTO activity_log (user_id, user_role, action_type, action_description, ip_address)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $user_id, $user_role, $action_type, $description, $ip_address);

    return $stmt->execute();
}

/**
 * Get employees on vacation from dbrrhh database
 */
function getEmployeesOnVacation() {
    try {
        // Connect to dbrrhh database
        $dbrrhh_conn = new mysqli("localhost", "root", "", "dbrrhh");

        if ($dbrrhh_conn->connect_error) {
            error_log("dbrrhh database connection error: " . $dbrrhh_conn->connect_error);
            return [];
        }

        $dbrrhh_conn->set_charset("utf8mb4");

        // Query for current month approved leave requests
        $sql = "SELECT
            e.first_name,
            e.last_name,
            p.start_time,
            p.end_time,
            p.employee_id,
            p.start_date,
            p.end_date,
            p.total_days,
            p.total_hours
        FROM
            employees AS e,
            leave_approvals AS a,
            leave_requests AS p
        WHERE
            e.id = p.employee_id
            AND a.leave_request_id = p.id
            AND a.`status` = 'approved'
            AND year(p.start_date)=year(now()) and month(p.start_date)=month(now())
        ORDER BY p.start_date ASC";

        $result = $dbrrhh_conn->query($sql);
        $vacations = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $vacations[] = [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'total_days' => $row['total_days'],
                    'total_hours' => $row['total_hours']
                ];
            }
        }

        $dbrrhh_conn->close();
        return $vacations;

    } catch (Exception $e) {
        error_log("Error fetching vacation data: " . $e->getMessage());
        return [];
    }
}

/**
 * Create user session record
 */
function createSession($user_id, $user_role) {
    $conn = getDBConnection();

    $session_id = session_id();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $sql = "INSERT INTO user_sessions (session_id, user_id, user_role, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $session_id, $user_id, $user_role, $ip_address, $user_agent);

    return $stmt->execute();
}

/**
 * Close user session
 */
function closeSession($session_id) {
    $conn = getDBConnection();

    $sql = "UPDATE user_sessions SET logout_time = NOW() WHERE session_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $session_id);

    return $stmt->execute();
}

// ============================================
// SYSTEM STATISTICS FUNCTIONS
// ============================================







/**
 * Get total user count across all roles
 */
function getTotalUserCount() {
    $conn = getDBConnection();
    $tables = ['admins', 'maintenance_technicians', 'faculty_members', 'students'];
    $total = 0;

    foreach ($tables as $table) {
        $result = $conn->query("SELECT COUNT(*) as count FROM `$table` WHERE status = 'active'");
        if ($result) {
            $total += $result->fetch_assoc()['count'];
        }
    }

    return $total;
}





// ============================================
// INITIALIZATION
// ============================================

/**
 * Initialize system with sample data if needed
 */
function initializeSystem() {
    global $fileStorage;

    // Check if equipment data exists
    $equipment = getAllEquipment();

    if (empty($equipment)) {
        // Generate sample data
        generateSampleData();
        return true;
    }

    return false;
}

// Auto-initialize on first run
// initializeSystem();
