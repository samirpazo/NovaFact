<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateSessionToken
{
    public function __construct(
        protected SessionTokenService $sessionTokenService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return app(ValidateBillingToken::class)->handle($request, $next);
    }
}
