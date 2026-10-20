<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\Portal\AdministrativeDialer;
class PortalCallingController extends Controller {
 public function show(Request $r,AdministrativeDialer $d){$d->authorize($r->user()->id);return view('portal.calling');}
 public function catalog(Request $r,AdministrativeDialer $d){return $d->catalog($r->user()->id)+['calls'=>$d->recent($r->user()->id)];}
 public function create(Request $r,AdministrativeDialer $dialer){
  $dialer->authorize($r->user()->id);
  $d=$r->validate(['route'=>'required|string|max:4096','request_id'=>'required|uuid','destination'=>'required|string|max:30','consent_confirmed'=>'required|accepted','consent_evidence'=>'required|string|min:8|max:1000']);
  return $dialer->create($r->user()->id,$d);
 }
 public function action(Request $r,AdministrativeDialer $d,string $id,string $action='status'){return $d->action($r->user()->id,$id,$action);}
}
