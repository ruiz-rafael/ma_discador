<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('users',function(Blueprint $t){$t->boolean('voice_enabled')->default(true);$t->unsignedInteger('voice_revision')->default(1);$t->unsignedInteger('voice_auth_version')->default(0);});}
 public function down(): void {Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['voice_enabled','voice_revision','voice_auth_version']));}
};
