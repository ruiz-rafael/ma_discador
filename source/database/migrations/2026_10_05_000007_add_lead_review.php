<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('social_leads',function(Blueprint $t){$t->foreignId('assigned_user_id')->nullable()->constrained('users');$t->foreignId('reviewed_by')->nullable()->constrained('users');$t->timestamp('reviewed_at')->nullable();$t->text('review_notes')->nullable();$t->unsignedInteger('revision')->default(1);$t->index(['workspace_id','status','created_at']);});}
 public function down():void {Schema::table('social_leads',function(Blueprint $t){$t->dropIndex(['workspace_id','status','created_at']);$t->dropConstrainedForeignId('assigned_user_id');$t->dropConstrainedForeignId('reviewed_by');$t->dropColumn(['reviewed_at','review_notes','revision']);});}
};
