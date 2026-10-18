<?php

// Public API endpoints may be consumed by browsers hosted on any origin.
// Do not enable credentialed cross-origin cookies with wildcard origins.
// Future first-party browser sessions should use a separate, explicit
// origin/credential policy rather than broadening this public API policy.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
