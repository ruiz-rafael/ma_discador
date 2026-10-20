<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    public function up(): void {
        Schema::table('portal_mail_connections',function(Blueprint $t){
            $t->string('sender_email')->nullable();
            $t->string('sender_name',120)->default('');
            $t->text('sender_options')->nullable();
        });
        // Preserve existing operation, but never silently switch an explicitly chosen sender.
        DB::table('portal_mail_connections')->where('status','connected')->update(['sender_email'=>DB::raw('email')]);
    }
    public function down(): void {Schema::table('portal_mail_connections',fn(Blueprint $t)=>$t->dropColumn(['sender_email','sender_name','sender_options']));}
};
