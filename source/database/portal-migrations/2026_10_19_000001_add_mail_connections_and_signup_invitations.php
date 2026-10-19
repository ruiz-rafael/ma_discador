<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('portal_mail_connections', function (Blueprint $t) {
            $t->string('scope',64)->primary(); // platform or tenant:<portal ID>; never a caller supplied sender.
            $t->foreignId('tenant_id')->nullable()->constrained('portal_tenants');
            $t->string('google_sub')->nullable()->unique();
            $t->string('email')->nullable();
            $t->text('tokens')->nullable();
            $t->string('status',24)->default('disconnected');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('daily_limit')->default(20);
            $t->unsignedInteger('revision')->default(0);
            $t->foreignId('connected_by')->nullable()->constrained('users');
            $t->timestamp('connected_at')->nullable();$t->timestamps();
        });
        Schema::create('portal_mail_oauth_states', function (Blueprint $t) {
            $t->string('state_hash',64)->primary();$t->string('scope',64);
            $t->foreignId('user_id')->constrained('users');$t->string('session_hash',64);
            $t->text('verifier');$t->unsignedInteger('revision');
            $t->timestamp('expires_at');$t->timestamp('used_at')->nullable();$t->timestamps();
        });
        Schema::create('portal_mail_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();$t->string('scope',64);$t->string('request_key',100);
            $t->string('payload_hash',64);$t->string('destination');$t->string('status',24);
            $t->unsignedInteger('connection_revision');$t->string('provider_id')->nullable();
            $t->string('reason',240)->nullable();$t->timestamps();$t->unique(['scope','request_key']);$t->index(['scope','created_at']);
        });
        Schema::create('portal_signup_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();$t->string('email',200);$t->string('company_name',160);
            $t->string('token_hash',64)->unique();$t->text('token_encrypted')->nullable();
            $t->foreignId('created_by')->constrained('users');$t->foreignId('tenant_id')->nullable()->constrained('portal_tenants');
            $t->string('plan',80);$t->unsignedInteger('seats');$t->unsignedInteger('channels');$t->unsignedInteger('storage_mb');
            $t->string('delivery_status',24)->default('link');$t->timestamp('expires_at');$t->timestamp('accepted_at')->nullable();$t->timestamp('revoked_at')->nullable();$t->timestamps();
        });
    }
    public function down(): void {
        foreach (['portal_signup_invitations','portal_mail_submissions','portal_mail_oauth_states','portal_mail_connections'] as $table) Schema::dropIfExists($table);
    }
};
