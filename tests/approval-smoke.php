<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Nateyk\Chapa\Transfers\ApprovalCallback;
use Nateyk\Chapa\Transfers\ApprovalSignature;

$passes = 0;
$check = static function (bool $ok, string $why) use (&$passes): void {
    ++$passes;
    if (!$ok) {
        throw new RuntimeException('Transfer approval smoke test failed: '.$why);
    }
};
$secret = 'synthetic-only-super-secret-approval';
$raw = '{"reference":"PAYOUT123","amount":"123.45","bank":"telebirr","account_name":"A Test","account_number":"0012345"}';
$signature = hash_hmac('sha256', $raw, $secret);
$v = new ApprovalSignature($secret);
$check($v->verify($raw, $signature), 'authentic raw-body signature');
$check(!$v->verify($raw.' ', $signature), 'tampered bytes rejected');
$check(!$v->verify($raw, 'abc'), 'invalid signature format rejected');
$check(!$v->verify($raw, null), 'missing signature rejected');
$callback = ApprovalCallback::fromRawBody($raw);
$check($callback->amountCents === 12345, 'decimal major units converted to cents precisely');
$check($callback->reference === 'PAYOUT123', 'merchant reference parsed');
$check($callback->accountNumber === '0012345', 'leading zeros retained');
$check($callback->currency === null, 'legacy callback currency optional');
$rejected = 0;
foreach (['12.345', '-1.00', '0.01', '1e2', '1.00 ', 12.3] as $bad) {
    $mutated = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $mutated['amount'] = $bad;
    try {
        ApprovalCallback::fromRawBody(json_encode($mutated, JSON_THROW_ON_ERROR));
    } catch (InvalidArgumentException) {
        ++$rejected;
    }
}
$check($rejected === 6, 'invalid money forms rejected');
printf("OK: %d transfer approval smoke assertions passed\n", $passes);
