<?php

return [
    // Explicitly opt in on dev/local only. Never enable on production.
    'enabled' => (bool) env('CONTACT_PROVIDER_EMULATION_ENABLED', false),
];
