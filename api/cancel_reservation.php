<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$reservation_id = $_POST['reservation_id'] ?? null;
$user_id = getUserId();

if (!$reservation_id) {
    echo json_encode(['success' => false, 'message' => 'Reservation ID required']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Verify ownership
    $stmt = $conn->prepare("SELECT user_id FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservation = $result->fetch_assoc();
    
    if (!$reservation || $reservation['user_id'] !== $user_id) {
        throw new Exception('Unauthorized');
    }
    
    // Cancel reservation
    $stmt = $conn->prepare("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Reservation cancelled successfully'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
```

### **Fix 2: Reorganize Directory Structure**
```
bec_equipment/
├── config/
│   ├── database.php (move from root)
│   └── constants.php (new)
├── includes/
│   ├── auth.php (already exists)
│   ├── functions.php (new - move helper functions)
│   ├── user_navbar.php (already exists)
│   └── handler_sidebar.php (already exists)
├── api/
│   ├── get_technicians.php (new)
│   ├── get_report_details.php (new)
│   ├── get_work_details.php (new)
│   └── cancel_reservation.php (new)
├── css/
├── js/
├── uploads/
├── data/
└── (all your PHP pages)