<?php

namespace App\Services;

use App\Models\AuthorizationAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * نقطة الكتابة الوحيدة لسجل تدقيق الصلاحيات - لا Controller/Resource يكتب
 * AuthorizationAuditLog مباشرة. تُستدعى فقط عند تغييرات إدارية حقيقية على
 * RBAC (لا عند كل فحص can() - هذا سيولّد ملايين السجلات بلا فائدة).
 */
class AuthorizationAuditService
{
    public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
    {
        AuthorizationAuditLog::create([
            'actor_user_id' => ($actor ?? Auth::user())?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'user_agent' => (string) Request::userAgent(),
        ]);
    }
}