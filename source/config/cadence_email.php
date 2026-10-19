<?php
return [
 // Infrastructure-owned SMTP configuration, isolated by tenant runtime.
 'enabled'=>env('DISCAI_EMAIL_ENABLED',false),
 'daily_limit'=>(int)env('DISCAI_EMAIL_DAILY_LIMIT',20),
 'from'=>env('DISCAI_EMAIL_FROM'),
 'name'=>env('DISCAI_EMAIL_NAME','DiscAI'),
 'reply_to'=>env('DISCAI_EMAIL_REPLY_TO'),
];
