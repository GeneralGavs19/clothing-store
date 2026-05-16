<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public function log(string $action, ?object $entity = null, array $meta = [], ?Request $request = null): void
    {
        $userId = Auth::id();
        if ($userId === null && $entity && property_exists($entity, 'id') && $entity instanceof \App\Models\User) {
            $userId = $entity->id;
        }

        ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity->id ?? null,
            'meta' => $meta ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
