<?php

namespace App\Services\Ingest;

use App\Enums\Provider;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Verifies provider webhook signatures against the raw request body.
 * Every comparison goes through hash_equals() to avoid timing leaks.
 */
class SignatureVerifier
{
    public function __construct(private readonly int $stripeToleranceSeconds = 300) {}

    public function verify(Provider $provider, HeaderBag $headers, string $body, string $secret, ?int $now = null): bool
    {
        return match ($provider) {
            Provider::Stripe => $this->stripe($headers->get('Stripe-Signature'), $body, $secret, $now ?? time()),
            Provider::GitHub => $this->prefixedHex($headers->get('X-Hub-Signature-256'), $body, $secret),
            Provider::Shopify => $this->base64($headers->get('X-Shopify-Hmac-Sha256'), $body, $secret),
            Provider::WooCommerce => $this->base64($headers->get('X-WC-Webhook-Signature'), $body, $secret),
            // Generic sources: hex HMAC-SHA256 of the body, optionally prefixed with "sha256=".
            Provider::Generic => $this->prefixedHex($headers->get('X-Webhook-Signature'), $body, $secret, optionalPrefix: true),
        };
    }

    /**
     * Stripe-Signature: t=1700000000,v1=<hex>[,v1=<hex>][,v0=...]
     * Signed payload is "{t}.{body}"; any v1 may match; t must be within the tolerance.
     */
    private function stripe(?string $header, string $body, string $secret, int $now): bool
    {
        if (! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs($now - $timestamp) > $this->stripeToleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /** "sha256=<hex>" (GitHub). With $optionalPrefix the bare hex digest is accepted too. */
    private function prefixedHex(?string $header, string $body, string $secret, bool $optionalPrefix = false): bool
    {
        if (! $header) {
            return false;
        }

        $header = trim($header);
        if (str_starts_with($header, 'sha256=')) {
            $header = substr($header, 7);
        } elseif (! $optionalPrefix) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), strtolower($header));
    }

    /** Base64 of the raw HMAC-SHA256 digest (Shopify, WooCommerce). */
    private function base64(?string $header, string $body, string $secret): bool
    {
        if (! $header) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), trim($header));
    }
}
