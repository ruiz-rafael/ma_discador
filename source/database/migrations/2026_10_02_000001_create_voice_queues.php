<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_queues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('campaign_id')->unique()->constrained('voice_campaigns');
            $t->string('name', 160);
            $t->string('status', 20)->default('paused');
            $t->string('strategy', 20)->default('fifo');
            $t->unsignedInteger('wrapup_seconds')->default(30);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('voice_queue_agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('queue_id')->constrained('voice_queues')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->unique(['queue_id', 'user_id']);
        });
        Schema::create('voice_agent_presence', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('status', 20)->default('offline');
            $t->string('pause_reason', 160)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('available_after')->nullable();
            $t->timestamps();
        });
        Schema::create('voice_queue_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('queue_id')->constrained('voice_queues');
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->string('status', 20)->default('waiting');
            $t->unsignedInteger('priority')->default(0);
            $t->timestamp('available_at');
            $t->string('reason', 300)->nullable();
            $t->timestamps();
            $t->unique(['queue_id', 'contact_id']);
            $t->index(['queue_id', 'status', 'available_at']);
        });
        Schema::create('voice_queue_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('queue_id')->constrained('voice_queues');
            $t->foreignId('item_id')->constrained('voice_queue_items');
            $t->foreignId('user_id')->constrained();
            $t->uuid('attempt_id')->unique();
            $t->foreign('attempt_id')->references('id')->on('voice_attempts');
            $t->uuid('idempotency_key');
            $t->string('status', 20)->default('active');
            $t->string('completion_hash', 64)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
            $t->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['voice_queue_assignments', 'voice_queue_items', 'voice_agent_presence', 'voice_queue_agents', 'voice_queues'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
