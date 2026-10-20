<?php
// Disposable loopback Google contract fixture; never reaches a provider.
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);file_put_contents($argv[1],stream_socket_get_name($server,false));
while($client=@stream_socket_accept($server,15)){
 stream_set_timeout($client,3);$line=fgets($client);if(!$line){fclose($client);continue;}preg_match('~^([A-Z]+) (/[^ ]*)~',$line,$m);$length=0;while(($h=fgets($client))!==false&&trim($h)!=='')if(preg_match('/^Content-Length: (\d+)/i',$h,$x))$length=(int)$x[1];$body='';while(strlen($body)<$length){$part=fread($client,$length-strlen($body));if($part===false||$part==='')break;$body.=$part;}
 $path=$m[2]??'';file_put_contents($argv[2],json_encode(['path'=>$path,'body'=>json_decode($body,true)??$body])."\n",FILE_APPEND);$status=200;
 if($path==='/token')$data=['access_token'=>'fixture-access','refresh_token'=>'fixture-refresh','expires_in'=>3600,'scope'=>'openid email https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.settings.basic'];
 elseif($path==='/userinfo')$data=['sub'=>'fixture-user','email'=>'sender@example.test','email_verified'=>true];
 elseif($path==='/senders'){
  $mode=$argv[3]??'';
  if($mode==='sender-redirect'){fwrite($client,"HTTP/1.1 302 Found\r\nLocation: /unexpected\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");fclose($client);continue;}
  if($mode==='sender-outage'){$status=503;$data=['error'=>'temporary'];}
  elseif($mode==='sender-forbidden'){$status=403;$data=['error'=>'permission'];}
  else $data=['sendAs'=>[['sendAsEmail'=>'sender@example.test','isPrimary'=>true],['sendAsEmail'=>'commercial@example.test','verificationStatus'=>$mode==='sender-revoked'?'pending':'accepted','smtpMsa'=>['password'=>'not-public']],['sendAsEmail'=>'pending@example.test','verificationStatus'=>'pending']]];
 }
 elseif($path==='/send'){
  if(($argv[3]??'')==='drop'){fclose($client);continue;}
  if(($argv[3]??'')==='redirect'){$reply="HTTP/1.1 302 Found\r\nLocation: /unexpected\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";fwrite($client,$reply);fclose($client);continue;}
  $data=['id'=>'fixture-message'];
 }else{$status=404;$data=['error'=>'unknown_path'];}
 $json=json_encode($data);fwrite($client,"HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n".$json);fclose($client);
}
fclose($server);
