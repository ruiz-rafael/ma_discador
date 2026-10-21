<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_public_profiles', function (Blueprint $t) {
            $t->foreignId('sender_id')->primary()->constrained('wa_senders')->cascadeOnDelete();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->text('draft')->nullable();
            $t->text('confirmed')->nullable();
            $t->string('submission_state', 32)->default('draft');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('wa_public_profiles'); }
};
