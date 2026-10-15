<?php

namespace App\Enums;

enum Provider: string
{
    case Generic = 'generic';
    case Stripe = 'stripe';
    case GitHub = 'github';
    case Shopify = 'shopify';
    case WooCommerce = 'woocommerce';
}
