<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_agent_presence', fn (Blueprint $t) => $t->timestamp('state_changed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('voice_agent_presence', fn (Blueprint $t) => $t->dropColumn('state_changed_at'));
    }
};
