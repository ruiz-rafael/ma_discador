<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Schema};
/** Updates editable brand labels and future message text, preserving historical records and provider identities. */
class PlatformBranding {
 public function apply(bool $portal=false):array {
  $fields=$portal?['users'=>['name'],'portal_tenants'=>['name']]:[
   'users'=>['name'],'voice_workspaces'=>['name'],'voice_campaigns'=>['name','settings'],
   'voice_lists'=>['name'],'voice_live_queues'=>['name'],'wa_senders'=>['label'],
   'wa_templates'=>['name','body'],'wa_qr_templates'=>['name','body'],
   'audiences'=>['name'],'journeys'=>['title','description'],'speech_templates'=>['name','body'],'ma_integrations'=>['name'],
  ];
  return DB::transaction(function()use($fields){
   $counts=[];
   foreach($fields as $table=>$columns){
    if(!Schema::hasTable($table))continue;
    $query=DB::table($table);
    if($table==='wa_templates')$query->where('state','draft')->whereNull('content_sid');
    $query->where(function($q)use($columns){foreach($columns as $column)$q->orWhereRaw('LOWER(CAST('.$column.' AS TEXT)) LIKE ?',['%zyrex%']);});
    foreach($query->lockForUpdate()->get() as $row){
     $update=[];
     foreach($columns as $column){
      $old=$row->$column;if(!is_string($old))continue;
      if($column==='settings'){
       $data=json_decode($old,true,512,JSON_THROW_ON_ERROR);$next=$data;
       // Only editable text: never replace callbacks, provider IDs, keys or nested operational configuration.
       foreach(['script','whatsapp_text','company_name','greeting_text','voicemail_text']as $key)if(isset($next[$key])&&is_string($next[$key]))$next[$key]=$this->text($next[$key]);
       $value=$next===$data?$old:json_encode($next,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      } else $value=$table==='wa_templates'&&$column==='name'?str_ireplace('zyrex','discai',$old):$this->text($old);
      if($value!==$old)$update[$column]=$value;
     }
     if(!$update)continue;
     if(Schema::hasColumn($table,'revision'))$update['revision']=$row->revision+1;
     if(Schema::hasColumn($table,'updated_at'))$update['updated_at']=now();
     DB::table($table)->where('id',$row->id)->update($update);$counts[$table]=($counts[$table]??0)+1;
    }
   }
   return $counts;
  });
 }
 private function text(string $text):string {
  // Existing URLs and email addresses are connection/account identities, not brand labels.
  return preg_replace_callback('~https?://[^\s<>"\']+|[\w.+-]+@[\w.-]+|zyrex~iu',fn($m)=>strcasecmp($m[0],'zyrex')===0?'DiscAI':$m[0],$text);
 }
}
