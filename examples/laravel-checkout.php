<?php

// Illustrative Laravel controller methods; integrate with your own Order model and routes.
// This file is not loaded automatically by the package.

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Support\PaymentVerifier;

final class ExampleChapaCheckoutController
{
    public function start(ChapaClient $chapa): RedirectResponse
    {
        $order = /* Load and authorize a pending order */ null;
        // Save your own payment_attempt and merchant_reference before issuing this call.
        // Replace null with a real order before using this example.
        if ($order === null) {
            abort(501, 'Connect this example to your order repository.');
        }

        $response = $chapa->payments()->hosted([
            'amount' => $order->amount_cents,
            'currency' => $order->currency,
            'merchant_reference' => $order->merchant_reference,
            'customer' => ['email' => $order->customer_email],
            'return_url' => route('checkout.return'),
        ], 'checkout-'.$order->id.'-'.$order->payment_attempt);

        return redirect()->away($response->data()['checkout_url']);
    }

    public function confirm(ChapaClient $chapa): void
    {
        $order = /* Load and authorize order identified by the request */ null;
        if ($order === null) {
            abort(404);
        }

        $result = $chapa->payments()->verify($order->merchant_reference);
        if (!PaymentVerifier::matches($result, $order->amount_cents, $order->currency, $order->merchant_reference)) {
            abort(409, 'Payment is not verified.');
        }

        // In your application: DB::transaction + lockForUpdate + unique fulfillment key.
        // Do not mark fulfilled solely due to a redirect or an unverified webhook.
    }
}
