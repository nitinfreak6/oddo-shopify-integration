<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawBody = $request->getContent();
        $secret  = config('shopify.webhook_secret');

        if (!$secret) {
            abort(500, 'SHOPIFY_WEBHOOK_SECRET is not configured.');
        }

        $hmacHeader  = $request->header('X-Shopify-Hmac-Sha256', '');
        $computed    = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        if (!hash_equals($computed, $hmacHeader)) {
            abort(401, 'Invalid Shopify webhook HMAC.');
        }

        // Stash raw body for controller use (JSON body is already parsed by Laravel)
        $request->attributes->set('raw_body', $rawBody);

        return $next($request);
    }
}
