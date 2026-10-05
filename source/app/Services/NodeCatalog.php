<?php
namespace App\Services;
class NodeCatalog {
 public static function all():array {
  $groups=[
   'trigger'=>['list_added'=>'Adicionado à lista','list_removed'=>'Removido da lista','segment_added'=>'Adicionado ao segmento','segment_removed'=>'Sair do segmento','form_submitted'=>'Envio de formulário','email_event'=>'Ação do e-mail','whatsapp_event'=>'Ação do WhatsApp','date_field'=>'Campo de data','criteria'=>'Correspondência de critérios','tag_added'=>'Tag atribuída','tag_removed'=>'Tag removida','stage_changed'=>'Estágio do lead','score_changed'=>'Pontuação do lead','goal'=>'Meta atingida','page_visit'=>'Visita à página','open_trigger'=>'Gatilho por API','cart_abandoned'=>'Carrinho abandonado','purchase'=>'Acompanhamento de compra','cyclic'=>'Cíclico'],
   'process'=>['if_else'=>'Se / Outro','multi_branch'=>'Divisão múltipla','check_activity'=>'Verificar atividade','ab_split'=>'Divisão aleatória A/B','merge'=>'Consolidar'],
   'action'=>['send_email'=>'Enviar e-mail','send_sms'=>'Enviar SMS','send_whatsapp'=>'Enviar WhatsApp','add_list'=>'Adicionar à lista','remove_list'=>'Excluir da lista','add_tag'=>'Atribuir tag','remove_tag'=>'Remover tag','update_field'=>'Atualizar campo','update_score'=>'Atualizar pontuação','update_stage'=>'Atualizar estágio','internal_email'=>'E-mail interno','subscription'=>'Gerenciar assinatura','delay'=>'Atraso de tempo','wait_until'=>'Condição de espera','webhook'=>'Webhook','move_journey'=>'Mover para outra jornada','remove_journey'=>'Remover da jornada','sync_crm'=>'Sincronizar CRM','create_task'=>'Criar tarefa','create_deal'=>'Criar negócio']];
  $result=[]; foreach($groups as $type=>$nodes)foreach($nodes as $key=>$label){
   $ports=match($key){'if_else'=>['yes','no'],'ab_split'=>['a','b'],'multi_branch'=>['branch_1','branch_2','other'],'check_activity','wait_until'=>['yes','timeout'],'send_whatsapp'=>['sent','delivered','read','failed'],default=>['success']};
   $result[$key]=compact('key','label','type','ports')+['fields'=>array_column(NodeConfiguration::schema($key)['fields'],'key'),'schema'=>NodeConfiguration::schema($key),'integration'=>in_array($key,['send_email','send_sms','send_whatsapp','internal_email','sync_crm'])];
  } return $result;
 }
}
