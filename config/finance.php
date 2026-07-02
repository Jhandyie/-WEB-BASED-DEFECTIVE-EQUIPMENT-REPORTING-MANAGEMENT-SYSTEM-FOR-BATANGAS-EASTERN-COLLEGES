<?php
/**
 * config/finance.php — Finance / Accounting notification settings.
 *
 * Set BEC_FINANCE_EMAIL to the real Finance / Accounting Office inbox.
 * All "Notify Finance" emails for budget requests are sent to this address.
 */
if (!defined('BEC_FINANCE_EMAIL')) {
    define('BEC_FINANCE_EMAIL', 'cyril.casala@bec.edu.ph'); // temporary Finance/Accounting recipient
}
if (!defined('BEC_FINANCE_LABEL')) {
    define('BEC_FINANCE_LABEL', 'BEC Finance / Accounting Office');
}
