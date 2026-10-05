<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_outbound_calls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->foreignId('campaign_id')->nullable()->constrained('voice_campaigns');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('grant_hash', 64)->unique();
            $t->string('configuration_hash', 64);
            $t->string('destination', 20);
            $t->string('caller_id', 20);
            $t->string('status', 24)->default('pending');
            $t->string('channel_id', 120)->nullable();
            $t->string('dial_status', 30)->nullable();
            $t->string('cause', 30)->nullable();
            $t->unsignedInteger('bill_seconds')->default(0);
            $t->unsignedInteger('max_seconds');
            $t->unsignedInteger('ring_seconds');
            $t->text('consent_evidence');
            $t->timestamp('grant_expires_at');
            $t->timestamp('deadline_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('capacity_released_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
            $t->index(['status', 'capacity_released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_outbound_calls');
    }
};
