<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_outbound_calls', function (Blueprint $t) {
            $t->string('method', 32)->default('sip_trunk');
            $t->string('provider_account', 34)->nullable();
            $t->string('provider_child_sid', 34)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('voice_outbound_calls', function (Blueprint $t) {
            $t->dropUnique(['provider_child_sid']);
            $t->dropColumn(['method', 'provider_account', 'provider_child_sid']);
        });
    }
};
