<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auto Trigger Ambassador Level Changes
    |--------------------------------------------------------------------------
    |
    | When enabled, ambassador level changes (upgrade/downgrade) will be
    | automatically triggered during statistics recalculation.
    | When disabled, level changes must be manually triggered via commands
    | or API endpoints.
    |
    | Set to false to disable automatic level changes and require manual
    | recalculation via:
    | - php artisan referral:recalc-stats {user_id}
    | - POST /api/v1/admin/ref/level-recalc
    |
    */

    'auto_trigger_level_changes' => env('REFERRAL_AUTO_TRIGGER_LEVEL_CHANGES', false),

];

