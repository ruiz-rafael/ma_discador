<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', \App\Http\Middleware\VoiceAccess::class);
        $middleware->trimStrings(except: [fn ($r) => $r->is('callbacks/twilio/whatsapp/*', 'callbacks/twilio/voice/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn ($r) => $r->is('callbacks/twilio/whatsapp/*', 'callbacks/twilio/voice/*')]);
        $middleware->validateCsrfTokens(except: ['api/v1/*','embed/api/*','hooks/lists/*', 'events', 'crm/catalog/sync', 'internal/voice/audio/event', 'internal/voice/calling/event', 'callbacks/twilio/whatsapp/*', 'callbacks/twilio/voice/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RuntimeException $e, Request $r) {
            if ($r->is('api/voice/whatsapp*') && str_starts_with($e->getMessage(), 'twilio_')) {
                return response()->json(['message' => 'A Twilio não confirmou a operação. Consulte o estado e a configuração antes de repetir.'], 502);
            }
        });
    })->create();
