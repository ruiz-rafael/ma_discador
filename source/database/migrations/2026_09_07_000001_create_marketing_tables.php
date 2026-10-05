<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('contacts', function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->nullable();$t->string('phone')->nullable();$t->json('fields')->nullable();$t->json('tags')->nullable();$t->integer('score')->default(0);$t->string('stage')->nullable();$t->boolean('subscribed')->default(false);$t->timestamps();});
  Schema::create('audiences', function(Blueprint $t){$t->id();$t->string('name');$t->string('type')->default('list');$t->timestamps();});
  Schema::create('audience_contact',function(Blueprint $t){$t->foreignId('audience_id')->constrained()->cascadeOnDelete();$t->foreignId('contact_id')->constrained()->cascadeOnDelete();$t->unique(['audience_id','contact_id']);});
  Schema::create('journeys',function(Blueprint $t){$t->id();$t->string('title');$t->text('description')->nullable();$t->timestamp('start_date')->nullable();$t->timestamp('end_date')->nullable();$t->boolean('is_indefinite')->default(true);$t->string('status')->default('draft');$t->json('graph')->nullable();$t->unsignedInteger('revision')->default(0);$t->unsignedInteger('published_version')->nullable();$t->timestamps();});
  Schema::create('audience_journey',function(Blueprint $t){$t->foreignId('audience_id')->constrained()->cascadeOnDelete();$t->foreignId('journey_id')->constrained()->cascadeOnDelete();$t->unique(['audience_id','journey_id']);});
  Schema::create('journey_nodes',function(Blueprint $t){$t->id();$t->foreignId('journey_id')->constrained()->cascadeOnDelete();$t->string('node_uuid');$t->string('type');$t->string('category_key');$t->string('label');$t->float('position_x');$t->float('position_y');$t->json('settings');$t->timestamps();$t->unique(['journey_id','node_uuid']);});
  Schema::create('journey_edges',function(Blueprint $t){$t->id();$t->foreignId('journey_id')->constrained()->cascadeOnDelete();$t->string('source_node_uuid');$t->string('target_node_uuid');$t->string('source_handle')->default('success');$t->timestamps();});
  Schema::create('journey_versions',function(Blueprint $t){$t->id();$t->foreignId('journey_id')->constrained()->cascadeOnDelete();$t->unsignedInteger('version');$t->json('graph');$t->timestamps();$t->unique(['journey_id','version']);});
  Schema::create('journey_subscribers',function(Blueprint $t){$t->id();$t->foreignId('journey_id')->constrained()->cascadeOnDelete();$t->foreignId('contact_id')->constrained()->cascadeOnDelete();$t->unsignedInteger('version');$t->string('event_key');$t->string('status')->default('active');$t->timestamp('entered_at');$t->timestamps();$t->unique(['journey_id','contact_id','event_key']);});
  Schema::create('journey_tokens',function(Blueprint $t){$t->id();$t->foreignId('journey_subscriber_id')->constrained()->cascadeOnDelete();$t->string('node_uuid');$t->string('path_key')->unique();$t->string('status')->default('pending')->index();$t->timestamp('resume_at')->nullable()->index();$t->json('context')->nullable();$t->timestamps();});
  Schema::create('journey_node_logs',function(Blueprint $t){$t->id();$t->foreignId('journey_id')->constrained()->cascadeOnDelete();$t->foreignId('contact_id')->constrained()->cascadeOnDelete();$t->foreignId('journey_token_id')->constrained()->cascadeOnDelete();$t->string('node_uuid');$t->string('status');$t->json('payload')->nullable();$t->json('response')->nullable();$t->timestamp('executed_at')->index();});
  Schema::create('inbound_events',function(Blueprint $t){$t->id();$t->string('event_key')->unique();$t->json('payload');$t->timestamps();});
  Schema::create('crm_records',function(Blueprint $t){$t->id();$t->foreignId('contact_id')->constrained()->cascadeOnDelete();$t->string('type');$t->string('title');$t->json('data')->nullable();$t->timestamps();});
 }
 public function down():void {foreach(['crm_records','inbound_events','journey_node_logs','journey_tokens','journey_subscribers','journey_versions','journey_edges','journey_nodes','audience_journey','journeys','audience_contact','audiences','contacts'] as $table)Schema::dropIfExists($table);}
};
