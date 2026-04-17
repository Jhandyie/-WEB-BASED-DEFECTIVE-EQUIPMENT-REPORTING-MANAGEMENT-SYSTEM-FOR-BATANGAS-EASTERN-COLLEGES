<?php
/**
 * Gmail SMTP Email Helper
 * Uses direct SMTP connection with TLS authentication
 */

require_once __DIR__ . '/../config/database.php';

function sendEmail($to, $subject, $message, $settings = null, $role = 'admin') {
    // If explicit settings are provided, use them directly.
    if ($settings) {
        $smtp_result = sendEmailSMTP($to, $subject, $message, $settings);
        if ($smtp_result) {
            return true;
        }

        error_log("SMTP failed, falling back to PHP mail() function");
        return sendEmailPHP($to, $subject, $message, $settings);
    }

    // Try all configured SMTP accounts for the role (primary + additional accounts).
    $email_candidates = getEmailSettingsCandidatesByRole($role);
    foreach ($email_candidates as $index => $email_settings) {
        if (sendEmailSMTP($to, $subject, $message, $email_settings)) {
            return true;
        }
        error_log("SMTP failed for role {$role} account index {$index}");
    }

    // Final fallback to PHP mail() using the first candidate.
    $fallback_settings = $email_candidates[0] ?? getEmailSettingsByRole($role);
    error_log("All SMTP accounts failed, falling back to PHP mail() function");
    return sendEmailPHP($to, $subject, $message, $fallback_settings);
}

function getEmailSettingsCandidatesByRole($role = 'admin') {
    $settings_file = __DIR__ . '/../data/system_settings.json';
    if (!file_exists($settings_file)) {
        error_log("Settings file not found: $settings_file");
        return [];
    }

    $settings = json_decode(file_get_contents($settings_file), true);
    $email_config = $settings['email'] ?? [];

    $base_settings = [
        'smtp_host' => $email_config['smtp_host'] ?? 'smtp.gmail.com',
        'smtp_port' => $email_config['smtp_port'] ?? 587,
    ];

    $role_key = strtolower((string)$role);
    $role_settings = $email_config[$role_key] ?? [];

    $candidates = [];

    if (!empty($role_settings['smtp_accounts']) && is_array($role_settings['smtp_accounts'])) {
        foreach ($role_settings['smtp_accounts'] as $account) {
            if (!is_array($account)) {
                continue;
            }
            $candidates[] = array_merge($base_settings, $role_settings, $account);
        }
    }

    if (empty($candidates)) {
        $candidates[] = array_merge($base_settings, $role_settings);
    }

    foreach ($candidates as &$candidate) {
        unset($candidate['smtp_accounts']);
    }
    unset($candidate);

    return $candidates;
}

function getEmailSettingsByRole($role = 'admin') {
    $candidates = getEmailSettingsCandidatesByRole($role);
    return $candidates[0] ?? null;
}
function sendEmailSMTP($to, $subject, $message, $settings = null) {
    if (!$settings) {
        $settings_file = __DIR__ . '/../data/system_settings.json';
        if (!file_exists($settings_file)) {
            error_log("Settings file not found: $settings_file");
            return false;
        }
        $settings = json_decode(file_get_contents($settings_file), true);
        $settings = $settings['email'] ?? [];
    }

    if (empty($settings['smtp_host']) || empty($settings['smtp_username']) || empty($settings['smtp_password'])) {
        error_log("SMTP settings not configured properly");
        return false;
    }

    $smtp_host = $settings['smtp_host'];
    $smtp_port = $settings['smtp_port'] ?? 587;
    $username = $settings['smtp_username'];
    $password = $settings['smtp_password'];
    $from_email = $settings['from_email'] ?? $username;
    $from_name = $settings['from_name'] ?? 'BEC Equipment Management System';

    $boundary = md5(uniqid(time()));
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $headers .= "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $email_content = "--$boundary\r\n";
    $email_content .= "Content-Type: text/html; charset=UTF-8\r\n";
    $email_content .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $email_content .= $message . "\r\n\r\n";
    $email_content .= "--$boundary--";

    $socket = fsockopen("tcp://" . $smtp_host, $smtp_port, $errno, $errstr, 30);
    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    stream_set_blocking($socket, true);

    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 220)) {
        fclose($socket);
        return false;
    }

    fputs($socket, "EHLO localhost\r\n");
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }

    fputs($socket, "STARTTLS\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 220)) {
        error_log("STARTTLS failed");
        fclose($socket);
        return false;
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        error_log("Failed to enable TLS encryption");
        fclose($socket);
        return false;
    }

    fputs($socket, "EHLO localhost\r\n");
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }

    fputs($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 334)) {
        fclose($socket);
        error_log("AUTH LOGIN failed");
        return false;
    }

    fputs($socket, base64_encode($username) . "\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 334)) {
        fclose($socket);
        error_log("Username authentication failed");
        return false;
    }

    fputs($socket, base64_encode($password) . "\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 235)) {
        fclose($socket);
        error_log("Password authentication failed");
        return false;
    }

    fputs($socket, "MAIL FROM: <$from_email>\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 250)) {
        fclose($socket);
        error_log("MAIL FROM failed");
        return false;
    }

    fputs($socket, "RCPT TO: <$to>\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 250)) {
        fclose($socket);
        error_log("RCPT TO failed");
        return false;
    }

    fputs($socket, "DATA\r\n");
    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 354)) {
        fclose($socket);
        error_log("DATA command failed");
        return false;
    }

    fputs($socket, "Subject: $subject\r\n");
    fputs($socket, $headers);
    fputs($socket, "\r\n");
    fputs($socket, $email_content);
    fputs($socket, "\r\n.\r\n");

    $response = fgets($socket, 515);
    if (!smtp_check_response($response, 250)) {
        fclose($socket);
        error_log("Email sending failed");
        return false;
    }

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

function sendEmailPHP($to, $subject, $message, $settings = null) {
    if (!$settings) {
        $settings_file = __DIR__ . '/../data/system_settings.json';
        if (!file_exists($settings_file)) {
            error_log("Settings file not found: $settings_file");
            return false;
        }
        $settings = json_decode(file_get_contents($settings_file), true);
        $settings = $settings['email'] ?? [];
    }

    $from_email = $settings['from_email'] ?? 'noreply@bec.edu';
    $from_name = $settings['from_name'] ?? 'BEC Equipment Management System';

    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: $from_name <$from_email>" . "\r\n";
    $headers .= "Reply-To: $from_email" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $result = mail($to, $subject, $message, $headers);

    if ($result) {
        error_log("Email sent successfully using PHP mail() to: $to");
    } else {
        error_log("PHP mail() failed for: $to");
    }

    return $result;
}

function smtp_check_response($response, $expected_code) {
    $code = substr($response, 0, 3);
    return $code == $expected_code;
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail($email, $reset_link) {
    $subject = "Password Reset - BEC Equipment Management System";

    $year = date('Y');

    $message = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Password Reset - BEC Equipment Management System</title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap');

            * { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                background-color: #0f0f14;
                font-family: 'DM Sans', Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                -webkit-text-size-adjust: 100%;
            }

            .wrapper {
                background: linear-gradient(160deg, #0f0f14 0%, #1a0a0a 50%, #0f0f14 100%);
                padding: 40px 20px;
                min-height: 100vh;
            }

            .email-container {
                max-width: 600px;
                margin: 0 auto;
                border-radius: 20px;
                overflow: hidden;
                box-shadow:
                    0 0 0 1px rgba(128,0,0,0.3),
                    0 25px 80px rgba(0,0,0,0.6),
                    0 0 60px rgba(128,0,0,0.08);
            }

            /* -- HEADER -- */
            .header {
                background: linear-gradient(145deg, #6b0000 0%, #8b0000 40%, #a30000 70%, #6b0000 100%);
                padding: 48px 40px 40px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .header-bg-circle {
                position: absolute;
                border-radius: 50%;
                background: rgba(255,255,255,0.04);
            }
            .header-bg-circle.c1 { width: 300px; height: 300px; top: -100px; right: -80px; }
            .header-bg-circle.c2 { width: 200px; height: 200px; bottom: -80px; left: -50px; }
            .header-bg-circle.c3 { width: 120px; height: 120px; top: 20px; left: 30px; background: rgba(255,255,255,0.03); }

            .logo-block {
                position: relative;
                z-index: 2;
                margin-bottom: 22px;
                display: inline-block;
            }

            /* BECT Shield SVG Logo */
            .bect-shield {
                width: 72px;
                height: 80px;
                margin: 0 auto 10px;
                display: block;
                filter: drop-shadow(0 4px 12px rgba(0,0,0,0.5));
            }

            .logo-text {
                font-family: 'Playfair Display', Georgia, serif;
                font-size: 13px;
                font-weight: 700;
                color: rgba(255,255,255,0.9);
                letter-spacing: 3px;
                text-transform: uppercase;
            }

            .header-divider {
                width: 60px;
                height: 2px;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
                margin: 18px auto;
            }

            .header-title {
                font-family: 'Playfair Display', Georgia, serif;
                color: #ffffff;
                font-size: 26px;
                font-weight: 800;
                margin: 0 0 8px;
                position: relative;
                z-index: 2;
                letter-spacing: -0.3px;
                text-shadow: 0 2px 8px rgba(0,0,0,0.4);
            }

            .header-subtitle {
                color: rgba(255,255,255,0.8);
                font-size: 14px;
                font-weight: 300;
                letter-spacing: 0.5px;
                position: relative;
                z-index: 2;
            }

            /* -- CONTENT -- */
            .content {
                padding: 44px 40px;
                background: #fafafa;
            }

            .greeting {
                font-size: 19px;
                font-weight: 600;
                color: #1a1a1a;
                margin-bottom: 14px;
            }

            .intro-text {
                font-size: 15px;
                color: #555;
                line-height: 1.75;
                margin-bottom: 32px;
            }

            /* Reset Button */
            .cta-wrapper {
                text-align: center;
                margin: 36px 0;
            }

            .reset-btn {
                display: inline-block;
                background: linear-gradient(135deg, #8b0000 0%, #a30000 100%);
                color: #ffffff !important;
                text-decoration: none;
                padding: 16px 44px;
                border-radius: 50px;
                font-size: 16px;
                font-weight: 600;
                letter-spacing: 0.3px;
                box-shadow:
                    0 8px 24px rgba(139,0,0,0.4),
                    0 2px 8px rgba(0,0,0,0.15),
                    inset 0 1px 0 rgba(255,255,255,0.15);
            }

            .expiry-badge {
                display: inline-block;
                background: #fff3f3;
                border: 1px solid #ffd0d0;
                border-radius: 20px;
                padding: 6px 16px;
                font-size: 13px;
                color: #8b0000;
                font-weight: 500;
                margin-top: 16px;
            }

            /* URL fallback */
            .url-fallback {
                background: #f5f5f5;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 14px 16px;
                margin: 28px 0;
            }

            .url-fallback p {
                font-size: 13px;
                color: #666;
                margin-bottom: 8px;
            }

            .url-fallback a {
                font-size: 12px;
                color: #8b0000;
                word-break: break-all;
                line-height: 1.5;
            }

            /* Notice strip */
            .notice-strip {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                background: #fffbf0;
                border-left: 4px solid #c9a227;
                border-radius: 0 8px 8px 0;
                padding: 16px 18px;
                margin: 28px 0;
            }

            .notice-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

            .notice-text {
                font-size: 14px;
                color: #7a6010;
                line-height: 1.6;
            }

            /* Signature */
            .signature {
                text-align: center;
                padding-top: 28px;
                border-top: 1px solid #ebebeb;
                margin-top: 32px;
            }

            .sig-label {
                font-size: 13px;
                color: #999;
                margin-bottom: 4px;
            }

            .sig-name {
                font-family: 'Playfair Display', Georgia, serif;
                font-size: 17px;
                font-weight: 700;
                color: #8b0000;
            }

            /* -- FOOTER -- */
            .footer {
                background: #1a1a2e;
                color: rgba(255,255,255,0.6);
                padding: 28px 40px;
                text-align: center;
            }

            .footer-brand {
                font-family: 'Playfair Display', Georgia, serif;
                font-size: 14px;
                font-weight: 700;
                color: rgba(255,255,255,0.85);
                letter-spacing: 1px;
                margin-bottom: 8px;
            }

            .footer-text {
                font-size: 12px;
                line-height: 1.7;
                color: rgba(255,255,255,0.45);
            }

            .footer-divider {
                width: 40px;
                height: 1px;
                background: rgba(255,255,255,0.15);
                margin: 12px auto;
            }

            @media only screen and (max-width: 600px) {
                .header, .content, .footer { padding-left: 24px; padding-right: 24px; }
                .header-title { font-size: 22px; }
                .reset-btn { padding: 14px 32px; font-size: 15px; }
                .email-container { border-radius: 12px; }
            }
        </style>
    </head>
    <body>
        <div class="wrapper">
            <div class="email-container">

                <!-- Header -->
                <div class="header">
                    <div class="header-bg-circle c1"></div>
                    <div class="header-bg-circle c2"></div>
                    <div class="header-bg-circle c3"></div>

                    <div class="logo-block">
                        <!-- BECT Shield SVG -->
                        <svg class="bect-shield" viewBox="0 0 72 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Shield outer shape -->
                            <path d="M36 2L4 14V42C4 58 36 78 36 78C36 78 68 58 68 42V14L36 2Z" fill="rgba(255,255,255,0.12)" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"/>
                            <!-- Shield inner accent -->
                            <path d="M36 8L10 18V42C10 55 36 72 36 72C36 72 62 55 62 42V18L36 8Z" fill="rgba(255,255,255,0.07)"/>
                            <!-- Cross / Book symbol -->
                            <path d="M33 22H39V38H33V22Z" fill="rgba(255,255,255,0.85)"/>
                            <path d="M26 29H46V35H26V29Z" fill="rgba(255,255,255,0.85)"/>
                            <!-- Stars / Dots -->
                            <circle cx="22" cy="50" r="3" fill="rgba(201,162,39,0.9)"/>
                            <circle cx="36" cy="55" r="3" fill="rgba(201,162,39,0.9)"/>
                            <circle cx="50" cy="50" r="3" fill="rgba(201,162,39,0.9)"/>
                            <!-- Bottom text line -->
                            <rect x="20" y="60" width="32" height="2" rx="1" fill="rgba(255,255,255,0.4)"/>
                        </svg>
                        <div class="logo-text">Batangas Eastern Colleges</div>
                    </div>

                    <div class="header-divider"></div>
                    <h1 class="header-title">Password Reset</h1>
                    <p class="header-subtitle">Equipment Management System</p>
                </div>

                <!-- Content -->
                <div class="content">
                    <p class="greeting">Hello,</p>

                    <p class="intro-text">
                        We received a request to reset your password for the
                        <strong>BEC Equipment Management System</strong>.
                        Click the button below to create a new password for your account.
                    </p>

                    <!-- CTA Button -->
                    <div class="cta-wrapper">
                        <a href="{$reset_link}" class="reset-btn">Reset My Password</a>
                        <br>
                        <span class="expiry-badge">? Expires in 1 hour</span>
                    </div>

                    <!-- Security notice -->
                    <div class="notice-strip">
                        <span class="notice-icon">??</span>
                        <div class="notice-text">
                            If you did not request a password reset, please ignore this email. Your password will remain unchanged. Contact IT support if you suspect unauthorized activity.
                        </div>
                    </div>

                    <!-- URL fallback -->
                    <div class="url-fallback">
                        <p>If the button above doesn't work, copy and paste this link into your browser:</p>
                        <a href="{$reset_link}">{$reset_link}</a>
                    </div>

                    <!-- Signature -->
                    <div class="signature">
                        <p class="sig-label">Sent by</p>
                        <p class="sig-name">BEC Equipment Management System</p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="footer">
                    <div class="footer-brand">?? BATANGAS EASTERN COLLEGES</div>
                    <div class="footer-divider"></div>
                    <p class="footer-text">
                        © {$year} Batangas Eastern Colleges. All rights reserved.<br>
                        This is an automated message — please do not reply to this email.
                    </p>
                </div>

            </div>
        </div>
    </body>
    </html>
HTML;

    return sendEmail($email, $subject, $message);
}

function getActiveRoleEmailRecipients(array $roles): array {
    $roles = array_values(array_filter(array_map(
        static fn($role) => strtolower(trim((string)$role)),
        $roles
    )));

    if ($roles === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $typeString = str_repeat('s', count($roles));

    $conn = getDBConnection();
    $sql = "SELECT user_id, fullname, email, role
            FROM users
            WHERE role IN ($placeholders)
              AND status = 'active'
              AND email IS NOT NULL
              AND email != ''
            ORDER BY fullname ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('getActiveRoleEmailRecipients() prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param($typeString, ...$roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_values(array_filter($rows, static function ($row) {
        return filter_var((string)($row['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    }));
}

function sendDefectWorkflowEmail(array $report, array $targetRoles, string $subject, string $headline, string $summary, array $options = []): array {
    $recipients = getActiveRoleEmailRecipients($targetRoles);
    if ($recipients === []) {
        return ['sent' => 0, 'failed' => 0, 'recipients' => []];
    }

    $reportId = trim((string)($report['report_id'] ?? 'N/A'));
    $equipmentName = trim((string)($report['equipment_name'] ?? 'Equipment'));
    $assetTag = trim((string)($report['asset_tag'] ?? 'Not specified'));
    $category = trim((string)($report['category_name'] ?? $report['category'] ?? 'Not specified'));
    $location = trim((string)($report['location'] ?? 'Not specified'));
    $priority = ucfirst(trim((string)($report['priority'] ?? 'medium')));
    $statusRaw = trim((string)($report['status'] ?? 'reported'));
    $statusLabel = function_exists('defectStatusLabel')
        ? defectStatusLabel($statusRaw)
        : ucwords(str_replace('_', ' ', $statusRaw));
    $reportDate = trim((string)($report['report_date'] ?? date('Y-m-d H:i:s')));
    $reporterName = trim((string)($report['reporter_name'] ?? $report['reported_by'] ?? 'Not specified'));
    $department = trim((string)($report['department_assigned'] ?? 'Not yet assigned'));
    $issueDescription = nl2br(htmlspecialchars(trim((string)($report['issue_description'] ?? 'No description provided.'))));
    $requestedAction = trim((string)($options['requested_action'] ?? 'Please review this report and record your decision in the system at the earliest opportunity.'));
    $notesLabel = trim((string)($options['notes_label'] ?? 'Workflow Notes'));
    $notes = trim((string)($options['notes'] ?? ''));

    $notesSection = '';
    if ($notes !== '') {
        $notesSection = '
            <div style="margin-top:18px;padding:14px 16px;background:#f8f1eb;border:1px solid #ead9cd;border-radius:10px;">
                <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#7a5a49;margin-bottom:6px;">' . htmlspecialchars($notesLabel) . '</div>
                <div style="font-size:14px;line-height:1.65;color:#2f221d;">' . nl2br(htmlspecialchars($notes)) . '</div>
            </div>';
    }

    $message = '
    <html>
    <body style="margin:0;padding:24px;background:#f4efe9;font-family:Arial,sans-serif;color:#241713;">
        <div style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #e7d8cc;border-radius:18px;overflow:hidden;">
            <div style="padding:24px 28px;background:linear-gradient(135deg,#6f1d1b,#9f3b2e);color:#ffffff;">
                <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.82;">BEC Equipment Management System</div>
                <h1 style="margin:10px 0 8px;font-size:26px;line-height:1.25;">' . htmlspecialchars($headline) . '</h1>
                <p style="margin:0;font-size:15px;line-height:1.65;opacity:.94;">' . htmlspecialchars($summary) . '</p>
            </div>
            <div style="padding:24px 28px;">
                <p style="margin:0 0 16px;font-size:14px;line-height:1.7;">Good day. This is a formal notification regarding an equipment defect report that requires monitoring for verification and approval within the established workflow.</p>
                <table style="width:100%;border-collapse:collapse;">
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;width:34%;">Report ID</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($reportId) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Equipment</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($equipmentName) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Asset Tag</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($assetTag) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Category</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($category) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Location</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($location) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Priority</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($priority) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Current Status</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($statusLabel) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Department</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($department) . '</td></tr>
                    <tr><td style="padding:10px 0;border-bottom:1px solid #efe3da;font-weight:700;">Reported By</td><td style="padding:10px 0;border-bottom:1px solid #efe3da;">' . htmlspecialchars($reporterName) . '</td></tr>
                    <tr><td style="padding:10px 0;font-weight:700;">Report Date</td><td style="padding:10px 0;">' . htmlspecialchars($reportDate) . '</td></tr>
                </table>
                <div style="margin-top:18px;padding:16px;border:1px solid #ead9cd;border-radius:10px;background:#fcf8f5;">
                    <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#7a5a49;margin-bottom:8px;">Defect Details</div>
                    <div style="font-size:14px;line-height:1.7;color:#2f221d;">' . $issueDescription . '</div>
                </div>'
                . $notesSection .
                '<div style="margin-top:18px;padding:16px;border-radius:10px;background:#eef6ff;border:1px solid #cfe0ff;">
                    <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#284b8f;margin-bottom:8px;">Requested Action</div>
                    <div style="font-size:14px;line-height:1.7;color:#1f2d3d;">' . htmlspecialchars($requestedAction) . '</div>
                </div>
                <p style="margin:20px 0 0;font-size:14px;line-height:1.7;">Thank you for your prompt attention to this matter.</p>
                <p style="margin:18px 0 0;font-size:14px;line-height:1.7;">Sincerely,<br><strong>BEC Equipment Management System</strong></p>
            </div>
        </div>
    </body>
    </html>';

    $sent = 0;
    $failed = 0;
    foreach ($recipients as $recipient) {
        $ok = sendEmail((string)$recipient['email'], $subject, $message, null, 'admin');
        if ($ok) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return [
        'sent' => $sent,
        'failed' => $failed,
        'recipients' => array_column($recipients, 'email'),
    ];
}


