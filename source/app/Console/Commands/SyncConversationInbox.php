<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\ConversationInbox;
class SyncConversationInbox extends Command {
 protected $signature='ma:inbox-sync {--limit=500}';protected $description='Vincula mensagens existentes à caixa, sem enviar mensagens.';
 public function handle():int {$n=0;foreach(DB::table('wa_messages')->whereNull('conversation_id')->orderBy('created_at')->limit(min(5000,max(1,(int)$this->option('limit'))))->pluck('id') as $id){app(ConversationInbox::class)->record($id);$n++;}DB::transaction(function(){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();foreach(DB::table('wa_conversations')->where('status','open')->whereNull('assigned_user_id')->whereNotNull('queue_id')->limit(200)->pluck('id') as $id)app(ConversationInbox::class)->distribute($id);});$this->info('Mensagens vinculadas: '.$n);return self::SUCCESS;}
}
