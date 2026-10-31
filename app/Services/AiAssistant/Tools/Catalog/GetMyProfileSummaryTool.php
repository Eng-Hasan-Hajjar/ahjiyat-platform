<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_my_profile_summary: الاسم المعروض وتاريخ الانضمام وحالة التوثيق ومستوى ظهور الملف. لا بريد ولا عنوان IP ولا كلمة مرور ولا بيانات دفع ولا أي حقل خاص. */
final class GetMyProfileSummaryTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_profile_summary';
    }

    public function description(): string
    {
        return "Get the signed-in member's own safe profile summary: display name, member since, email verified (yes/no), profile visibility. No email, no IP, no payment data.";
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'account';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $user = $context->user;

        return $this->ok(
            [
                'display_name' => Presenter::text($user->name, 80),
                'member_since' => Presenter::date($user->created_at, false),
                'email_verified' => $user->email_verified_at !== null,
                'profile_visibility' => (string) ($user->profile_visibility ?? 'public'),
                'friend_requests_enabled' => (bool) $user->friend_requests_enabled,
            ],
            array_values(array_filter([Presenter::link('ملفي الشخصي', Presenter::path('profile.edit'))])),
            ['display_name'],
        );
    }
}
