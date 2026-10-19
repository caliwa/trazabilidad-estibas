<?php

namespace App\Http\Middleware;

use App\Support\Correlation\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a caller's X-Correlation-Id when it is a UUID (a device can tie
 * its own retries together), otherwise creates one.
 */
class AssignCorrelationId
{
    public function __construct(private readonly CorrelationId $correlation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->correlation->set((string) $request->header('X-Correlation-Id', ''));
        Log::shareContext(['correlation_id' => $this->correlation->get()]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $this->correlation->get());

        return $response;
    }
}
