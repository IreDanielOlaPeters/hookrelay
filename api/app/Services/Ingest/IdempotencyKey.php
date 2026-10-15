<?php

namespace App\Services\Ingest;

use App\Enums\Provider;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Derives the dedupe key for an inbound webhook: the provider's own event / delivery id
 * when there is one, otherwise a SHA-256 of the raw body.
 */
class IdempotencyKey
{
    public static function for(Provider $provider, HeaderBag $headers, string $body): string
    {
        $providerId = match ($provider) {
            Provider::Stripe => self::stripeEventId($body),
            Provider::GitHub => $headers->get('X-GitHub-Delivery'),
            Provider::Shopify => $headers->get('X-Shopify-Webhook-Id'),
            Provider::WooCommerce => $headers->get('X-WC-Webhook-Delivery-ID'),
            // Generic senders may opt in to explicit keys with the de-facto standard header.
            Provider::Generic => $headers->get('Idempotency-Key'),
        };

        $providerId = is_string($providerId) ? trim($providerId) : '';
        if ($providerId !== '' && strlen($providerId) <= 200) {
            return 'id:'.$providerId;
        }

        return 'sha256:'.hash('sha256', $body);
    }

    private static function stripeEventId(string $body): ?string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])
            ? $decoded['id']
            : null;
    }
}
