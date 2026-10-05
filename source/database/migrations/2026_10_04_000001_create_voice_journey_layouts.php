<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_journey_layouts', function (Blueprint $table) {
            $table->foreignId('campaign_id')->primary()->constrained('voice_campaigns')->cascadeOnDelete();
            $table->json('positions');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_journey_layouts');
    }
};
