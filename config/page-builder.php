<?php

return [
    /*
     * Reviewed public, read-only Laravel entry points that CMS may replace.
     * No entity detail, create/edit, account, API or privileged routes here.
     * Registering a route here alone does not change the active page:
     * it must also opt in to the PageBuilderRouteOverride middleware.
     */
    'overrides' => [
        'events.index' => ['path' => '/events', 'source' => 'routes/web.php'],
    ],
];
