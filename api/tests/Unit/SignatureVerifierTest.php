<?php

use App\Enums\Provider;
use App\Services\Ingest\SignatureVerifier;
use Symfony\Component\HttpFoundation\HeaderBag;

const SECRET = 'shhh-its-a-secret';
const BODY = '{"id":"evt_123","type":"invoice.paid","amount":4200}';

function verify(Provider $provider, array $headers, string $body = BODY, ?int $now = null): bool
{
    return (new SignatureVerifier(300))->verify($provider, new HeaderBag($headers), $body, SECRET, $now);
}

function stripeHeader(string $body, int $timestamp, string $secret = SECRET): string
{
    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

describe('stripe', function () {
    it('accepts a valid signature', function () {
        expect(verify(Provider::Stripe, ['Stripe-Signature' => stripeHeader(BODY, time())]))->toBeTrue();
    });

    it('accepts any matching v1 when several are present (secret rotation)', function () {
        $t = time();
        $header = "t={$t},v1=".str_repeat('0', 64).','.substr(stripeHeader(BODY, $t), strlen("t={$t},"));
        expect(verify(Provider::Stripe, ['Stripe-Signature' => $header]))->toBeTrue();
    });

    it('rejects a tampered body', function () {
        $header = stripeHeader(BODY, time());
        expect(verify(Provider::Stripe, ['Stripe-Signature' => $header], str_replace('4200', '1', BODY)))->toBeFalse();
    });

    it('rejects a timestamp older than 5 minutes', function () {
        $old = time() - 301;
        expect(verify(Provider::Stripe, ['Stripe-Signature' => stripeHeader(BODY, $old)]))->toBeFalse();
    });

    it('accepts a timestamp just inside the tolerance', function () {
        $now = 1_700_000_000;
        expect(verify(Provider::Stripe, ['Stripe-Signature' => stripeHeader(BODY, $now - 299)], BODY, $now))->toBeTrue();
    });

    it('rejects a missing or malformed header', function (?string $header) {
        expect(verify(Provider::Stripe, $header === null ? [] : ['Stripe-Signature' => $header]))->toBeFalse();
    })->with([null, '', 'garbage', 't=abc,v1=deadbeef', 't='.PHP_INT_MAX]);
});

describe('github', function () {
    it('accepts a valid signature', function () {
        $sig = 'sha256='.hash_hmac('sha256', BODY, SECRET);
        expect(verify(Provider::GitHub, ['X-Hub-Signature-256' => $sig]))->toBeTrue();
    });

    it('rejects a tampered body', function () {
        $sig = 'sha256='.hash_hmac('sha256', BODY, SECRET);
        expect(verify(Provider::GitHub, ['X-Hub-Signature-256' => $sig], BODY.' '))->toBeFalse();
    });

    it('rejects a signature without the sha256= prefix or with the wrong secret', function () {
        expect(verify(Provider::GitHub, ['X-Hub-Signature-256' => hash_hmac('sha256', BODY, SECRET)]))->toBeFalse()
            ->and(verify(Provider::GitHub, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', BODY, 'nope')]))->toBeFalse();
    });
});

describe('shopify', function () {
    it('accepts a valid signature', function () {
        $sig = base64_encode(hash_hmac('sha256', BODY, SECRET, true));
        expect(verify(Provider::Shopify, ['X-Shopify-Hmac-Sha256' => $sig]))->toBeTrue();
    });

    it('rejects a tampered body', function () {
        $sig = base64_encode(hash_hmac('sha256', BODY, SECRET, true));
        expect(verify(Provider::Shopify, ['X-Shopify-Hmac-Sha256' => $sig], '{"id":"evt_999"}'))->toBeFalse();
    });
});

describe('woocommerce', function () {
    it('accepts a valid signature', function () {
        $sig = base64_encode(hash_hmac('sha256', BODY, SECRET, true));
        expect(verify(Provider::WooCommerce, ['X-WC-Webhook-Signature' => $sig]))->toBeTrue();
    });

    it('rejects a tampered body', function () {
        $sig = base64_encode(hash_hmac('sha256', BODY, SECRET, true));
        expect(verify(Provider::WooCommerce, ['X-WC-Webhook-Signature' => $sig], strtoupper(BODY)))->toBeFalse();
    });
});

describe('generic', function () {
    it('accepts a hex signature with or without the sha256= prefix', function () {
        $hex = hash_hmac('sha256', BODY, SECRET);
        expect(verify(Provider::Generic, ['X-Webhook-Signature' => $hex]))->toBeTrue()
            ->and(verify(Provider::Generic, ['X-Webhook-Signature' => "sha256={$hex}"]))->toBeTrue();
    });

    it('rejects a tampered body', function () {
        $hex = hash_hmac('sha256', BODY, SECRET);
        expect(verify(Provider::Generic, ['X-Webhook-Signature' => $hex], '{}'))->toBeFalse();
    });
});
