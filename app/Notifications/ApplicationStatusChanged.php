<?php

namespace App\Notifications;

use App\Models\VolunteerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ApplicationStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public VolunteerApplication $application,
        public string               $newStatus
    ) {}

    public function via($notifiable): array { return ['database']; }

    public function toArray($notifiable): array
    {
        $project = $this->application->project;

        $messages = [
            'accepted' => 'تم قبول طلبك للتطوع في مشروع "' . $project->title . '"! أهلاً بك في الفريق 🎉',
            'rejected' => 'نأسف، تم رفض طلبك للتطوع في مشروع "' . $project->title . '".',
        ];

        return [
            'type'           => 'application_status',
            'project_id'     => $project->id,
            'application_id' => $this->application->id,
            'title'          => 'تحديث طلب التطوع',
            'message'        => $messages[$this->newStatus] ?? 'تم تحديث طلبك.',
            'icon'           => 'application',
        ];
    }
}
