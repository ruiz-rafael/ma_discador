<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $t) {
            $t->uuid('reply_to_message_id')->nullable()->index();
            $t->string('reply_attribution', 24)->nullable();
            $t->string('button_id', 64)->nullable();
            $t->string('button_label', 100)->nullable();
            $t->index(['workspace_id','campaign_id','direction','created_at'], 'wa_journey_report_idx');
            $t->index(['workspace_id','sender_id','to_number','created_at'], 'wa_reply_lookup_idx');
        });
        Schema::table('voice_outbound_calls', fn(Blueprint $t) => $t->index(['workspace_id','campaign_id','created_at'], 'voice_journey_report_idx'));
    }
    public function down(): void
    {
        Schema::table('wa_messages', function (Blueprint $t) {
            $t->dropIndex('wa_journey_report_idx');$t->dropIndex('wa_reply_lookup_idx');
            $t->dropIndex(['reply_to_message_id']);
            $t->dropColumn(['reply_to_message_id','reply_attribution','button_id','button_label']);
        });
        Schema::table('voice_outbound_calls', fn(Blueprint $t) => $t->dropIndex('voice_journey_report_idx'));
    }
};
