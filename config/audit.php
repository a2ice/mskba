<?php

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Models\ContractPermission;
use App\Modules\Contract\Domain\Models\ContractRelation;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use App\Modules\Venue\Domain\Models\VenueReview;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;

return [
    'enabled' => env('AUDIT_LOG_ENABLED', true),
    'ignore_console' => env('AUDIT_LOG_IGNORE_CONSOLE', true),

    'ignored_attributes' => [
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'password',
        'code_hash',
    ],

    'auditable' => [
        User::class,
        UserDuplicate::class,
        UserOperationalPermission::class,
        Profile::class,
        PlayerProfile::class,
        UserParticipationRole::class,
        Contact::class,
        ContactVerification::class,
        Venue::class,
        VenueDuplicate::class,
        VenueSchedule::class,
        VenueScheduleInterval::class,
        VenueReview::class,
        Contract::class,
        ContractMembership::class,
        ContractPermission::class,
        ContractRelation::class,
        Address::class,
        Location::class,
        Media::class,
        UserNotification::class,
    ],
];
