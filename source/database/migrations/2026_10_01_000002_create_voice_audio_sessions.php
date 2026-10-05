<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_audio_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('user_id')->constrained();
            $t->string('idempotency_key', 100);
            $t->string('grant_hash', 64)->unique();
            $t->string('status', 24)->default('pending');
            $t->string('channel_id', 120)->nullable()->unique();
            $t->timestamp('grant_expires_at');
            $t->timestamp('expires_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('duration')->default(0);
            $t->string('cause', 30)->nullable();
            $t->json('client_metrics')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'user_id', 'idempotency_key'], 'voice_audio_idempotency');
            $t->index(['status', 'expires_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('voice_audio_sessions'); }
};
