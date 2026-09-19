<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}.doctor.{doctorId}', function ($user, $tenantId, $doctorId) {
    return (string) $user->tenant_id === (string) $tenantId
        && (string) $user->id === (string) $doctorId;
});
