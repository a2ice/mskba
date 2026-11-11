<?php

return [
    // Explicit global kill switch until Task 306 secure adapters and Task 308
    // bounded execution/observability pass their separate acceptance gates.
    // Neither an admin-enabled source nor a saved credential can initiate a
    // network request from this task's code.
    'live_source_execution_enabled' => false,
];
