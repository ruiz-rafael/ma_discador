<?php
// Loopback-only SMTP fixture. Logs protocol stages, never addresses or content.
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);file_put_contents($argv[1],stream_socket_get_name($server,false));
while($client=@stream_socket_accept($server,8)){
 stream_set_timeout($client,5);fwrite($client,"220 fixture ESMTP\r\n");$data=false;
 while(($line=fgets($client))!==false){
  if($data){if(trim($line)!=='.')continue;file_put_contents($argv[2],"DATA\n",FILE_APPEND);if(($argv[3]??'')==='drop')break;fwrite($client,"250 queued\r\n");$data=false;continue;}
  $command=strtoupper(strtok(trim($line),' '));
  if(in_array($command,['EHLO','HELO']))fwrite($client,"250 fixture\r\n");
  elseif($command==='DATA'){$data=true;fwrite($client,"354 end with dot\r\n");}
  elseif($command==='QUIT'){fwrite($client,"221 bye\r\n");break;}
  else fwrite($client,"250 OK\r\n");
 }
 fclose($client);
}
fclose($server);
