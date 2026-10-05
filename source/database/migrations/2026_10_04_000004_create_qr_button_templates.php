<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_qr_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained('voice_workspaces');
            $t->string('name', 160);
            $t->text('body');
            $t->json('buttons');
            $t->timestamps();
        });
        Schema::table('wa_messages', function (Blueprint $t) {
            $t->uuid('qr_template_id')->nullable();
            $t->foreign('qr_template_id')->references('id')->on('wa_qr_templates');
            $t->json('interactive')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_messages', function (Blueprint $t) {
            $t->dropForeign(['qr_template_id']);
            $t->dropColumn(['qr_template_id', 'interactive']);
        });
        Schema::dropIfExists('wa_qr_templates');
    }
};
