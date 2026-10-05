<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('voice_role', 16)->default('agent'));
        DB::table('users')->where('email', 'admin@zyrex.ia.br')->update(['voice_role' => 'admin']);
        Schema::create('voice_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('name', 160);
            $t->string('source', 300);
            $t->timestamps();
        });
        Schema::create('voice_list_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('list_id')->constrained('voice_lists');
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->string('status', 24)->default('active');
            $t->string('reason', 300)->nullable();
            $t->timestamps();
            $t->unique(['list_id', 'contact_id']);
        });
        Schema::create('voice_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('list_id')->constrained('voice_lists');
            $t->foreignId('user_id')->constrained('users');
            $t->string('file_hash', 64);
            $t->string('filename', 255);
            $t->string('status', 24)->default('preview');
            $t->json('rows');
            $t->json('errors');
            $t->json('summary');
            $t->timestamps();
            $t->unique(['list_id', 'file_hash']);
        });
        Schema::create('voice_campaign_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->unique()->constrained('voice_campaigns');
            $t->foreignId('list_id')->nullable()->constrained('voice_lists');
            $t->unsignedInteger('revision')->default(0);
            $t->unsignedInteger('daily_per_contact')->default(3);
            $t->unsignedInteger('global_daily')->default(5);
            $t->unsignedInteger('global_total')->default(20);
            $t->unsignedInteger('technical_limit')->default(3);
            $t->timestamp('expires_at')->nullable();
            $t->json('retry_minutes');
            $t->string('origin_mode', 20)->default('configured');
            $t->timestamps();
        });
        Schema::create('voice_disposition_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('code', 40);
            $t->string('label', 100);
            $t->boolean('human')->default(false);
            $t->boolean('notes_required')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['workspace_id', 'code']);
        });
        foreach (DB::table('voice_workspaces')->pluck('id') as $w) {
            foreach (['interested' => ['Interessado', true], 'not_interested' => ['Sem interesse', true], 'callback' => ['Retorno solicitado', true], 'converted' => ['Conversão', true], 'wrong_person' => ['Pessoa errada', true], 'invalid' => ['Telefone inválido confirmado', false], 'machine' => ['Caixa postal', false], 'opt_out' => ['Pediu interrupção', true]] as $code => $d) {
                DB::table('voice_disposition_codes')->insert(['workspace_id' => $w, 'code' => $code, 'label' => $d[0], 'human' => $d[1], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Schema::create('voice_dispositions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->uuid('call_id');
            $t->foreign('call_id')->references('id')->on('voice_outbound_calls');
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedInteger('revision');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('code', 40);
            $t->string('label', 100);
            $t->boolean('human');
            $t->text('notes')->nullable();
            $t->string('correction_reason', 500)->nullable();
            $t->timestamp('callback_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['call_id', 'revision']);
            $t->unique(['workspace_id', 'idempotency_key']);
        });
        Schema::create('voice_call_costs', function (Blueprint $t) {
            $t->id();
            $t->uuid('call_id');
            $t->foreign('call_id')->references('id')->on('voice_outbound_calls');
            $t->string('provider_sid', 34)->unique();
            $t->string('leg', 16);
            $t->decimal('amount', 16, 6)->nullable();
            $t->string('currency', 3)->nullable();
            $t->timestamp('synced_at');
        });
        Schema::create('voice_origins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('number', 20);
            $t->string('ddd', 2)->nullable();
            $t->string('account_sid', 34);
            $t->string('provider_sid', 34);
            $t->string('kind', 24);
            $t->boolean('enabled')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'number', 'account_sid']);
        });
        Schema::create('voice_live_queues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('campaign_id')->unique()->constrained('voice_campaigns');
            $t->string('name', 160);
            $t->string('status', 16)->default('paused');
            $t->string('mode', 16)->default('preview');
            $t->string('strategy', 16)->default('fifo');
            $t->unsignedInteger('wrapup_seconds')->default(30);
            $t->unsignedInteger('revision')->default(0);
            $t->json('agent_ids');
            $t->timestamps();
        });
        Schema::create('voice_live_reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('queue_id')->constrained('voice_live_queues');
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('call_id')->nullable()->unique();
            $t->foreign('call_id')->references('id')->on('voice_outbound_calls');
            $t->uuid('idempotency_key');
            $t->string('status', 24)->default('reserved');
            $t->timestamp('expires_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
        });
        Schema::table('voice_outbound_calls', function (Blueprint $t) {
            $t->string('environment', 20)->default('homologation');
            $t->json('context_snapshot')->nullable();
            $t->foreignId('list_id')->nullable()->constrained('voice_lists');
            $t->foreignId('queue_id')->nullable()->constrained('voice_live_queues');
            $t->unsignedInteger('disposition_revision')->default(0);
            $t->string('disposition_code', 40)->nullable();
            $t->string('disposition_label', 100)->nullable();
            $t->boolean('human_confirmed')->nullable();
            $t->text('disposition_notes')->nullable();
            $t->timestamp('callback_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voice_outbound_calls', function (Blueprint $t) {
            $t->dropConstrainedForeignId('list_id');
            $t->dropConstrainedForeignId('queue_id');
            $t->dropColumn(['environment', 'context_snapshot', 'disposition_revision', 'disposition_code', 'disposition_label', 'human_confirmed', 'disposition_notes', 'callback_at']);
        });
        foreach (['voice_live_reservations', 'voice_live_queues', 'voice_origins', 'voice_call_costs', 'voice_dispositions', 'voice_disposition_codes', 'voice_campaign_policies', 'voice_imports', 'voice_list_members', 'voice_lists'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users',fn (Blueprint $t) => $t->dropColumn('voice_role'));
    }
};
