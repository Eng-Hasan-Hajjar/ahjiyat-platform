<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public Project $project,
        public string  $newStatus
    ) {}

    public function via($notifiable): array { return ['database']; }

    public function toArray($notifiable): array
    {
        $messages = [
            'approved' => 'تمت الموافقة على مشروعك "' . $this->project->title . '"! يمكنك الآن البدء في استقبال المتطوعين.',
            'rejected' => 'تم رفض مشروعك "' . $this->project->title . '". السبب: ' . $this->project->rejection_reason,
            'in_progress' => 'بدأ تنفيذ مشروعك "' . $this->project->title . '".',
            'completed' => 'تم إكمال مشروعك "' . $this->project->title . '" بنجاح! 🎉',
        ];

        return [
            'type'       => 'project_status',
            'project_id' => $this->project->id,
            'title'      => 'تحديث حالة المشروع',
            'message'    => $messages[$this->newStatus] ?? 'تم تحديث حالة مشروعك.',
            'icon'       => 'project',
        ];
    }
}