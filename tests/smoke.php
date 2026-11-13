<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/MockTransport.php';

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Exceptions\ApiException;
use Nateyk\Chapa\Support\IdempotencyKey;
use Nateyk\Chapa\Support\PaymentVerifier;
use Nateyk\Chapa\Tests\MockTransport;
use Nateyk\Chapa\Http\RawResponse;

$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
};
$throws = static function (callable $callback, string $exception) use ($check): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $check($error instanceof $exception, 'Unexpected exception: '.$error::class);
        return;
    }
    $check(false, 'Expected exception: '.$exception);
};

$transport = new MockTransport();
$chapa = new ChapaClient('CHAPA_TEST_private', 'CHAPA_TEST_public', 'webhook-secret', $transport);
$payload = ['amount' => 10000, 'currency' => 'ETB', 'merchant_reference' => 'order-101'];
$chapa->payments()->hosted($payload, 'order-101-create');
$req = $transport->requests[0];
$check($req['url'] === 'https://api.chapa.global/v2/payments/hosted', 'Hosted endpoint');
$check($req['method'] === 'POST', 'Hosted HTTP method');
$check($req['headers']['Authorization'] === 'Bearer CHAPA_TEST_private', 'Private auth');
$check($req['headers']['Idempotency-Key'] === 'order-101-create', 'Idempotency');
$check(json_decode($req['body'], true)['amount'] === 10000, 'Integer cents');
$chapa->payments()->inline($payload + ['payment_method' => 'telebirr', 'mobile' => '251911000000'], 'inline-op');
$check($transport->requests[1]['headers']['Authorization'] === 'Bearer CHAPA_TEST_public', 'Public auth for inline');
$chapa->payments()->verify('CH0abc');
$check($transport->requests[2]['url'] === 'https://api.chapa.global/v2/payments/CH0abc/verify', 'Verify endpoint');
$chapa->payments()->validateDirect(['reference' => 'CH0abc', 'payment_method' => 'telebirr']);
$check(!isset($transport->requests[3]['headers']['Idempotency-Key']), 'No idempotency header for validation');
$chapa->payouts()->create(['amount' => 10000, 'account_reference' => 'ACC1'], 'payout-101');
$check(str_ends_with($transport->requests[4]['url'], '/v2/payouts'), 'Payout endpoint');
$chapa->payouts()->bulk(['currency' => 'ETB', 'items' => [['amount' => 10000, 'account_reference' => 'ACC1']]], 'bulk-1');
$check(str_ends_with($transport->requests[5]['url'], '/v2/payouts/bulk'), 'Bulk payout endpoint');
$chapa->payouts()->b2b(['amount' => 10000, 'merchant_id' => 'merchant-7'], 'b2b-1');
$check(str_ends_with($transport->requests[6]['url'], '/v2/payouts/b2b'), 'B2B payout endpoint');
$chapa->refunds()->create(['payment_reference' => 'CH0abc'], 'refund-101');
$check(str_ends_with($transport->requests[7]['url'], '/v2/refunds'), 'Refund endpoint');
$chapa->balances()->get('ETB');
$check(str_ends_with($transport->requests[8]['url'], '/v2/balances?currency=ETB'), 'Balance endpoint');
$chapa->subaccounts()->update('SUB_1', ['split_value' => 10]);
$check($transport->requests[9]['method'] === 'PUT', 'Update uses PUT');
$check(strlen(IdempotencyKey::generate()) === 32, 'Random operation key');
$throws(static fn () => $chapa->payments()->hosted(['amount' => 9.99, 'currency' => 'ETB'], 'abc'), InvalidArgumentException::class);
$throws(static fn () => $chapa->payments()->verify('../admin'), InvalidArgumentException::class);
$throws(static fn () => $chapa->payments()->hosted($payload, "bad\nkey"), InvalidArgumentException::class);
$throws(static fn () => new ChapaClient('secret', baseUrl: 'http://insecure.local'), InvalidArgumentException::class);
$raw = '{"event":"payment.success","amount":"10000"}';
$sig = hash_hmac('sha256', $raw, 'webhook-secret');
$check($chapa->webhooks()->verify($raw, $sig), 'Webhook signature');
$check(!$chapa->webhooks()->verify($raw.' ', $sig), 'Webhook tamper detection');
$check(!$chapa->webhooks()->verify($raw, ''), 'Webhook missing signature');
$check($chapa->webhooks()->decode($raw, $sig)['event'] === 'payment.success', 'Webhook decode');
$throws(static fn () => $chapa->webhooks()->decode($raw, str_repeat('0', 64)), \Nateyk\Chapa\Exceptions\ChapaException::class);
$transport->responses[] = new RawResponse(200, '{"status":"success","data":{"status":"SUCCESS","chapa_reference":"CH0abc","amount":10000,"currency":"ETB","merchant_reference":"order-101"}}');
$verified = $chapa->payments()->verify('CH0abc');
$check(PaymentVerifier::matches($verified, 10000, 'ETB', 'order-101'), 'Verification matches order');
$check(!PaymentVerifier::matches($verified, 10001, 'ETB', 'order-101'), 'Verification rejects amount mismatch');
$check(!PaymentVerifier::matches($verified, 10000, 'USD', 'order-101'), 'Verification rejects currency mismatch');
$check(!PaymentVerifier::matches($verified, 10000, 'ETB', 'other'), 'Verification rejects reference mismatch');
$transport->responses[] = new RawResponse(409, '{"status":"error","message":"Conflict","error":{"code":"INVALID_STATE"}}');
$throws(static fn () => $chapa->refunds()->verify('REF1'), ApiException::class);
$check(count($transport->requests) === 12, 'No automatic unsafe retries');

printf("OK: %d assertions passed\n", $count);
