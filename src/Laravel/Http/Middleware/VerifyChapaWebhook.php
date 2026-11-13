<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nateyk\Chapa\ChapaClient;
use Symfony\Component\HttpFoundation\Response;

/** Apply this middleware to your own CSRF-exempt webhook route before processing events. */
final class VerifyChapaWebhook
{
    public function __construct(private readonly ChapaClient $chapa)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->chapa->webhooks()->verify($request->getContent(), $request->header('x-chapa-signature'))) {
            abort(401, 'Invalid Chapa webhook signature.');
        }

        return $next($request);
    }
}
