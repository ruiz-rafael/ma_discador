<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('portal_invitations',function(Blueprint $t){$t->foreignId('created_by')->nullable()->constrained('users');$t->string('delivery_status',24)->default('link');$t->timestamp('emailed_at')->nullable();});}
 public function down():void {Schema::table('portal_invitations',function(Blueprint $t){$t->dropConstrainedForeignId('created_by');$t->dropColumn(['delivery_status','emailed_at']);});}
};
