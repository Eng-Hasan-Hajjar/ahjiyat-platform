<?php

use App\Broadcasting\ChatThreadChannel;
use Illuminate\Support\Facades\Broadcast;

/*
 * E21: قنوات خاصة فقط (لا قناة عامة لأي دردشة). التخويل بنفس سياسات المجال (ChatAccess).
 */
Broadcast::channel('chat.{thread}', ChatThreadChannel::class);
