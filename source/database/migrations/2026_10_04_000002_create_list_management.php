<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_contacts', fn (Blueprint $t) => $t->json('fields')->nullable());
        Schema::create('ma_list_membership_exclusions', function (Blueprint $t) {
            $t->foreignId('audience_id')->constrained('audiences')->cascadeOnDelete(); $t->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete(); $t->timestamp('created_at'); $t->primary(['audience_id', 'contact_id']);
        });
        Schema::create('ma_list_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('kind', 16); $t->unsignedBigInteger('list_id');
            $t->unsignedInteger('revision')->default(0); $t->json('fields'); $t->timestamps();
            $t->unique(['workspace_id', 'kind', 'list_id']);
        });
        Schema::create('ma_list_imports', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('kind', 16); $t->unsignedBigInteger('list_id'); $t->unsignedInteger('list_revision');
            $t->foreignId('user_id')->constrained('users'); $t->string('filename', 200);
            $t->string('status', 20)->default('ready'); $t->json('rows'); $t->json('errors'); $t->json('summary');
            $t->timestamp('expires_at'); $t->timestamps();
        });
        Schema::create('ma_list_webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('kind', 16); $t->unsignedBigInteger('list_id'); $t->string('name', 160);
            $t->boolean('active')->default(true); $t->unsignedInteger('revision')->default(0);
            $t->char('token_hash', 64); $t->json('mapping'); $t->timestamps();
        });
        Schema::create('ma_list_webhook_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('webhook_id')->constrained('ma_list_webhooks')->cascadeOnDelete();
            $t->string('event_key', 160); $t->char('request_hash', 64); $t->json('result'); $t->timestamp('created_at');
            $t->unique(['webhook_id', 'event_key']);
        });
    }
    public function down(): void
    {
        foreach (['ma_list_webhook_receipts', 'ma_list_webhooks', 'ma_list_imports', 'ma_list_settings', 'ma_list_membership_exclusions'] as $table) Schema::dropIfExists($table);
        Schema::table('voice_contacts', fn (Blueprint $t) => $t->dropColumn('fields'));
    }
};
