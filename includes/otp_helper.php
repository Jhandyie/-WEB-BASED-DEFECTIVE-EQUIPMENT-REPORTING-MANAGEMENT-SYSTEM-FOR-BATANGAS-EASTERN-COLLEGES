<?php
// includes/otp_helper.php
// OTP Email Authentication Helper Functions

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail_helper.php';

function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendOTPEmail($email, $otp, $role = 'admin') {
    $subject = "BEC Equipment System - Your Login Code";

    $roleNames = [
        'admin'      => 'Administrator',
        'handler'    => 'Equipment Handler',
        'technician' => 'Maintenance Technician',
        'student'    => 'Student',
        'faculty'    => 'Faculty Member',
        'guest'      => 'Guest',
    ];
    $roleName = $roleNames[$role] ?? 'User';

    $d = str_split(str_pad($otp, 6, '0', STR_PAD_LEFT));
    $year = date('Y');

    $digitStyle = "width:44px;height:56px;background-color:#ffffff;border:2px solid #d8a8a8;border-radius:10px;font-size:28px;font-weight:900;color:#8b0000;font-family:'Courier New',Courier,monospace;text-align:center;vertical-align:middle;";

    $message =
        '<!DOCTYPE html>'
      . '<html lang="en"><head>'
      . '<meta charset="UTF-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
      . '<title>BEC Equipment System - Login Verification</title>'
      . '</head>'
      . '<body style="margin:0;padding:0;background-color:#0f0a1e;font-family:Georgia,serif;">'

      // ── OUTER WRAPPER
      . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0f0a1e;">'
      . '<tr><td align="center" valign="top" style="padding:36px 16px 52px;">'

      // ── CARD
      . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;border-radius:24px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,0.6);">'

      // ══ HERO BANNER — CSS-only, email-safe, no SVG/images ══
      . '<tr><td style="padding:0;background:#1a0808;">'
          // Top accent bar
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'          . '<tr>'          . '<td style="width:15%;height:5px;background:#0d0404;font-size:0;">&nbsp;</td>'          . '<td style="width:70%;height:5px;background:#c9a227;font-size:0;">&nbsp;</td>'          . '<td style="width:15%;height:5px;background:#0d0404;font-size:0;">&nbsp;</td>'          . '</tr></table>'
          // Main banner body - dark maroon with subtle gradient via layered cells
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#2d0505;">'          . '<tr>'
              // Left decorative strip
              . '<td width="20" style="width:20px;background-color:#1a0303;font-size:0;">&nbsp;</td>'
              // Left ornament column
              . '<td width="60" valign="middle" align="center" style="padding:0;">'              . '<table cellpadding="0" cellspacing="0" border="0"><tr><td align="center">'              . '<p style="margin:0;font-size:28px;color:rgba(201,162,39,0.25);line-height:1;">&#10022;</p>'              . '<p style="margin:4px 0 0;font-size:10px;color:rgba(201,162,39,0.15);line-height:1;">&#9632;</p>'              . '<p style="margin:4px 0 0;font-size:10px;color:rgba(201,162,39,0.15);line-height:1;">&#9632;</p>'              . '</td></tr></table>'              . '</td>'
              // Vertical divider
              . '<td width="1" style="width:1px;background:rgba(201,162,39,0.15);font-size:0;">&nbsp;</td>'
              // Center content
              . '<td valign="middle" align="center" style="padding:32px 20px 28px;">'
                  // Large crest icon using Unicode shield + overlaid text
                  . '<table cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 14px;">'                  . '<tr><td align="center">'
                      // Outer ring
                      . '<table cellpadding="0" cellspacing="0" border="0" align="center">'                      . '<tr><td align="center" width="96" height="96" '                      . 'style="width:96px;height:96px;border-radius:50%;'                      . 'background-color:#1a0808;'                      . 'border:2px solid rgba(201,162,39,0.7);'                      . 'font-size:0;line-height:0;vertical-align:middle;text-align:center;">'
                          // Inner table for centering content
                          . '<table cellpadding="0" cellspacing="0" border="0" width="96" style="margin:0 auto;"><tr>'                          . '<td align="center" valign="middle" height="96" style="vertical-align:middle;padding:0;">'                          . '<p style="margin:0;font-family:Georgia,serif;font-size:26px;font-weight:900;'                          . 'color:#c9a227;letter-spacing:2px;line-height:1;">BEC</p>'                          . '<p style="margin:0;font-family:Arial,sans-serif;font-size:6px;font-weight:700;'                          . 'color:rgba(201,162,39,0.7);letter-spacing:2px;text-transform:uppercase;line-height:2;">&#9670;</p>'                          . '</td></tr></table>'
                      . '</td></tr></table>'
                  . '</td></tr></table>'
                  // School name
                  . '<p style="margin:0 0 4px;font-family:Georgia,serif;font-size:12px;font-weight:700;'                  . 'color:rgba(255,255,255,0.9);letter-spacing:5px;text-transform:uppercase;">BATANGAS EASTERN COLLEGES</p>'
                  // Gold divider line
                  . '<table cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px auto 8px;"><tr>'                  . '<td width="20" height="1" style="height:1px;background:rgba(201,162,39,0.3);font-size:0;">&nbsp;</td>'                  . '<td width="6" height="1" style="background:transparent;font-size:0;">&nbsp;</td>'                  . '<td width="8" height="8" style="width:8px;height:8px;background:#c9a227;transform:rotate(45deg);font-size:0;">&nbsp;</td>'                  . '<td width="6" height="1" style="background:transparent;font-size:0;">&nbsp;</td>'                  . '<td width="20" height="1" style="height:1px;background:rgba(201,162,39,0.3);font-size:0;">&nbsp;</td>'                  . '</tr></table>'
                  // Tagline
                  . '<p style="margin:0;font-family:Arial,sans-serif;font-size:9px;font-weight:700;'                  . 'color:rgba(201,162,39,0.6);letter-spacing:4px;text-transform:uppercase;">&#11835; EQUIPMENT MANAGEMENT SYSTEM &#11835;</p>'
              . '</td>'
              // Vertical divider right
              . '<td width="1" style="width:1px;background:rgba(201,162,39,0.15);font-size:0;">&nbsp;</td>'
              // Right ornament column
              . '<td width="60" valign="middle" align="center" style="padding:0;">'              . '<table cellpadding="0" cellspacing="0" border="0"><tr><td align="center">'              . '<p style="margin:0;font-size:28px;color:rgba(201,162,39,0.25);line-height:1;">&#10022;</p>'              . '<p style="margin:4px 0 0;font-size:10px;color:rgba(201,162,39,0.15);line-height:1;">&#9632;</p>'              . '<p style="margin:4px 0 0;font-size:10px;color:rgba(201,162,39,0.15);line-height:1;">&#9632;</p>'              . '</td></tr></table>'              . '</td>'
              // Right decorative strip
              . '<td width="20" style="width:20px;background-color:#1a0303;font-size:0;">&nbsp;</td>'
          . '</tr></table>'
          // Bottom accent bar
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'          . '<tr>'          . '<td style="width:15%;height:3px;background:#0d0404;font-size:0;">&nbsp;</td>'          . '<td style="width:70%;height:3px;background:rgba(201,162,39,0.5);font-size:0;">&nbsp;</td>'          . '<td style="width:15%;height:3px;background:#0d0404;font-size:0;">&nbsp;</td>'          . '</tr></table>'
      . '</td></tr>'
      // ── MAROON HEADER
      . '<tr><td style="background:#7b0000;padding:0;">'

          // Gold bar
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
          . '<tr>'
          . '<td style="width:20%;height:4px;background:#5c0000;font-size:0;">&nbsp;</td>'
          . '<td style="width:60%;height:4px;background:#c9a227;font-size:0;">&nbsp;</td>'
          . '<td style="width:20%;height:4px;background:#5c0000;font-size:0;">&nbsp;</td>'
          . '</tr></table>'

          . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
          . '<tr><td align="center" style="padding:28px 40px 32px;">'

              // Title
              . '<h1 style="margin:0 0 8px;font-family:Georgia,serif;font-size:24px;font-weight:800;color:#ffffff;letter-spacing:1px;">'
              . 'Secure Login Verification</h1>'
              . '<p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:rgba(255,255,255,0.65);letter-spacing:1px;">Equipment Management System</p>'

              // Role badge
              . '<table cellpadding="0" cellspacing="0" border="0" align="center"><tr>'
              . '<td style="background-color:rgba(255,255,255,0.13);border:1px solid rgba(255,255,255,0.22);border-radius:20px;padding:6px 20px;">'
              . '<span style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:600;color:rgba(255,255,255,0.92);">&#128273;&nbsp;&nbsp;' . htmlspecialchars($roleName) . '</span>'
              . '</td></tr></table>'

          . '</td></tr></table>'
      . '</td></tr>'

      // ── CONTENT
      . '<tr><td style="background-color:#f9f9f9;padding:44px 40px 40px;">'

          . '<p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;color:#1a1a1a;">Hello, ' . htmlspecialchars($roleName) . '!</p>'

          . '<p style="margin:0 0 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#555555;line-height:1.8;">'
          . 'You have requested access to the <strong style="color:#1a1a1a;">BEC Equipment Management System</strong>. '
          . 'Use the one-time verification code below to complete your secure login. '
          . 'This code is personal &mdash; do not share it with anyone.</p>'

          // ── OTP BOX
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff5f5;border:1px solid #f0d0d0;border-radius:16px;margin-bottom:24px;overflow:hidden;">'
          . '<tr><td style="height:3px;background:#8b0000;font-size:0;">&nbsp;</td></tr>'
          . '<tr><td align="center" style="padding:28px 20px 24px;">'
              . '<p style="margin:0 0 18px;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#aaaaaa;letter-spacing:3px;text-transform:uppercase;">YOUR VERIFICATION CODE</p>'
              . '<table cellpadding="0" cellspacing="0" border="0" align="center"><tr>'
              . '<td style="padding:0 4px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="44" height="56" style="' . $digitStyle . '">' . $d[0] . '</td>'
              . '</tr></table></td>'
              . '<td style="padding:0 4px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="44" height="56" style="' . $digitStyle . '">' . $d[1] . '</td>'
              . '</tr></table></td>'
              . '<td style="padding:0 4px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="44" height="56" style="' . $digitStyle . '">' . $d[2] . '</td>'
              . '</tr></table></td>'
              . '<td style="padding:0 6px;font-family:Arial,sans-serif;font-size:24px;color:#cccccc;vertical-align:middle;">&middot;</td>'
              . '<td style="padding:0 4px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="44" height="56" style="' . $digitStyle . '">' . $d[3] . '</td>'
              . '</tr></table></td>'
              . '<td style="padding:0 4px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="44" height="56" style="' . $digitStyle . '">' . $d[4] . '</td>'
              . '</tr></table></td>'
              . '<td style="padding:0 4px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="44" height="56" style="' . $digitStyle . '">' . $d[5] . '</td>'
              . '</tr></table></td>'
              . '</tr></table>'
              . '<p style="margin:18px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#888888;font-weight:500;">&#9203;&nbsp; Valid for <strong style="color:#8b0000;">10 minutes</strong> only</p>'
          . '</td></tr></table>'

          // ── SECURITY NOTICE
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fffcf0;border:1px solid #ffe0a0;border-radius:14px;margin-bottom:20px;overflow:hidden;">'
          . '<tr>'
          . '<td width="4" style="width:4px;background-color:#c9a227;font-size:0;">&nbsp;</td>'
          . '<td style="padding:22px 22px 22px 16px;">'
              . '<table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:14px;"><tr>'
              . '<td style="padding-right:12px;"><table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td align="center" width="36" height="36" style="width:36px;height:36px;background:#c9a227;border-radius:10px;font-size:18px;text-align:center;vertical-align:middle;">&#9888;</td>'
              . '</tr></table></td>'
              . '<td><p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#7a6010;">Security Notice</p></td>'
              . '</tr></table>'
              . '<table cellpadding="0" cellspacing="0" border="0" width="100%">'
              . '<tr><td style="padding:6px 0;border-bottom:1px solid rgba(201,162,39,0.18);">'
              . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td style="padding-right:8px;font-size:16px;color:#c9a227;font-weight:700;vertical-align:top;">&#8250;</td>'
              . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#7a6010;line-height:1.55;">This OTP is valid for <strong>10 minutes</strong> only &mdash; do not delay</td>'
              . '</tr></table></td></tr>'
              . '<tr><td style="padding:6px 0;border-bottom:1px solid rgba(201,162,39,0.18);">'
              . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td style="padding-right:8px;font-size:16px;color:#c9a227;font-weight:700;vertical-align:top;">&#8250;</td>'
              . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#7a6010;line-height:1.55;"><strong>Never share</strong> this code with anyone, including IT staff</td>'
              . '</tr></table></td></tr>'
              . '<tr><td style="padding:6px 0;border-bottom:1px solid rgba(201,162,39,0.18);">'
              . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td style="padding-right:8px;font-size:16px;color:#c9a227;font-weight:700;vertical-align:top;">&#8250;</td>'
              . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#7a6010;line-height:1.55;">If you didn&rsquo;t request this code, simply ignore this email</td>'
              . '</tr></table></td></tr>'
              . '<tr><td style="padding:6px 0;">'
              . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
              . '<td style="padding-right:8px;font-size:16px;color:#c9a227;font-weight:700;vertical-align:top;">&#8250;</td>'
              . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#7a6010;line-height:1.55;">Contact IT support immediately if you suspect unauthorized access</td>'
              . '</tr></table></td></tr>'
              . '</table>'
          . '</td></tr></table>'

          // ── HELP ROW
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f3f8;border-radius:12px;margin-bottom:32px;">'
          . '<tr>'
          . '<td width="62" valign="middle" style="padding:16px 0 16px 16px;">'
          . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
          . '<td align="center" width="42" height="42" style="width:42px;height:42px;background:#8b0000;border-radius:12px;font-size:20px;text-align:center;vertical-align:middle;">&#128172;</td>'
          . '</tr></table></td>'
          . '<td valign="middle" style="padding:16px 16px 16px 12px;">'
          . '<p style="margin:0 0 3px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#1a1a1a;">Need help logging in?</p>'
          . '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#555555;line-height:1.5;">Contact the <strong style="color:#1a1a1a;">IT Department</strong> for immediate assistance.</p></td>'
          . '</tr></table>'

          // ── SIGNATURE
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e8e8e8;">'
          . '<tr><td align="center" style="padding-top:24px;">'
          . '<p style="margin:0 0 5px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#bbbbbb;">Sent by</p>'
          . '<p style="margin:0;font-family:Georgia,serif;font-size:17px;font-weight:700;color:#8b0000;">BEC Equipment Management System</p>'
          . '</td></tr></table>'

      . '</td></tr>'

      // ── FOOTER
      . '<tr><td style="background-color:#1a1a2e;padding:24px 40px 28px;">'
          . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
          . '<tr><td align="center" style="padding-bottom:10px;">'
          . '<table cellpadding="0" cellspacing="0" border="0"><tr>'

          // Mini BEC badge — CSS circle, no SVG
          . '<td style="padding-right:10px;vertical-align:middle;">'          . '<table cellpadding="0" cellspacing="0" border="0"><tr>'          . '<td align="center" width="28" height="28" '          . 'style="width:28px;height:28px;border-radius:50%;background:#7b0000;'          . 'border:1px solid rgba(201,162,39,0.6);font-family:Georgia,serif;font-size:7px;'          . 'font-weight:900;color:#c9a227;text-align:center;vertical-align:middle;line-height:28px;">'          . 'BEC'          . '</td></tr></table>'          . '</td>'          . '<td style="vertical-align:middle;">'
          . '<p style="margin:0;font-family:Georgia,serif;font-size:13px;font-weight:700;color:rgba(255,255,255,0.85);">Batangas Eastern Colleges</p>'
          . '</td></tr></table></td></tr>'
          . '<tr><td align="center" style="padding:8px 0;">'
          . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
          . '<td style="width:48px;height:1px;background:rgba(255,255,255,0.12);font-size:0;">&nbsp;</td>'
          . '</tr></table></td></tr>'
          . '<tr><td align="center">'
          . '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:rgba(255,255,255,0.38);line-height:1.9;">'
          . 'Equipment Management System<br>'
          . '&copy; ' . $year . ' Batangas Eastern Colleges. All rights reserved.<br>'
          . 'This is an automated message &mdash; please do not reply.'
          . '</p></td></tr>'
          . '</table>'
      . '</td></tr>'

      . '</table>' // close card
      . '</td></tr></table>' // close wrapper
      . '</body></html>';
    $result = sendEmail($email, $subject, $message);
    error_log("OTP Email sent to {$email}: " . ($result ? 'SUCCESS' : 'FAILED'));
    return $result;
}

function storeOTP($email, $otp, $role = 'admin') {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE email = ? AND is_used = 0");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO email_otp (email, otp_code, user_role, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $stmt->bind_param("sss", $email, $otp, $role);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function verifyOTP($email, $otp_code, $role = 'admin') {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM email_otp WHERE email = ? AND otp_code = ? AND user_role = ? AND is_used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("sss", $email, $otp_code, $role);
    $stmt->execute();
    $otp_record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$otp_record) {
        $stmt = $conn->prepare("SELECT * FROM email_otp WHERE email = ? AND otp_code = ? AND user_role = ? AND is_used = 0 AND expires_at <= NOW() ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("sss", $email, $otp_code, $role);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); return ['success' => false, 'message' => 'OTP has expired. Please request a new one.', 'user' => null]; }
        $stmt->close();
        $stmt = $conn->prepare("SELECT * FROM email_otp WHERE email = ? AND otp_code = ? AND user_role = ? AND is_used = 1 ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("sss", $email, $otp_code, $role);
        $stmt->execute();
        $used = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ['success' => false, 'message' => $used ? 'This OTP has already been used.' : 'Invalid OTP code.', 'user' => null];
    }
    $stmt = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE otp_id = ?");
    $stmt->bind_param("i", $otp_record['otp_id']);
    $stmt->execute();
    $stmt->close();
    $allowed_roles = ['admin', 'student', 'faculty', 'guest'];
    if (!in_array($role, $allowed_roles)) return ['success' => false, 'message' => 'Invalid role.', 'user' => null];
    $stmt = $conn->prepare("SELECT * FROM `users` WHERE email = ? AND role = ? LIMIT 1");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) return ['success' => false, 'message' => 'User account not found.', 'user' => null];
    if (isset($user['status']) && $user['status'] !== 'active') return ['success' => false, 'message' => 'Your account is inactive. Please contact support.', 'user' => null];
    return ['success' => true, 'message' => 'OTP verified successfully.', 'user' => $user];
}

function requestLoginOTP($email, $role = 'admin') {
    $conn = getDBConnection();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Invalid email address format.'];
    $allowed_roles = ['admin', 'student', 'faculty', 'guest'];
    if (!in_array($role, $allowed_roles)) return ['success' => false, 'message' => 'Invalid role.'];
    $stmt = $conn->prepare("SELECT email, fullname FROM `users` WHERE email = ? AND role = ? LIMIT 1");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) return ['success' => true, 'message' => 'If this email is registered, an OTP has been sent.'];
    $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago FROM email_otp WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND) ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $recent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($recent) {
        $wait = max(0, 10 - max(0, $recent['seconds_ago']));
        if ($wait > 0) return ['success' => false, 'message' => "Please wait {$wait} seconds before requesting another OTP."];
    }
    $otp = generateOTP();
    if (!storeOTP($email, $otp, $role)) return ['success' => false, 'message' => 'Failed to generate OTP. Please try again.'];
    if (!sendOTPEmail($email, $otp, $role)) {
        error_log("WARNING: OTP email failed for {$email}");
        return ['success' => true, 'message' => 'Login verified. Please enter the OTP code sent to your email.'];
    }
    return ['success' => true, 'message' => 'OTP has been sent to your email. Please check your inbox and spam folder.'];
}

function cleanupExpiredOTPs() {
    $conn = getDBConnection();
    $result = $conn->query("DELETE FROM email_otp WHERE expires_at < NOW()");
    if ($result) { $deleted = $conn->affected_rows; error_log("Cleaned up {$deleted} expired OTP records"); return $deleted; }
    return 0;
}