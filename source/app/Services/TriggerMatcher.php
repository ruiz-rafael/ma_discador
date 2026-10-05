<?php
namespace App\Services;
use App\Models\Contact;
class TriggerMatcher {
 public function matches(array $node,Contact $contact,array $attributes=[]):bool {
  $key=$node['data']['key'];$s=$node['data']['settings']??[];
  if($key==='criteria'&&!app(NodeHandlers::class)->matches($contact,$s))return false;
  if($key==='score_changed'&&!app(NodeHandlers::class)->matches($contact,array_merge($s,['field'=>'score'])))return false;
  $fields=match($key){'segment_added','segment_removed'=>['segment_id'],'email_event'=>['campaign_id','event'],'whatsapp_event'=>['campaign_id','event','button_key'],'form_submitted'=>['form_id'],'goal'=>['goal_id'],'page_visit'=>['url'],'tag_added','tag_removed'=>['tag'],'stage_changed'=>['stage'],'open_trigger'=>['event_name'],'purchase'=>['store_id','order_status'],'cart_abandoned'=>['store_id'],default=>[]};
  foreach($fields as $field)if(isset($s[$field])&&$s[$field]!==''&&(string)($attributes[$field]??'')!==(string)$s[$field])return false;
  if(in_array($key,['list_added','list_removed'])){
   if(($s['list_source']??'journey')==='crm')return isset($s['list_id'])&&(string)($attributes['list_id']??'')===(string)$s['list_id'];
   if(($s['list_source']??'journey')==='local')return in_array((string)($s['audience_id']??''),array_map('strval',$attributes['audience_ids']??[]),true);
  }
  return true;
 }
}
