<?php
return [
 // Infrastructure-owned exception for a private Ollama origin. Never supplied by a tenant/browser.
 'trusted_origins'=>array_values(array_filter(explode(',',env('AI_LOCAL_TRUSTED_ORIGINS','')))),
];
