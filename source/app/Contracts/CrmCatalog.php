<?php
namespace App\Contracts;
interface CrmCatalog {
 public function search(string $kind,string $query='',?string $channel=null,int $page=1):array;
 public function find(string $kind,string $externalId):?array;
}
