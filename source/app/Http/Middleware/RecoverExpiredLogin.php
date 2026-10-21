<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\TokenMismatchException;

/** Reject stale logins without authenticating or retaining the submitted password. */
class RecoverExpiredLogin extends ValidateCsrfToken
{
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException $e) {
            if (!config('discai.portal') || !$request->isMethod('POST') || !$request->is('login') || $request->expectsJson()) {
                throw $e;
            }
            $email = $request->input('email');
            $input = is_string($email) && strlen($email)<=254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? ['email'=>$email] : [];
            return redirect('/login', 303)
                ->withErrors(['login'=>'Sua sessão de acesso expirou ou foi atualizada em outra aba. Digite sua senha novamente para entrar.'])
                ->withInput($input)
                ->header('Cache-Control', 'no-store, private')
                ->header('Referrer-Policy', 'no-referrer');
        }
    }
}
