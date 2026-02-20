<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

// Get the JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit();
}

$action = $input['action'] ?? '';

switch ($action) {
    case 'add':
        // Add new equipment
        $required_fields = ['category', 'propertyNo', 'campus', 'buildingName', 'room', 'article', 'qty', 'status'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                exit();
            }
        }

        $itemData = [
            'propertyNo' => $input['propertyNo'],
            'campus' => $input['campus'],
            'buildingName' => $input['buildingName'],
            'room' => $input['room'],
            'article' => $input['article'],
            'qty' => (int)$input['qty'],
            'status' => $input['status'],
            'remarks' => $input['remarks'] ?? ''
        ];

        $result = addInventoryItem($input['category'], $itemData);
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Equipment added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add equipment']);
        }
        break;

    case 'update':
        // Update existing equipment
        if (!isset($input['itemId'])) {
            echo json_encode(['success' => false, 'message' => 'Missing item ID']);
            exit();
        }

        if (!isset($input['category'])) {
            echo json_encode(['success' => false, 'message' => 'Missing category']);
            exit();
        }

        $updateData = [];
        $allowed_fields = ['propertyNo', 'campus', 'buildingName', 'room', 'article', 'qty', 'status', 'remarks'];

        foreach ($allowed_fields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (isset($input['qty'])) {
            $updateData['qty'] = (int)$input['qty'];
        }

        $category = $input['category'];
        $itemId = (int)$input['itemId'];

        $result = updateInventoryItem($itemId, $category, $updateData);
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Equipment updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update equipment']);
        }
        break;

    case 'delete':
        // Delete existing equipment
        if (!isset($input['itemId'])) {
            echo json_encode(['success' => false, 'message' => 'Missing item ID']);
            exit();
        }

        if (!isset($input['category'])) {
            echo json_encode(['success' => false, 'message' => 'Missing category']);
            exit();
        }

        $category = $input['category'];
        $itemId = (int)$input['itemId'];

        $result = deleteInventoryItem($itemId, $category);
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Equipment deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete equipment']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>
