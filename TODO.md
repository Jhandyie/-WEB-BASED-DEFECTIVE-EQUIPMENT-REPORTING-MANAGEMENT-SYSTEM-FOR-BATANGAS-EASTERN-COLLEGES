# TODO: Add 2 Email for OTP Verification

## Task Summary
Configure 3 sender emails for OTP verification based on user role:
- Admin → thesterads@gmail.com
- Student → jhanmark_decastro@bec.edu.ph
- Technician → technician9123@gmail.com

## Implementation Steps

- [x] 1. Update `data/system_settings.json` - Add role-based email configurations
- [x] 2. Update `includes/mail_helper.php` - Add role parameter to select correct email
- [x] 3. Update `includes/otp_helper.php` - Pass role to email sending function

## Implementation Complete ✅

All role-based email configurations have been implemented:

1. **data/system_settings.json** - Contains role-specific SMTP credentials for admin, student, and technician
2. **includes/mail_helper.php** - `getEmailSettingsByRole()` function retrieves role-based settings, `sendEmail()` accepts `$role` parameter
3. **includes/otp_helper.php** - `sendOTPEmail()` and `requestLoginOTP()` accept `$role` parameter and pass it through
4. **Login processes** - All three login files (admin, student, technician) correctly pass the role when requesting OTP
