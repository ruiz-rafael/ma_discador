<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_senders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('label', 160);
            $t->string('number', 20);
            $t->string('ownership', 16);
            $t->string('provider_sid', 34)->nullable()->unique();
            $t->string('account_sid', 34)->nullable();
            $t->string('status', 40)->default('unverified');
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'number']);
        });
        Schema::create('wa_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('name', 100);
            $t->string('language', 12);
            $t->string('category', 16);
            $t->text('body');
            $t->json('variables');
            $t->string('content_sid', 34)->nullable()->unique();
            $t->string('account_sid', 34)->nullable();
            $t->string('state', 32)->default('draft');
            $t->string('approval_status', 32)->default('not_submitted');
            $t->string('rejection_reason', 500)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'name', 'language']);
        });
        Schema::create('wa_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('sender_id')->constrained('wa_senders');
            $t->foreignId('contact_id')->nullable()->constrained('voice_contacts');
            $t->foreignId('campaign_id')->nullable()->constrained('voice_campaigns');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->uuid('template_id')->nullable();
            $t->foreign('template_id')->references('id')->on('wa_templates');
            $t->string('direction', 12);
            $t->string('idempotency_key', 100);
            $t->string('request_hash', 64);
            $t->string('account_sid', 34);
            $t->string('provider_sid', 34)->nullable()->unique();
            $t->string('from_number', 20);
            $t->string('to_number', 20);
            $t->string('status', 32);
            $t->string('error_code', 20)->nullable();
            $t->json('variables')->nullable();
            $t->text('body')->nullable();
            $t->string('consent_evidence', 1000)->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
        });
        Schema::create('wa_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_hash', 64)->unique();
            $t->uuid('message_id');
            $t->foreign('message_id')->references('id')->on('wa_messages');
            $t->string('status', 32);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['wa_events', 'wa_messages', 'wa_templates', 'wa_senders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
