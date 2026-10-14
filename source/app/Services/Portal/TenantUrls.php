<?php
namespace App\Services\Portal;
class TenantUrls {
 public static function origin():string {$b=app(TenantBridge::class)->config();return !empty($b['required'])?rtrim(config('app.url'),'/'):'https://ma.zyrex.ia.br';}
}
