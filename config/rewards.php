<?php

use App\Modules\Rewards\Application\Mechanisms\DirectReferralConfirmedMechanism;
use App\Modules\Rewards\Application\Mechanisms\SecondLevelReferralConfirmedMechanism;
use App\Modules\Rewards\Application\Mechanisms\VenueFirstApprovalMechanism;

return [
    /*
    |--------------------------------------------------------------------------
    | Reward mechanisms
    |--------------------------------------------------------------------------
    |
    | Only mechanisms registered here are considered technically implemented.
    | Catalog entries may reference future codes, but such rewards cannot be
    | enabled until a matching RewardMechanism is registered.
    |
    */
    'mechanisms' => [
        DirectReferralConfirmedMechanism::class,
        SecondLevelReferralConfirmedMechanism::class,
        VenueFirstApprovalMechanism::class,
    ],
];
