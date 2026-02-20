<?php
// admin_settings.php
session_start();
require_once 'includes/auth.php';
require_once 'config/database.php';

requireRole('admin');

$conn = getDBConnection();

// Define default settings
$default_settings = [
    'general' => [
        'system_name' => 'BEC Equipment Management System',
        'institution_name' => 'Batangas Eastern Colleges',
        'timezone' => 'Asia/Manila',
        'date_format' => 'M d, Y',
        'time_format' => '12h',
        'language' => 'en'
    ],
    'notifications' => [
        'email_enabled' => true,
        'sms_enabled' => false,
        'inapp_enabled' => true,
        'notify_new_reports' => true,
        'notify_new_reservations' => true,
        'notify_status_changes' => true,
        'admin_email' => 'thesterads@gmail.com'
    ],
    'security' => [
        'session_timeout' => 30,
        'password_min_length' => 8,
        'password_require_special' => true,
        'password_require_numbers' => true,
        'max_login_attempts' => 5,
        'lockout_duration' => 15
    ],
    'data' => [
        'backup_enabled' => true,
        'backup_frequency' => 'daily',
        'data_retention_days' => 365,
        'auto_archive' => true,
        'archive_after_days' => 180
    ],
    'ui' => [
        'theme' => 'light',
        'primary_color' => '#800000',
        'accent_color' => '#C9A227',
        'items_per_page' => 20,
        'enable_animations' => true
    ]
];

// Load current settings
$settings_file = 'data/system_settings.json';
if (!file_exists($settings_file)) {
    file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
    $settings = $default_settings;
} else {
    $settings = json_decode(file_get_contents($settings_file), true);
}

$settings = array_merge($default_settings, $settings ?? []);

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    
    if ($section === 'general') {
        $settings['general'] = [
            'system_name' => $_POST['system_name'],
            'institution_name' => $_POST['institution_name'],
            'timezone' => $_POST['timezone'],
            'date_format' => $_POST['date_format'],
            'time_format' => $_POST['time_format'],
            'language' => $_POST['language']
        ];
    } elseif ($section === 'notifications') {
        $settings['notifications'] = [
            'email_enabled' => isset($_POST['email_enabled']),
            'sms_enabled' => isset($_POST['sms_enabled']),
            'inapp_enabled' => isset($_POST['inapp_enabled']),
            'notify_new_reports' => isset($_POST['notify_new_reports']),
            'notify_new_reservations' => isset($_POST['notify_new_reservations']),
            'notify_status_changes' => isset($_POST['notify_status_changes']),
            'admin_email' => $_POST['admin_email']
        ];
    } elseif ($section === 'security') {
        $settings['security'] = [
            'session_timeout' => intval($_POST['session_timeout']),
            'password_min_length' => intval($_POST['password_min_length']),
            'password_require_special' => isset($_POST['password_require_special']),
            'password_require_numbers' => isset($_POST['password_require_numbers']),
            'max_login_attempts' => intval($_POST['max_login_attempts']),
            'lockout_duration' => intval($_POST['lockout_duration'])
        ];
    } elseif ($section === 'data') {
        $settings['data'] = [
            'backup_enabled' => isset($_POST['backup_enabled']),
            'backup_frequency' => $_POST['backup_frequency'],
            'data_retention_days' => intval($_POST['data_retention_days']),
            'auto_archive' => isset($_POST['auto_archive']),
            'archive_after_days' => intval($_POST['archive_after_days'])
        ];
    } elseif ($section === 'ui') {
        $settings['ui'] = [
            'theme' => $_POST['theme'],
            'primary_color' => $_POST['primary_color'],
            'accent_color' => $_POST['accent_color'],
            'items_per_page' => intval($_POST['items_per_page']),
            'enable_animations' => isset($_POST['enable_animations'])
        ];
    }
    
    file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
    $_SESSION['success_message'] = 'Settings updated successfully';
    header('Location: admin_settings.php');
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_style.css">
    <style>
        .settings-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .settings-tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .settings-tab:hover {
            color: var(--bec-maroon);
        }
        
        .settings-tab.active {
            color: var(--bec-maroon);
            border-bottom-color: var(--bec-maroon);
        }
        
        .settings-section {
            display: none;
        }
        
        .settings-section.active {
            display: block;
        }
        
        .settings-group {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .settings-group h3 {
            color: var(--bec-maroon);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--bec-maroon);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .color-picker {
            width: 60px;
            height: 40px;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include 'includes/admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <div class="page-title-section">
                <h1 class="page-title">System Settings</h1>
                <div class="breadcrumb">
                    <span>Home</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Settings</span>
                </div>
            </div>
        </div>

        <div class="content-area">
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
            <?php endif; ?>

            <div class="settings-tabs">
                <button class="settings-tab active" onclick="showTab('general')">
                    <i class="fas fa-cog"></i> General
                </button>
                <button class="settings-tab" onclick="showTab('notifications')">
                    <i class="fas fa-bell"></i> Notifications
                </button>
                <button class="settings-tab" onclick="showTab('security')">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="settings-tab" onclick="showTab('data')">
                    <i class="fas fa-database"></i> Data Management
                </button>
                <button class="settings-tab" onclick="showTab('ui')">
                    <i class="fas fa-palette"></i> UI Preferences
                </button>
            </div>

            <!-- General Settings -->
            <div class="settings-section active" id="general-section">
                <form method="POST">
                    <input type="hidden" name="section" value="general">
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-building"></i> System Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">System Name</label>
                                <input type="text" class="form-control" name="system_name" value="<?php echo htmlspecialchars($settings['general']['system_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Institution Name</label>
                                <input type="text" class="form-control" name="institution_name" value="<?php echo htmlspecialchars($settings['general']['institution_name']); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="settings-group">
                        <h3><i class="fas fa-globe"></i> Regional Settings</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Timezone</label>
                                <select class="form-control" name="timezone">
                                    <option value="Asia/Manila" <?php echo $settings['general']['timezone'] === 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila (UTC+8)</option>
                                    <option value="UTC" <?php echo $settings['general']['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Language</label>
                                <select class="form-control" name="language">
                                    <option value="en" <?php echo $settings['general']['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                                    <option value="fil" <?php echo $settings['general']['language'] === 'fil' ? 'selected' : ''; ?>>Filipino</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Date Format</label>
                                <select class="form-control" name="date_format">
                                    <option value="M d, Y" <?php echo $settings['general']['date_format'] === 'M d, Y' ? 'selected' : ''; ?>>Jan 14, 2026</option>
                                    <option value="d/m/Y" <?php echo $settings['general']['date_format'] === 'd/m/Y' ? 'selected' : ''; ?>>14/01/2026</option>
                                    <option value="Y-m-d" <?php echo $settings['general']['date_format'] === 'Y-m-d' ? 'selected' : ''; ?>>2026-01-14</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Time Format</label>
                                <select class="form-control" name="time_format">
                                    <option value="12h" <?php echo $settings['general']['time_format'] === '12h' ? 'selected' : ''; ?>>12-hour (3:00 PM)</option>
                                    <option value="24h" <?php echo $settings['general']['time_format'] === '24h' ? 'selected' : ''; ?>>24-hour (15:00)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save General Settings
                    </button>
                </form>
            </div>

            <!-- Notification Settings -->
            <div class="settings-section" id="notifications-section">
                <form method="POST">
                    <input type="hidden" name="section" value="notifications">
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-toggle-on"></i> Notification Channels</h3>
                        
                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Email Notifications</label>
                            <label class="switch">
                                <input type="checkbox" name="email_enabled" <?php echo $settings['notifications']['email_enabled'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">SMS Notifications</label>
                            <label class="switch">
                                <input type="checkbox" name="sms_enabled" <?php echo $settings['notifications']['sms_enabled'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">In-App Notifications</label>
                            <label class="switch">
                                <input type="checkbox" name="inapp_enabled" <?php echo $settings['notifications']['inapp_enabled'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-group">
                        <h3><i class="fas fa-list-check"></i> Notification Events</h3>
                        
                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">New Defect Reports</label>
                            <label class="switch">
                                <input type="checkbox" name="notify_new_reports" <?php echo $settings['notifications']['notify_new_reports'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">New Reservations</label>
                            <label class="switch">
                                <input type="checkbox" name="notify_new_reservations" <?php echo $settings['notifications']['notify_new_reservations'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Status Changes</label>
                            <label class="switch">
                                <input type="checkbox" name="notify_status_changes" <?php echo $settings['notifications']['notify_status_changes'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Admin Email Address</label>
                            <input type="email" class="form-control" name="admin_email" value="<?php echo htmlspecialchars($settings['notifications']['admin_email']); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Notification Settings
                    </button>
                </form>
            </div>

            <!-- Security Settings -->
            <div class="settings-section" id="security-section">
                <form method="POST">
                    <input type="hidden" name="section" value="security">
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-lock"></i> Session Security</h3>
                        
                        <div class="form-group">
                            <label class="form-label">Session Timeout (minutes)</label>
                            <input type="number" class="form-control" name="session_timeout" value="<?php echo $settings['security']['session_timeout']; ?>" min="5" max="120" required>
                        </div>
                    </div>

                    <div class="settings-group">
                        <h3><i class="fas fa-key"></i> Password Requirements</h3>
                        
                        <div class="form-group">
                            <label class="form-label">Minimum Password Length</label>
                            <input type="number" class="form-control" name="password_min_length" value="<?php echo $settings['security']['password_min_length']; ?>" min="6" max="20" required>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Require Special Characters</label>
                            <label class="switch">
                                <input type="checkbox" name="password_require_special" <?php echo $settings['security']['password_require_special'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Require Numbers</label>
                            <label class="switch">
                                <input type="checkbox" name="password_require_numbers" <?php echo $settings['security']['password_require_numbers'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-group">
                        <h3><i class="fas fa-user-lock"></i> Login Security</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Max Login Attempts</label>
                                <input type="number" class="form-control" name="max_login_attempts" value="<?php echo $settings['security']['max_login_attempts']; ?>" min="3" max="10" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Lockout Duration (minutes)</label>
                                <input type="number" class="form-control" name="lockout_duration" value="<?php echo $settings['security']['lockout_duration']; ?>" min="5" max="60" required>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Security Settings
                    </button>
                </form>
            </div>

            <!-- Data Management Settings -->
            <div class="settings-section" id="data-section">
                <form method="POST">
                    <input type="hidden" name="section" value="data">
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-database"></i> Backup Configuration</h3>
                        
                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Enable Automatic Backups</label>
                            <label class="switch">
                                <input type="checkbox" name="backup_enabled" <?php echo $settings['data']['backup_enabled'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Backup Frequency</label>
                            <select class="form-control" name="backup_frequency">
                                <option value="daily" <?php echo $settings['data']['backup_frequency'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo $settings['data']['backup_frequency'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo $settings['data']['backup_frequency'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            </select>
                        </div>

                        <button type="button" class="btn btn-secondary" onclick="createBackupNow()">
                            <i class="fas fa-download"></i> Create Backup Now
                        </button>
                    </div>

                    <div class="settings-group">
                        <h3><i class="fas fa-archive"></i> Data Retention</h3>
                        
                        <div class="form-group">
                            <label class="form-label">Data Retention Period (days)</label>
                            <input type="number" class="form-control" name="data_retention_days" value="<?php echo $settings['data']['data_retention_days']; ?>" min="30" max="3650" required>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Auto-Archive Old Records</label>
                            <label class="switch">
                                <input type="checkbox" name="auto_archive" <?php echo $settings['data']['auto_archive'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Archive After (days)</label>
                            <input type="number" class="form-control" name="archive_after_days" value="<?php echo $settings['data']['archive_after_days']; ?>" min="30" max="365" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Data Settings
                    </button>
                </form>
            </div>

            <!-- UI Preferences -->
            <div class="settings-section" id="ui-section">
                <form method="POST">
                    <input type="hidden" name="section" value="ui">
                    
                    <div class="settings-group">
                        <h3><i class="fas fa-palette"></i> Appearance</h3>
                        
                        <div class="form-group">
                            <label class="form-label">Theme</label>
                            <select class="form-control" name="theme">
                                <option value="light" <?php echo $settings['ui']['theme'] === 'light' ? 'selected' : ''; ?>>Light</option>
                                <option value="dark" <?php echo $settings['ui']['theme'] === 'dark' ? 'selected' : ''; ?>>Dark</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Primary Color</label>
                                <input type="color" class="color-picker" name="primary_color" value="<?php echo $settings['ui']['primary_color']; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Accent Color</label>
                                <input type="color" class="color-picker" name="accent_color" value="<?php echo $settings['ui']['accent_color']; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="settings-group">
                        <h3><i class="fas fa-table"></i> Display Options</h3>
                        
                        <div class="form-group">
                            <label class="form-label">Items Per Page</label>
                            <select class="form-control" name="items_per_page">
                                <option value="10" <?php echo $settings['ui']['items_per_page'] === 10 ? 'selected' : ''; ?>>10</option>
                                <option value="20" <?php echo $settings['ui']['items_per_page'] === 20 ? 'selected' : ''; ?>>20</option>
                                <option value="50" <?php echo $settings['ui']['items_per_page'] === 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $settings['ui']['items_per_page'] === 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>

                        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label" style="margin: 0;">Enable Animations</label>
                            <label class="switch">
                                <input type="checkbox" name="enable_animations" <?php echo $settings['ui']['enable_animations'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save UI Preferences
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected section
            document.getElementById(tabName + '-section').classList.add('active');
            event.target.closest('.settings-tab').classList.add('active');
        }

        function createBackupNow() {
            if (confirm('Create a backup of all system data now?')) {
                window.location.href = 'api/create_backup.php';
            }
        }
    </script>
</body>
</html>