<?php

return [
    // Provisioning secret is per-environment, never a hard-coded default.
    'superadmin_password' => env('SUPERADMIN_SEED_PASSWORD'),
];
