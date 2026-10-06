<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_onboarding_settings', function (Blueprint $t) {
            $t->unsignedBigInteger('workspace_id')->primary();
            $t->json('settings');
            $t->timestamps();
        });
        Schema::create('wa_onboardings', function (Blueprint $t) {
            $t->unsignedBigInteger('sender_id')->primary();
            $t->foreign('sender_id')->references('id')->on('wa_senders');
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id');
            $t->string('account_sid', 34);
            $t->string('mode', 20);
            $t->string('state', 32);
            $t->uuid('session_id')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('waba_id', 40)->nullable();
            $t->timestamp('last_request_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('wa_onboardings');
        Schema::dropIfExists('wa_onboarding_settings');
    }
};
