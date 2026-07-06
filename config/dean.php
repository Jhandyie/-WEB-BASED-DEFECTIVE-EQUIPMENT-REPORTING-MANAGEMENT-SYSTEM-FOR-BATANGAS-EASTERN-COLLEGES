<?php
/**
 * config/dean.php — Dean's office approval settings.
 *
 * Set BEC_DEAN_EMAIL to the Dean's real mailbox. Reports endorsed for Dean
 * approval email this address a secure one-click Approve / Reject link.
 */
if (!defined('BEC_DEAN_EMAIL')) {
    define('BEC_DEAN_EMAIL', 'dean@bec.edu.ph'); // TODO: replace with the Dean's real email
}
if (!defined('BEC_DEAN_LABEL')) {
    define('BEC_DEAN_LABEL', "Dean's Office");
}
