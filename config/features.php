<?php
/**
 * config/features.php — switches for parts of the system that are built and
 * working but deliberately kept out of sight.
 *
 * VENUE RESERVATION is a feature added beyond the capstone study: it is not
 * claimed under any objective and was not evaluated by the respondents, so the
 * manuscript names it in the Delimitation. It is switched ON here by the
 * author's decision — it is built, it works, and it is part of what the office
 * actually uses.
 *
 * Setting this to false hides it from every entry point at once: the public
 * nav, the landing page, the admin sidebar, and the two pages themselves,
 * which redirect rather than render. Nothing about the module is deleted.
 */
if (!function_exists('becVenueEnabled')) {
    function becVenueEnabled(): bool {
        return true;    // <-- set to false to hide the venue reservation module
    }
}
