<?php
namespace App\Services;
class QueueDestinations {
 public static function reason(object $queue,string $phone):?string {
  if(!str_starts_with($phone,'+55'))return ($queue->allow_international??true)?null:'Esta fila não permite chamadas internacionais.';
  if(preg_match('/^\+55(?:300|500|800|900)[0-9]{7}$/D',$phone))return ($queue->allow_special??false)?null:'Esta fila não permite números de serviços especiais.';
  if(preg_match('/^\+55[1-9][0-9]9[0-9]{8}$/D',$phone)&&!$queue->allow_mobile)return 'Esta fila não permite chamadas para celulares.';
  if(preg_match('/^\+55[1-9][0-9][2-5][0-9]{7}$/D',$phone)&&!$queue->allow_landline)return 'Esta fila não permite chamadas para telefones fixos.';
  if(str_starts_with($phone,'+55')&&!preg_match('/^\+55[1-9][0-9](?:9[0-9]{8}|[2-5][0-9]{7})$/D',$phone))return 'Destino brasileiro fora das categorias de fixo ou celular permitidas pela fila.';
  return null;
 }
 public static function check(object $queue,string $phone):void {abort_if($reason=self::reason($queue,$phone),422,$reason);}
}
