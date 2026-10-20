<?php
// Real loopback HTTP contract fixture. Never connects to a phone or provider.
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);file_put_contents($argv[1],stream_socket_get_name($server,false));
while($client=@stream_socket_accept($server,15)){
 stream_set_timeout($client,3);$line=fgets($client);if(!$line){fclose($client);continue;}$length=0;$headers=[];while(($h=fgets($client))!==false&&trim($h)!==''){[$k,$v]=explode(':',$h,2);$headers[strtolower(trim($k))]=trim($v);if(strtolower($k)==='content-length')$length=(int)$v;}
 $body='';while(strlen($body)<$length){$part=fread($client,$length-strlen($body));if(!$part)break;$body.=$part;}$d=json_decode($body,true);$valid=hash_equals(hash_hmac('sha256',($headers['x-discai-time']??'').'.discai-internal/calling.'.$body,'fixture-secret'),$headers['x-discai-signature']??'');
 file_put_contents($argv[2],json_encode(['action'=>$d['action']??null,'signed'=>$valid])."\n",FILE_APPEND);$mode=$argv[3];$status=200;$data=['tenant'=>'fixture'];
 if(!$valid){$status=401;$data=['message'=>'Invalid signature'];}
 elseif(($d['action']??null)==='reserve'){
  if($mode==='drop'){fclose($client);continue;}
  if($mode==='redirect'){fwrite($client,"HTTP/1.1 302 Found\r\nLocation: /unexpected\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");fclose($client);continue;}
  if($mode==='forbidden'){$status=403;$data=['message'=>'Access revoked'];}
  else $data['grant']=['id'=>'fixture-call','method'=>'programmable_voice','access_token'=>'fixture-private-token'];
 }else $data['call']=['found'=>true,'status'=>'cancelled','ended'=>true];
 $json=json_encode($data);fwrite($client,"HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n".$json);fclose($client);
}
