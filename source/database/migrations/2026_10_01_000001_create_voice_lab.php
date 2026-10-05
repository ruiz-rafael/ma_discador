<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->timestamps();
        });
        DB::table('voice_workspaces')->insert(['id' => 1, 'name' => 'Zyrex · laboratório', 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('voice_workspaces', 'id'), 1)");
        }
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('voice_workspace_id')->nullable()->constrained('voice_workspaces');
        });
        DB::table('users')->update(['voice_workspace_id' => 1]);
        Schema::create('voice_runtime', function (Blueprint $t) { $t->id(); });
        DB::table('voice_runtime')->insert(['id' => 1]);
        Schema::create('voice_contacts', function (Blueprint $t) {
            $t->id(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('name', 160); $t->string('phone', 20); $t->string('original_phone', 80);
            $t->string('crm_contact_id', 160)->nullable(); $t->string('source', 300);
            $t->boolean('consent')->default(false); $t->text('consent_evidence')->nullable();
            $t->timestamp('consented_at')->nullable(); $t->timestamp('suppressed_at')->nullable();
            $t->timestamp('replied_at')->nullable(); $t->timestamp('next_allowed_at')->nullable();
            $t->timestamps(); $t->unique(['workspace_id', 'phone']);
        });
        Schema::create('voice_campaigns', function (Blueprint $t) {
            $t->id(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('name', 160); $t->string('status', 24)->default('draft');
            $t->json('settings'); $t->unsignedInteger('revision')->default(0); $t->timestamps();
            $t->index(['workspace_id', 'status']);
        });
        Schema::create('voice_members', function (Blueprint $t) {
            $t->id(); $t->foreignId('campaign_id')->constrained('voice_campaigns')->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->unique(['campaign_id', 'contact_id']);
        });
        Schema::create('voice_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('campaign_id')->constrained('voice_campaigns');
            $t->foreignId('contact_id')->constrained('voice_contacts'); $t->foreignId('user_id')->constrained();
            $t->string('idempotency_key', 100); $t->string('status', 24)->default('ringing');
            $t->string('outcome', 32)->nullable(); $t->string('qualification', 32)->nullable();
            $t->boolean('simulated')->default(true); $t->timestamp('expires_at');
            $t->timestamp('finished_at')->nullable(); $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']); $t->index(['workspace_id', 'contact_id', 'status']);
        });
        Schema::create('voice_actions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('campaign_id')->constrained('voice_campaigns');
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->uuid('attempt_id')->unique(); $t->foreign('attempt_id')->references('id')->on('voice_attempts');
            $t->string('kind', 32); $t->string('status', 24)->default('pending');
            $t->json('settings'); $t->timestamp('due_at'); $t->string('reason', 300)->nullable(); $t->timestamps();
            $t->index(['workspace_id', 'status']);
        });
        Schema::create('voice_audit', function (Blueprint $t) {
            $t->id(); $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('user_id')->nullable()->constrained(); $t->string('event', 80);
            $t->string('subject_id', 100); $t->json('data'); $t->timestamp('created_at');
            $t->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['voice_audit', 'voice_actions', 'voice_attempts', 'voice_members', 'voice_campaigns', 'voice_contacts', 'voice_runtime'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('voice_workspace_id'));
        Schema::dropIfExists('voice_workspaces');
    }
};
