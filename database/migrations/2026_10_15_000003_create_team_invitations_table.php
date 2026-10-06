<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E19-B: دعوة فريق. pending_key ("team:user" ما دامت pending وNULL بعدها) UNIQUE: دعوة معلّقة واحدة لكل (فريق، مستخدم) بقيد حقيقي. status: pending|accepted|declined|cancelled|expired. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('pending');
            $table->string('pending_key', 41)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['invited_user_id', 'status']);
            $table->index(['team_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
