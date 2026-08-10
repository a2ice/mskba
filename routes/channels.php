<?php

use Illuminate\Support\Facades\Broadcast;

// Reserved reusable private channel for notifications and account-scoped updates.
Broadcast::channel('users.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
