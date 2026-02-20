<?php
// api/equipment_manager.php - Fast JSON-based equipment storage
session_start();
require_once '../includes/auth.php';

requireRole('admin');
header('Content-Type: application/json');

class FastEquipmentManager {
    private $dataFile = '../data/equipment_cache.json';
    private $uploadsDir = '../uploads/equipment/';
    
    public function __construct() {
        if (!file_exists(dirname($this->dataFile))) {
            mkdir(dirname($this->dataFile), 0755, true);
        }
        if (!file_exists($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }
    
    private function loadData() {
        if (!file_exists($this->dataFile)) {
            return ['equipment' => [], 'last_updated' => time()];
        }
        return json_decode(file_get_contents($this->dataFile), true);
    }
    
    private function saveData($data) {
        $data['last_updated'] = time();
        file_put_contents($this->dataFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    public function addEquipment($equipmentData, $photoFile = null) {
        $data = $this->loadData();
        
        // Generate unique ID
        $equipmentData['equipment_id'] = 'EQ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $equipmentData['created_at'] = date('Y-m-d H:i:s');
        $equipmentData['updated_at'] = date('Y-m-d H:i:s');
        $equipmentData['status'] = $equipmentData['status'] ?? 'available';
        
        // Handle photo upload
        if ($photoFile && $photoFile['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($photoFile['name'], PATHINFO_EXTENSION);
            $filename = $equipmentData['equipment_id'] . '.' . $ext;
            $filepath = $this->uploadsDir . $filename;
            
            if (move_uploaded_file($photoFile['tmp_name'], $filepath)) {
                $equipmentData['photo'] = 'uploads/equipment/' . $filename;
            }
        }
        
        // Add to data
        $data['equipment'][] = $equipmentData;
        
        // Save instantly
        $this->saveData($data);
        
        // Also update MySQL for relational queries (async)
        $this->syncToDatabase($equipmentData);
        
        return [
            'success' => true,
            'message' => 'Equipment added successfully',
            'equipment_id' => $equipmentData['equipment_id']
        ];
    }
    
    public function updateEquipment($equipment_id, $updateData, $photoFile = null) {
        $data = $this->loadData();
        $found = false;
        
        foreach ($data['equipment'] as $key => $equipment) {
            if ($equipment['equipment_id'] === $equipment_id) {
                // Merge update data
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                
                // Handle photo upload
                if ($photoFile && $photoFile['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($photoFile['name'], PATHINFO_EXTENSION);
                    $filename = $equipment_id . '.' . $ext;
                    $filepath = $this->uploadsDir . $filename;
                    
                    if (move_uploaded_file($photoFile['tmp_name'], $filepath)) {
                        $updateData['photo'] = 'uploads/equipment/' . $filename;
                    }
                }
                
                $data['equipment'][$key] = array_merge($equipment, $updateData);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['success' => false, 'message' => 'Equipment not found'];
        }
        
        $this->saveData($data);
        $this->syncToDatabase($data['equipment'][$key]);
        
        return ['success' => true, 'message' => 'Equipment updated successfully'];
    }
    
    public function deleteEquipment($equipment_id) {
        $data = $this->loadData();
        $found = false;
        
        foreach ($data['equipment'] as $key => $equipment) {
            if ($equipment['equipment_id'] === $equipment_id) {
                // Soft delete
                $data['equipment'][$key]['status'] = 'deleted';
                $data['equipment'][$key]['deleted_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['success' => false, 'message' => 'Equipment not found'];
        }
        
        $this->saveData($data);
        $this->syncToDatabase($data['equipment'][$key]);
        
        return ['success' => true, 'message' => 'Equipment deleted successfully'];
    }
    
    public function getAllEquipment() {
        $data = $this->loadData();
        return array_filter($data['equipment'], function($eq) {
            return $eq['status'] !== 'deleted';
        });
    }

    public function getEquipment($equipment_id) {
        require_once '../config/database.php';
        $conn = getDBConnection();

        $stmt = $conn->prepare("SELECT * FROM equipment WHERE equipment_id = ? AND status != 'deleted'");
        $stmt->bind_param("s", $equipment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $equipment = $result->fetch_assoc();
        $stmt->close();
        $conn->close();

        return $equipment;
    }

    public function updateInventory($equipment_id, $quantity_change, $reason = '') {
        require_once '../config/database.php';
        require_once '../inventory_functions.php';

        $updated_by = $_SESSION['user_id'] ?? null;
        $result = updateInventory($equipment_id, $quantity_change, $reason, $updated_by);

        return [
            'success' => $result,
            'message' => $result ? 'Inventory updated successfully' : 'Failed to update inventory'
        ];
    }

    public function updateInventorySettings($equipment_id, $settings) {
        require_once '../config/database.php';
        require_once '../inventory_functions.php';

        $result = updateInventorySettings($equipment_id, $settings);

        return [
            'success' => $result,
            'message' => $result ? 'Inventory settings updated successfully' : 'Failed to update settings'
        ];
    }
    
    private function syncToDatabase($equipmentData) {
        // Background sync to MySQL for relational integrity
        require_once '../config/database.php';
        $conn = getDBConnection();
        
        $check = $conn->prepare("SELECT equipment_id FROM equipment WHERE equipment_id = ?");
        $check->bind_param("s", $equipmentData['equipment_id']);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        
        if ($exists) {
            // Update
            $stmt = $conn->prepare("UPDATE equipment SET equipment_name = ?, category_id = ?, description = ?, location = ?, status = ?, photo = ?, updated_at = NOW() WHERE equipment_id = ?");
            $stmt->bind_param("sssssss", 
                $equipmentData['equipment_name'],
                $equipmentData['category_id'],
                $equipmentData['description'],
                $equipmentData['location'],
                $equipmentData['status'],
                $equipmentData['photo'],
                $equipmentData['equipment_id']
            );
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO equipment (equipment_id, asset_tag, equipment_name, category_id, description, location, status, photo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssss",
                $equipmentData['equipment_id'],
                $equipmentData['asset_tag'],
                $equipmentData['equipment_name'],
                $equipmentData['category_id'],
                $equipmentData['description'],
                $equipmentData['location'],
                $equipmentData['status'],
                $equipmentData['photo']
            );
        }
        
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

// Handle API requests
$manager = new FastEquipmentManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add':
            $equipmentData = [
                'asset_tag' => $_POST['asset_tag'],
                'equipment_name' => $_POST['equipment_name'],
                'category_id' => $_POST['category_id'],
                'description' => $_POST['description'] ?? '',
                'location' => $_POST['location'],
                'status' => $_POST['status'] ?? 'available'
            ];

            $photo = $_FILES['photo'] ?? null;
            $result = $manager->addEquipment($equipmentData, $photo);
            echo json_encode($result);
            break;

        case 'update':
            $equipment_id = $_POST['equipment_id'];
            $updateData = [
                'equipment_name' => $_POST['equipment_name'],
                'category_id' => $_POST['category_id'],
                'description' => $_POST['description'] ?? '',
                'location' => $_POST['location'],
                'status' => $_POST['status']
            ];

            $photo = $_FILES['photo'] ?? null;
            $result = $manager->updateEquipment($equipment_id, $updateData, $photo);
            echo json_encode($result);
            break;

        case 'delete':
            $equipment_id = $_POST['equipment_id'];
            $result = $manager->deleteEquipment($equipment_id);
            echo json_encode($result);
            break;

        case 'update_inventory':
            $equipment_id = $_POST['equipment_id'];
            $quantity_change = (int)$_POST['quantity_change'];
            $reason = $_POST['reason'] ?? '';
            $result = $manager->updateInventory($equipment_id, $quantity_change, $reason);
            echo json_encode($result);
            break;

        case 'update_inventory_settings':
            $equipment_id = $_POST['equipment_id'];
            $settings = [
                'min_stock_level' => (int)$_POST['min_stock_level'],
                'reorder_point' => (int)$_POST['reorder_point'],
                'supplier_info' => $_POST['supplier_info'] ?? ''
            ];
            $result = $manager->updateInventorySettings($equipment_id, $settings);
            echo json_encode($result);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            $equipment = $manager->getAllEquipment();
            echo json_encode(['success' => true, 'equipment' => array_values($equipment)]);
            break;

        case 'get_equipment':
            $equipment_id = $_GET['id'] ?? '';
            if (empty($equipment_id)) {
                echo json_encode(['success' => false, 'message' => 'Equipment ID required']);
                break;
            }
            $equipment = $manager->getEquipment($equipment_id);
            if ($equipment) {
                echo json_encode(['success' => true, 'equipment' => $equipment]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Equipment not found']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>