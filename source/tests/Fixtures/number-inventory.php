<?php
// Isolated HTTP fixture; no connection to any provider or tenant.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
$ac='AC'.str_repeat('a',32);$status=501;$data=[];
if($method==='GET'&&$path==='/2010-04-01/Accounts/'.$ac.'/IncomingPhoneNumbers.json'){
 $status=200;$page=(int)($_GET['Page']??0);
 $data=['incoming_phone_numbers'=>[['phone_number'=>$page?'+551130001235':'+551130001234','account_sid'=>$ac,'sid'=>'PN'.str_repeat($page?'b':'a',32),'capabilities'=>['voice'=>true]]],'next_page_uri'=>$page?null:'/2010-04-01/Accounts/'.$ac.'/IncomingPhoneNumbers.json?Page=1'];
}
if($method==='POST'&&$path==='/discai-internal/number-inventory'){
 $body=file_get_contents('php://input');$stamp=$_SERVER['HTTP_X_DISCAI_TIME']??'';
 $valid=hash_equals(hash_hmac('sha256',$stamp.'.'.ltrim($path,'/').'.'.$body,'fixture-secret'),$_SERVER['HTTP_X_DISCAI_SIGNATURE']??'');
 $status=$valid?(is_file(getenv('INVENTORY_FAIL_FLAG'))?503:200):401;
 $data=['tenant'=>'discai','numbers'=>[['number'=>'+551154443245','provider'=>'fale_vono']]];
}
file_put_contents(getenv('INVENTORY_HTTP_LOG'),json_encode(['method'=>$method,'path'=>$path,'status'=>$status])."\n",FILE_APPEND|LOCK_EX);
http_response_code($status);header('Content-Type: application/json');echo json_encode($data);
