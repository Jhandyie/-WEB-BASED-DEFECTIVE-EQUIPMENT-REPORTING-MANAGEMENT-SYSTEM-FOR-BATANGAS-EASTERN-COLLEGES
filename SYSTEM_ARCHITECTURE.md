# BEC Equipment Management System - System Architecture

## 1. SYSTEM OVERVIEW

The **BEC Equipment Management System** is a comprehensive web-based application designed to manage equipment inventory, defect reporting, reservations, and maintenance operations for a multi-campus educational institution (Main Campus, Annex 1, Annex 2).

### Key Features:
- Equipment Inventory Management (Air Conditioners, TVs, Fans, Whiteboards, Lockers, Office Chairs, Computers)
- Defect Reporting & Tracking with priority-based workflow
- Equipment Reservations System
- Multi-role User Management (Admin, Technician, Student, Faculty)
- OTP-based Authentication
- Real-time Notifications
- Analytics & Reporting

---

## 2. TECHNOLOGY STACK

### Frontend
| Technology | Purpose |
|------------|---------|
| HTML5 | Markup Structure |
| CSS3 (Custom + Bootstrap) | Styling & Responsive Design |
| JavaScript (Vanilla + jQuery) | Client-side Logic |
| Font Awesome 6.5.2 | Icons |
| Google Fonts | Typography (Playfair Display, DM Sans) |

### Backend
| Technology | Purpose |
|------------|---------|
| PHP 8.x | Server-side Processing |
| MySQL | Relational Database |
| Session Management | User Authentication |

### Development Tools
| Tool | Purpose |
|------|---------|
| XAMPP | Local Development Server |
| phpMyAdmin | Database Management |
| VSCode | Code Editor |

---

## 3. SYSTEM ARCHITECTURE LAYERS

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                       │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐           │
│  │  Login HTML │ │ Dashboards  │ │  Modals     │           │
│  │  (Student)  │ │  (Admin)    │ │  (Reports)  │           │
│  └─────────────┘ └─────────────┘ └─────────────┘           │
│        │               │                │                   │
│        └───────────────┴────────────────┘                   │
│                        │                                     │
│                        ▼                                     │
│  ┌─────────────────────────────────────────────────────────┐│
│  │              JavaScript / AJAX Layer                    ││
│  │  - Form Validation    - Dynamic UI Updates             ││
│  │  - API Calls          - Event Handling                 ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                    BUSINESS LOGIC LAYER                      │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │ Controllers     │  │   Models        │  │   Helpers    │ │
│  │                 │  │                 │  │              │ │
│  │ - StudentDash  │  │ - DefectReport │  │ - otp_helper │ │
│  │   boardController  │                 │  │ - mail_helper│ │
│  │                 │  │ - User Model   │  │ - validation  │ │
│  │                 │  │ - Equipment    │  │ - auth        │ │
│  └─────────────────┘  └─────────────────┘  └──────────────┘ │
└─────────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                      DATA ACCESS LAYER                      │
│  ┌─────────────────────────────────────────────────────────┐│
│  │              config/database.php                         ││
│  │  - Singleton Database Connection                        ││
│  │  - CRUD Operations                                      ││
│  │  - Query Builders                                       ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                      DATABASE LAYER                          │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │  users   │ │equipment │ │reserva-  │ │defect_   │       │
│  │          │ │          │ │tions     │ │reports   │       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘       │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │notifica- │ │  otp_    │ │password_ │ │activity_ │       │
│  │tions     │ │codes     │ │resets    │ │log       │       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘       │
└─────────────────────────────────────────────────────────────┘
```

---

## 4. USER ROLES & PERMISSIONS

| Role | Description | Key Permissions |
|------|-------------|-----------------|
| **Admin** | System Administrator | Manage users, equipment, view all reports, verify completed work, system settings |
| **Technician** | Maintenance Personnel | View assigned tasks, update defect status, complete repairs |
| **Student** | Equipment User | Submit defect reports, make reservations, view own reports |
| **Faculty** | Teaching Staff | Same as student + priority booking |

### Role Hierarchy
```
Admin
   │
   ├──► Technician
   │         │
   │         └──► Can view/manage assigned tasks
   │
   └──► Faculty
             │
             └──► Student (inherits all student permissions)
```

---

## 5. DATABASE SCHEMA

### Core Tables

#### 5.1 Users Table
```
sql
CREATE TABLE users (
    user_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    fullname VARCHAR(100),
    role ENUM('admin', 'technician', 'student', 'faculty'),
    status ENUM('active', 'inactive', 'suspended'),
    created_at DATETIME,
    last_login DATETIME
);
```

#### 5.2 Equipment Table
```
sql
CREATE TABLE equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id VARCHAR(50) UNIQUE,
    equipment_name VARCHAR(100),
    equipment_category VARCHAR(50),
    location VARCHAR(200),
    status ENUM('available', 'in-use', 'maintenance', 'defective', 'deleted'),
    quantity INT DEFAULT 1,
    description TEXT,
    serial_number VARCHAR(100),
    brand VARCHAR(50),
    model VARCHAR(50),
    created_at DATETIME,
    updated_at DATETIME
);
```

#### 5.3 Defect Reports Table
```
sql
CREATE TABLE defect_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(20) UNIQUE,
    user_id VARCHAR(20),
    equipment_id INT,
    issue_description TEXT,
    location VARCHAR(200),
    photo_paths JSON,
    status ENUM('pending', 'in_progress', 'completed', 'rejected', 'cancelled'),
    priority ENUM('low', 'medium', 'high', 'critical'),
    category ENUM('hardware', 'software', 'physical_damage', 'performance', 'other'),
    report_date DATETIME,
    assigned_to VARCHAR(20),
    completed_date DATETIME,
    admin_notes TEXT,
    estimated_repair_time DECIMAL(5,1),
    actual_repair_time DECIMAL(5,1)
);
```

#### 5.4 Reservations Table
```
sql
CREATE TABLE reservations (
    reservation_id VARCHAR(20) PRIMARY KEY,
    equipment_id INT,
    user_id VARCHAR(20),
    start_date DATETIME,
    end_date DATETIME,
    purpose TEXT,
    status ENUM('pending', 'approved', 'active', 'completed', 'rejected', 'cancelled'),
    request_date DATETIME
);
```

#### 5.5 OTP Codes Table
```
sql
CREATE TABLE otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100),
    otp_code VARCHAR(6),
    role VARCHAR(20),
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

#### 5.6 Notifications Table
```
sql
CREATE TABLE notifications (
    notification_id VARCHAR(20) PRIMARY KEY,
    user_id VARCHAR(20),
    type VARCHAR(50),
    title VARCHAR(100),
    message TEXT,
    related_id VARCHAR(20),
    is_read TINYINT DEFAULT 0,
    created_date DATETIME
);
```

---

## 6. AUTHENTICATION FLOW

### Login with OTP
```
┌─────────────────────────────────────────────────────────────┐
│                    LOGIN PROCESS                             │
└─────────────────────────────────────────────────────────────┘

1. User enters email & password
        │
        ▼
2. Verify credentials against users table
        │
        ├── Invalid ──► Return error message
        │
        └── Valid ──► Check account status (must be 'active')
                          │
                          ├── Inactive ──► Return error
                          │
                          └── Active ──► Generate OTP
                                          │
                                          ▼
                                  Send OTP via email
                                          │
                                          ▼
                                  Return: {require_otp: true}
                                          │
                                          ▼
3. User enters 6-digit OTP
        │
        ▼
4. Verify OTP (check otp_codes table, expiry time)
        │
        ├── Invalid/Expired ──► Return error
        │
        └── Valid ──► Create Session
                        │
                        ▼
                Set Session Variables:
                - user_id
                - user_email
                - fullname
                - role
                - logged_in = true
                - login_time
                        │
                        ▼
                Update last_login in users table
                        │
                        ▼
                Redirect to role-based dashboard
```

### Password Reset Flow
```
1. Forgot Password Request
        │
        ▼
2. Enter email address
        │
        ▼
3. Check if email exists in users table
        │
        ▼
4. Generate reset token (32 hex chars)
        │
        ▼
5. Store token in password_resets table (expires in 1 hour)
        │
        ▼
6. Send reset link via email
        │
        ▼
7. User clicks link → admin/reset_password.php?token=xxx
        │
        ▼
8. Enter new password (min 6 chars)
        │
        ▼
9. Verify token validity
        │
        ├── Invalid/Expired ──► Show error
        │
        └── Valid ──► Update password (hash with password_hash)
                          │
                          ▼
                     Delete used token
                          │
                          ▼
                     Show success message
```

---

## 7. DEFECT REPORTING WORKFLOW

### Student Submits Report
```
1. Student logs in → Dashboard
        │
        ▼
2. Click "Report Defect" button
        │
        ▼
3. Fill form:
   - Select Equipment (dropdown)
   - Issue Description (min 10 chars)
   - Location
   - Upload Photos (optional, max 10MB each)
        │
        ▼
4. Submit via AJAX → API endpoint
        │
        ▼
5. Server-side validation
        │
        ▼
6. Auto-detect priority (keyword-based):
   - CRITICAL: fire, smoke, broken, not working, urgent
   - HIGH: cracked, damaged, leaking, overheating
   - MEDIUM: slow, issue, problem
   - LOW: default
        │
        ▼
7. Auto-detect category:
   - HARDWARE: keyboard, mouse, monitor, screen
   - SOFTWARE: software, program, app, system
   - PHYSICAL_DAMAGE: crack, broken, scratch
   - PERFORMANCE: slow, lag, freeze, crash
   - OTHER: default
        │
        ▼
8. Check duplicate (same equipment, within 24 hours)
        │
        ├── Duplicate ──► Return error
        │
        └── Unique ──► Insert into defect_reports
                          │
                          ▼
                  Set status = 'pending'
                          │
                          ▼
                  Update equipment status = 'defective'
                          │
                          ▼
                  Create notification for student
                          │
                          ▼
                  If HIGH/CRITICAL priority:
                     Notify all admins/technicians
                          │
                          ▼
                  Return success with report_id
```

### Status Workflow
```
pending ──► in_progress ──► completed
    │            │
    │            └──► cancelled
    │
    └──► rejected
         │
         └──► pending (can reopen)
```

---

## 8. API STRUCTURE

### Student Dashboard API
| Endpoint | Action | Description |
|----------|--------|-------------|
| `api/student_dashboard_api.php?action=get_stats` | GET | Get dashboard statistics |
| `api/student_dashboard_api.php?action=get_reports` | GET | Get user's defect reports |
| `api/student_dashboard_api.php?action=get_reservations` | GET | Get user's reservations |
| `api/student_dashboard_api.php?action=get_notifications` | GET | Get user notifications |
| `api/student_dashboard_api.php?action=create_report` | POST | Submit new defect report |
| `api/student_dashboard_api.php?action=create_reservation` | POST | Create equipment reservation |
| `api/student_dashboard_api.php?action=mark_notification_read` | POST | Mark notification as read |

### Technician Dashboard API
| Endpoint | Action | Description |
|----------|--------|-------------|
| `api/technician_dashboard_api.php?action=get_tasks` | GET | Get assigned tasks |
| `api/technician_dashboard_api.php?action=claim_task` | POST | Claim unassigned task |
| `api/technician_dashboard_api.php?action=update_status` | POST | Update defect status |
| `api/technician_dashboard_api.php?action=complete_task` | POST | Mark task as completed |

### Admin API
| Endpoint | Action | Description |
|----------|--------|-------------|
| `api/get_work_details.php` | GET | Get work order details |
| `api/export_reports.php` | GET | Export reports to CSV |
| `api/get_technicians.php` | GET | Get technician list |
| `api/get_admin_notifications.php` | GET | Get admin notifications |

---

## 9. DIRECTORY STRUCTURE

```
bec_equipment/
│
├── admin/
│   ├── admin_login_process.php      # Admin login handler
│   ├── admin_login_otp.html         # OTP verification UI
│   ├── admin_dashboard.php          # Admin main dashboard
│   ├── admin_users.php              # User management
│   ├── admin_inventory.php          # Equipment inventory
│   ├── admin_defect_reports.php     # Defect reports management
│   ├── admin_work_orders.php        # Work orders
│   ├── admin_verify_work.php        # Verify completed work
│   ├── admin_assign_technicians.php # Assign technicians
│   ├── admin_analytics.php          # Analytics & reports
│   ├── admin_settings.php           # System settings
│   └── reset_password.php           # Password reset page
│
├── student/
│   ├── login.html                   # Student login page
│   ├── student_login_process.php    # Student login handler
│   └── student_dashboard.php        # Student dashboard
│
├── technician/
│   ├── login.html                   # Technician login
│   ├── technician_login_process.php # Login handler
│   ├── technician_dashboard.php     # Technician dashboard
│   ├── technician_tasks.php         # Task list
│   ├── technician_task_details.php  # Task details
│   ├── technician_claim_task.php    # Claim task
│   ├── technician_complete_task.php # Complete task
│   └── technician_update_status.php # Update status
│
├── api/
│   ├── student_dashboard_api.php   # Student API
│   ├── technician_dashboard_api.php # Technician API
│   ├── get_work_details.php         # Work details
│   ├── export_reports.php           # Export reports
│   ├── get_technicians.php          # Get technicians
│   ├── cancel_reservation.php       # Cancel reservation
│   └── request_otp.php              # OTP request
│
├── config/
│   └── database.php                 # Database configuration
│
├── controllers/
│   └── studentDashboardController.php
│
├── includes/
│   ├── otp_helper.php               # OTP generation & verification
│   ├── mail_helper.php              # Email sending
│   ├── auth.php                     # Authentication
│   ├── validation.php               # Input validation
│   ├── notification_helper.php      # Notifications
│   ├── admin_sidebar.php            # Admin navigation
│   ├── user_navbar.php              # User navbar
│   └── csrf.php                     # CSRF protection
│
├── models/
│   ├── defectreport.php             # Defect report model
│   ├── defect_report_modal.php      # Report modal
│   ├── reservation_modal.php        # Reservation modal
│   └── defectreport.php             # Defect report model
│
├── css/
│   ├── admin_style.css
│   ├── user_styles.css
│   ├── technician_styles.css
│   ├── global_styles.css
│   └── handler_styles.css
│
├── js/
│   ├── admin_dashboard.js
│   ├── technician_dashboard.js
│   └── reservation_modal.js
│
├── data/
│   ├── inventory.json               # Inventory data (backup)
│   ├── equipment.json
│   ├── categories.json
│   └── ...
│
├── uploads/
│   ├── defect_photos/               # Uploaded defect photos
│   └── ...
│
├── assets/
│   ├── logo.png
│   ├── bec background (2).png
│   └── logs.png
│
├── index.php                        # Main entry point
├── logout.php                       # Logout handler
├── register_process.php             # Registration handler
└── README.md                        # Documentation
```

---

## 10. SECURITY FEATURES

| Feature | Implementation |
|---------|----------------|
| **Password Hashing** | `password_hash()` with PASSWORD_DEFAULT (bcrypt) |
| **OTP Verification** | 6-digit OTP with 5-minute expiry |
| **Session Management** | PHP sessions with login time tracking |
| **SQL Injection Prevention** | Prepared statements (`$stmt->bind_param()`) |
| **Input Validation** | Server-side validation in PHP |
| **Role-based Access** | Role check before every action |
| **File Upload Security** | MIME type validation, file size limits |
| **CORS Headers** | Access-Control-Allow-Origin headers |
| **Error Logging** | `error_log()` for debugging |

---

## 11. KEY BUSINESS RULES

### Equipment Status
- **available**: Ready for reservation/use
- **in-use**: Currently reserved or in use
- **maintenance**: Under repair/maintenance
- **defective**: Has reported defect
- **deleted**: Removed from system

### Defect Priority Auto-Detection
| Priority | Keywords |
|----------|----------|
| CRITICAL | fire, smoke, explosion, broken, not working, urgent, dangerous |
| HIGH | cracked, damaged, leaking, overheating, malfunction |
| MEDIUM | slow, issue, problem, minor damage |
| LOW | (default) |

### Reservation Rules
- Cannot reserve already booked equipment (conflict check)
- Status: pending → approved → active → completed
- Can cancel pending/approved reservations

### Repair Time Estimation
| Priority | Base Time | Category Multiplier |
|----------|-----------|---------------------|
| CRITICAL | 2 hours | ×1.5 for physical damage |
| HIGH | 4 hours | ×1.2 for performance |
| MEDIUM | 8 hours | ×0.8 for software |
| LOW | 24 hours | ×1.0 default |

---

## 12. CAMPUSES & BUILDINGS

### Main Campus
- Building 1: Gymnasium
- Building 2: Faculty & Student Center
- Building 3: Learning Resource Building
- Building 4: Diamond Building
- Building 5: TLE Building
- Building 6: Canteen & Support Services
- Building 7: Old HS Building
- Building 9: Temporary Building 2
- Building 22: Temporary Building

### Annex 1 Campus
- Building 12: Admin Services Building
- Building 13: BEC Skills Training Center
- Building 15: Pre-school Building
- Building 17: Grade School Building 1
- Building 18: Grade School Building 2

### Annex 2 Campus
- Building 21: Annex 2 Temporary Building
- SPC Building: TESDA
- GA Building: SHS

---

## 13. EQUIPMENT CATEGORIES

| Category | Sample Items |
|----------|--------------|
| Air Conditioners | Carrier, Panasonic, Kolin |
| Televisions | TCL, Skyworth, Samsung, Prestiz |
| Fans | Ceiling Fan, Stand Fan, Industrial Fan |
| Whiteboards | Glassboard, Whiteboard (Big/Medium/Small) |
| Lockers | Steel-Grey, Steel-White, Green |
| Office Chairs | Executive, Ordinary |
| Computers | HP, Dell, Lenovo, Acer, ASUS |

---

## 14. SYSTEM FLOW SUMMARY

```
┌────────────────────────────────────────────────────────────────┐
│                    BEC Equipment System                       │
└────────────────────────────────────────────────────────────────┘

    ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
    │   STUDENT    │     │  TECHNICIAN  │     │    ADMIN     │
    └──────┬───────┘     └──────┬───────┘     └──────┬───────┘
           │                    │                    │
           ▼                    ▼                    ▼
    ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
    │    Login     │     │    Login     │     │    Login     │
    │  + OTP Auth  │     │  + OTP Auth  │     │  + OTP Auth  │
    └──────┬───────┘     └──────┬───────┘     └──────┬───────┘
           │                    │                    │
           ▼                    ▼                    ▼
    ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
    │  Dashboard   │     │  Dashboard   │     │  Dashboard   │
    │  - Reports   │     │  - Tasks     │     │  - Overview  │
    │  - Reserve   │     │  - History   │     │  - Users     │
    │  - Notifs    │     │  - Update    │     │  - Equipment │
    └──────────────┘     └──────────────┘     │  - Reports   │
           │                    │              │  - Settings  │
           └────────┬───────────┘              └──────┬───────┘
                    │                                 │
                    ▼                                 ▼
            ┌─────────────────────────────────────────────┐
            │              SHARED DATABASE                │
            │                                             │
            │  users │_reports          equipment │ defect │
            │  reservations │ notifications │ otp_codes   │
            │  activity_log │ password_resets             │
            └─────────────────────────────────────────────┘
```

---

## 15. CONCLUSION

This **BEC Equipment Management System** is a robust, role-based application designed to streamline equipment management, defect reporting, and maintenance workflows across multiple campuses. The architecture follows modern web application patterns with clear separation of concerns, comprehensive security measures, and extensibility for future enhancements.

**Key Strengths:**
- ✅ Multi-role authentication with OTP security
- ✅ Automated priority and category detection
- ✅ Real-time notifications
- ✅ Comprehensive audit trail (activity logs)
- ✅ Duplicate report prevention
- ✅ Equipment status tracking
- ✅ Reservation management
- ✅ Analytics and reporting capabilities

---

*Document Version: 1.0*
*Last Updated: Based on codebase analysis*
