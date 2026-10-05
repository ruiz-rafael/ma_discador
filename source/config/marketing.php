<?php
return ['catalog_secret'=>env('MA_CRM_CATALOG_SECRET'),'gateway_url'=>env('MA_GATEWAY_URL'),'gateway_token'=>env('MA_GATEWAY_TOKEN'),'webhook_secret'=>env('MA_WEBHOOK_SECRET'),'allowed_hosts'=>array_filter(explode(',',env('MA_ALLOWED_HOSTS','')))];
