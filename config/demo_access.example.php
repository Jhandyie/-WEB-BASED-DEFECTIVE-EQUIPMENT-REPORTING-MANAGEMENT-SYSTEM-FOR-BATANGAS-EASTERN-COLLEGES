<?php
/**
 * Accounts that may sign in to the admin portal WITHOUT the emailed one-time code.
 *
 * This exists so a tester can reach the admin portal without waiting on (or having
 * access to) the account's mailbox. It is a deliberate hole in two-factor auth:
 * anyone who learns the password of a listed account is straight in. Keep the list
 * as short as the testing actually needs, and empty it when the testing is done.
 *
 * Safe by default in three ways:
 *   - config/demo_access.php is gitignored, so a bypass can never reach the repo;
 *   - a missing file means an empty list, so a fresh checkout has no bypass at all;
 *   - the password is still verified in full, and so are the rate limits. Only the
 *     second factor is skipped.
 *
 * Every bypassed sign-in is written to activity_log as 'auth.login' with the OTP
 * bypass named in the description, so the audit trail still shows what happened.
 *
 * To use: copy this file to config/demo_access.php and list the addresses.
 */

return [
    'otp_bypass_emails' => [
        // 'someone@bec.edu.ph',
    ],
];
