<?php

use GraphQL\Validator\Rules\DisableIntrospection;
use Nuwave\Lighthouse\Http\Middleware\AcceptJson;
use Nuwave\Lighthouse\Http\Middleware\AttemptAuthentication;

return [
    // Shared GraphQL endpoint for native applications, browsers and integrations.
    // The 'api' group keeps requests stateless; CORS already covers api/*.
    'route' => [
        'uri' => 'api/graphql',
        'name' => 'graphql',
        'middleware' => [
            'api',
            'throttle:60,1',
            AcceptJson::class,
            AttemptAuthentication::class,
        ],
    ],
    'guards' => ['sanctum'],
    'schema_path' => base_path('graphql/schema.graphql'),
    // Explicitly disable schema cache until the deployment pipeline runs
    // lighthouse:clear-cache or rebuilds the cache on every schema change.
    'schema_cache' => [
        'enable' => false,
        'path' => base_path('bootstrap/cache/lighthouse-schema.php'),
    ],
    'security' => [
        'max_query_complexity' => 150,
        'max_query_depth' => 7,
        'disable_introspection' => (bool) env(
            'LIGHTHOUSE_SECURITY_DISABLE_INTROSPECTION',
            (string) env('APP_ENV', 'production') === 'production',
        ) ? DisableIntrospection::ENABLED : DisableIntrospection::DISABLED,
    ],
    'pagination' => [
        'default_count' => 30,
        'max_count' => 100,
    ],
];
