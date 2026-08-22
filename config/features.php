<?php
/**
 * config/features.php — switches for parts of the system that are built and
 * working but deliberately kept out of sight.
 *
 * VENUE RESERVATION is excluded from the capstone study: it is not claimed
 * under any objective, was not evaluated by the respondents, and is named as
 * excluded in the Delimitation of the manuscript. It is therefore hidden from
 * every entry point so that it cannot be reached during the defense.
 *
 * The module itself is untouched. To bring it back, set this to true.
 */
if (!function_exists('becVenueEnabled')) {
    function becVenueEnabled(): bool {
        return false;   // <-- set to true to restore the venue reservation module
    }
}
