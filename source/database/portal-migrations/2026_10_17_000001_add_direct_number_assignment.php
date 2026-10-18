<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {Schema::table('portal_number_requests',function(Blueprint $t){$t->string('source',24)->default('client_request');$t->string('ddd',2)->nullable()->change();});}
 public function down():void {DB::table('portal_number_requests')->whereNull('ddd')->update(['ddd'=>'']);Schema::table('portal_number_requests',function(Blueprint $t){$t->dropColumn('source');$t->string('ddd',2)->nullable(false)->change();});}
};
