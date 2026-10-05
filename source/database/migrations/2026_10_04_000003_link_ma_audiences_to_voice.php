<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('voice_campaign_policies', fn (Blueprint $t) => $t->foreignId('audience_id')->nullable()->constrained('audiences'));
        Schema::create('voice_audience_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('audience_id')->constrained('audiences')->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $t->foreignId('voice_contact_id')->constrained('voice_contacts');
            $t->unique(['audience_id', 'contact_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('voice_audience_contacts');
        Schema::table('voice_campaign_policies', fn (Blueprint $t) => $t->dropConstrainedForeignId('audience_id'));
    }
};
