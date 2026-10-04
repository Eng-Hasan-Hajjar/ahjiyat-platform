<?php

namespace App\Services\Notifications;

enum DispatchResult: string
{
    case Created = 'created';
    case Duplicate = 'duplicate';
    case Suppressed = 'suppressed';
    case Failed = 'failed';
}
