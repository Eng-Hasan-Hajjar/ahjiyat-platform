<?php

namespace App\Services;

use App\Models\OperationalAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class OperationalAuditService
{
    public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
    {
        OperationalAuditLog::create([
            'actor_user_id' => ($actor ?? Auth::user())?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
        ]);
    }
}