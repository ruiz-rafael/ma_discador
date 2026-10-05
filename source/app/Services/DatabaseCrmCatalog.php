<?php
namespace App\Services;
use App\Contracts\CrmCatalog;
use App\Models\CrmResource;
class DatabaseCrmCatalog implements CrmCatalog {
 public function search(string $kind,string $query='',?string $channel=null,int $page=1):array {
  $q=CrmResource::where('kind',$kind)->where('active',true);
  if($channel)$q->where('channel',$channel);
  if($query!==''){$needle='%'.mb_strtolower($query).'%';$q->where(fn($q)=>$q->whereRaw('LOWER(name) LIKE ?',[$needle])->orWhereRaw('LOWER(external_id) LIKE ?',[$needle]));}
  $p=$q->orderBy('name')->orderBy('id')->paginate(25,['*'],'page',$page);
  return ['data'=>$p->items(),'page'=>$p->currentPage(),'has_more'=>$p->hasMorePages(),'total'=>$p->total()];
 }
 public function find(string $kind,string $externalId):?array{return CrmResource::where('kind',$kind)->where('external_id',$externalId)->first()?->toArray();}
}
