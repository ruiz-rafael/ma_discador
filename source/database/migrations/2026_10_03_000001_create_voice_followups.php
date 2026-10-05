<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_senders', function (Blueprint $t) { $t->string('provider', 16)->default('twilio'); });
        Schema::table('wa_messages', function (Blueprint $t) {
            $t->string('provider', 16)->default('twilio');
            $t->string('provider_reference', 120)->nullable()->index();
        });
        Schema::table('voice_campaigns', fn (Blueprint $t) => $t->unsignedInteger('followup_revision')->default(0));
        Schema::table('voice_outbound_calls', function (Blueprint $t) { $t->unsignedInteger('campaign_revision')->nullable(); });
        Schema::create('voice_followups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->foreignId('campaign_id')->constrained('voice_campaigns');
            $t->foreignId('contact_id')->constrained('voice_contacts');
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('call_id');
            $t->foreign('call_id')->references('id')->on('voice_outbound_calls');
            $t->unsignedInteger('campaign_revision');
            $t->json('settings');
            $t->string('destination', 20);
            $t->string('status', 24)->default('pending');
            $t->string('reason', 500)->nullable();
            $t->uuid('message_id')->nullable();
            $t->foreign('message_id')->references('id')->on('wa_messages');
            $t->timestamp('due_at');
            $t->timestamps();
            $t->unique(['campaign_id', 'contact_id']);
            $t->index(['status', 'due_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('voice_followups');
        Schema::table('voice_campaigns', fn (Blueprint $t) => $t->dropColumn('followup_revision'));
        Schema::table('voice_outbound_calls', fn (Blueprint $t) => $t->dropColumn('campaign_revision'));
        Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn(['provider', 'provider_reference']));
        Schema::table('wa_senders', fn (Blueprint $t) => $t->dropColumn('provider'));
    }
};
