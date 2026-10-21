<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_number_verifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('number', 20);
            $t->unsignedBigInteger('user_id');
            $t->string('account_sid', 34);
            $t->string('waba_id', 40);
            $t->string('state', 32);
            $t->string('operation', 20);
            $t->unsignedInteger('provider_error')->nullable();
            $t->timestamp('retry_after')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'number']);
        });
    }
    public function down(): void { Schema::dropIfExists('wa_number_verifications'); }
};
