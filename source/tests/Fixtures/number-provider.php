<?php
// Loopback-only test fixture. Shape matches IncomingPhoneNumbers used by NumberProvider::owned.
$number=$_GET['PhoneNumber']??'';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$status=$_SERVER['REQUEST_METHOD']==='GET'&&str_ends_with($path,'/IncomingPhoneNumbers.json')?($number==='+12025550003'?500:200):501;
file_put_contents(getenv('NUMBER_SIM_LOG'),json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'path'=>$path,'status'=>$status])."\n",FILE_APPEND|LOCK_EX);
http_response_code($status);header('Content-Type: application/json');
echo json_encode($status===200?['incoming_phone_numbers'=>[['sid'=>'PN'.str_repeat('b',32),'phone_number'=>$number,'account_sid'=>'AC'.str_repeat('a',32),'capabilities'=>['voice'=>true],'voice_url'=>'','status_callback'=>'','trunk_sid'=>null,'voice_application_sid'=>null]],'next_page_uri'=>null]:['code'=>20500,'message'=>'Simulated provider error']);
