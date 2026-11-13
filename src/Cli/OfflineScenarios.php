<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Support\PaymentVerifier;

/** No user credentials, real wallets, external HTTP or webhooks. All inputs are synthetic. */
final class OfflineScenarios
{
    /** @var list<string> */
    public const NAMES = ['direct', 'inline', 'states', 'webhooks'];

    public function run(string $scenario = 'all'): DiagnosticReport
    {
        if (!in_array($scenario, [...self::NAMES, 'all'], true)) {
            throw new \InvalidArgumentException('Unknown offline scenario.');
        }
        $report = new DiagnosticReport('offline', $scenario);
        foreach (self::NAMES as $name) {
            if ($scenario !== 'all' && $scenario !== $name) {
                continue;
            }
            $this->{$name}($report);
        }
        return $report;
    }

    private function direct(DiagnosticReport $report): void
    {
        $transport = new ScenarioTransport([
            $this->response(['status' => 'PENDING', 'chapa_reference' => 'CHSIMDIRECT']),
            $this->response(['status' => 'PENDING', 'chapa_reference' => 'CHSIMDIRECT']),
            $this->response(['status' => 'SUCCESS', 'chapa_reference' => 'CHSIMDIRECT', 'merchant_reference' => 'SIMDIRECT', 'amount' => 10000, 'currency' => 'ETB']),
        ]);
        $client = new ChapaClient('CHAPA_TEST_SYNTHETIC_PRIVATE', 'CHAPA_TEST_SYNTHETIC_PUBLIC', transport: $transport);
        $client->payments()->direct([
            'payment_method' => 'telebirr', 'amount' => 10000, 'currency' => 'ETB',
            'merchant_reference' => 'SIMDIRECT', 'customer' => ['phone_number' => '251900000000'],
            'metadata' => ['simulation' => 'offline'],
        ], 'sim-direct-unique-operation');
        $client->payments()->validateDirect(['reference' => 'CHSIMDIRECT', 'payment_method' => 'telebirr']);
        $verified = $client->payments()->verify('CHSIMDIRECT');
        $requests = $transport->requests;
        $body = json_decode((string) ($requests[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $report->add('direct.endpoint', str_ends_with($requests[0]['url'], '/v2/payments/direct') && str_ends_with($requests[1]['url'], '/v2/payments/direct/validate'));
        $report->add('direct.private_key', ($requests[0]['headers']['Authorization'] ?? null) === 'Bearer CHAPA_TEST_SYNTHETIC_PRIVATE');
        $report->add('direct.nested_customer', isset($body['customer']['phone_number']) && !isset($body['mobile']) && isset($body['metadata']));
        $report->add('direct.idempotency', isset($requests[0]['headers']['Idempotency-Key']) && !isset($requests[1]['headers']['Idempotency-Key']));
        $report->add('direct.verify_before_fulfillment', PaymentVerifier::matches($verified, 10000, 'ETB', 'SIMDIRECT'));
    }

    private function inline(DiagnosticReport $report): void
    {
        $transport = new ScenarioTransport([
            $this->response(['status' => 'PENDING', 'chapa_reference' => 'CHSIMINLINE']),
            $this->response(['status' => 'PENDING', 'chapa_reference' => 'CHSIMINLINE']),
            $this->response(['status' => 'SUCCESS', 'chapa_reference' => 'CHSIMINLINE', 'merchant_reference' => 'SIMINLINE', 'amount' => 10000, 'currency' => 'ETB']),
        ]);
        $client = new ChapaClient('CHAPA_TEST_SYNTHETIC_PRIVATE', 'CHAPA_TEST_SYNTHETIC_PUBLIC', transport: $transport);
        $client->payments()->inline([
            'payment_method' => 'telebirr', 'amount' => 10000, 'currency' => 'ETB',
            'merchant_reference' => 'SIMINLINE', 'mobile' => '251900000000', 'meta' => ['simulation' => 'offline'],
        ], 'sim-inline-unique-operation');
        $client->payments()->validateInline(['reference' => 'CHSIMINLINE', 'payment_method' => 'telebirr']);
        $verified = $client->payments()->verify('CHSIMINLINE');
        $requests = $transport->requests;
        $body = json_decode((string) ($requests[0]['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $report->add('inline.endpoint', str_ends_with($requests[0]['url'], '/v2/payments/inline') && str_ends_with($requests[1]['url'], '/v2/payments/inline/validate'));
        $report->add('inline.public_key', ($requests[0]['headers']['Authorization'] ?? null) === 'Bearer CHAPA_TEST_SYNTHETIC_PUBLIC' && ($requests[1]['headers']['Authorization'] ?? null) === 'Bearer CHAPA_TEST_SYNTHETIC_PUBLIC');
        $report->add('inline.top_level_mobile', isset($body['mobile']) && !isset($body['customer']) && isset($body['meta']));
        $report->add('inline.idempotency', isset($requests[0]['headers']['Idempotency-Key']) && !isset($requests[1]['headers']['Idempotency-Key']));
        $report->add('inline.verify_before_fulfillment', PaymentVerifier::matches($verified, 10000, 'ETB', 'SIMINLINE'));
    }

    private function states(DiagnosticReport $report): void
    {
        foreach (['PENDING', 'FAILED', 'CANCELLED', 'INCOMPLETE', 'BLOCKED', 'AUTH_NEEDED'] as $state) {
            $response = new ApiResponse(200, ['status' => 'success', 'data' => [
                'status' => $state, 'chapa_reference' => 'CHSIMSTATE',
                'merchant_reference' => 'SIMSTATE', 'amount' => 10000, 'currency' => 'ETB',
            ]]);
            $report->add('states.'.strtolower($state).'_not_fulfilled', !PaymentVerifier::matches($response, 10000, 'ETB', 'SIMSTATE'));
        }
        $ok = new ApiResponse(200, ['status' => 'success', 'data' => [
            'status' => 'SUCCESS', 'chapa_reference' => 'CHSIMSTATE',
            'merchant_reference' => 'SIMSTATE', 'amount' => 10000, 'currency' => 'ETB',
        ]]);
        $report->add('states.success_matches_all_dimensions', PaymentVerifier::matches($ok, 10000, 'ETB', 'SIMSTATE'));
        $report->add('states.amount_mismatch_rejected', !PaymentVerifier::matches($ok, 10001, 'ETB', 'SIMSTATE'));
        $report->add('states.currency_mismatch_rejected', !PaymentVerifier::matches($ok, 10000, 'USD', 'SIMSTATE'));
        $report->add('states.reference_mismatch_rejected', !PaymentVerifier::matches($ok, 10000, 'ETB', 'OTHER'));
    }

    private function webhooks(DiagnosticReport $report): void
    {
        $harness = new WebhookReplayHarness();
        $base = ['webhook_type' => 'payment', 'event' => 'payment.success', 'status' => 'success',
            'mode' => 'test', 'chapa_reference' => 'CHSIM01', 'merchant_reference' => 'SIMORDER',
            'amount' => '10000', 'currency' => 'ETB', 'updated_at' => '2026-11-13T10:00:00Z'];
        $report->add('webhooks.success_requires_reconciliation', $harness->receive($base) === 'accepted_for_reconciliation');
        $report->add('webhooks.raw_delivery_deduplication', $harness->receive($base) === 'duplicate');
        $report->add('webhooks.signature_tampering_rejected', $harness->receive($base, true) === 'rejected_signature_or_payload');
        $report->add('webhooks.mode_mismatch_rejected', $harness->receive(array_replace($base, ['mode' => 'live'])) === 'rejected_environment');
        $report->add('webhooks.older_failure_ignored', $harness->receive(array_replace($base, [
            'event' => 'payment.failed', 'status' => 'failed', 'updated_at' => '2026-11-13T09:59:59Z',
        ])) === 'ignored_stale');
        $report->add('webhooks.newer_failure_flagged', $harness->receive(array_replace($base, [
            'event' => 'payment.failed', 'status' => 'failed', 'updated_at' => '2026-11-13T10:00:01Z',
        ])) === 'review_ordering');
        $refund = ['webhook_type' => 'refund', 'event' => 'payment.partially_refunded',
            'status' => 'partially_refunded', 'mode' => 'test', 'chapa_reference' => 'CHSIM01',
            'amount' => '10000', 'refunded_amount' => '2000', 'currency' => 'ETB', 'updated_at' => '2026-11-13T10:00:02Z'];
        $report->add('webhooks.partial_refund_accepted', $harness->receive($refund) === 'accepted_for_reconciliation');
        $report->add('webhooks.distinct_partial_refunds_retained', $harness->receive(array_replace($refund, [
            'refunded_amount' => '4000', 'updated_at' => '2026-11-13T10:00:03Z',
        ])) === 'accepted_for_reconciliation');
        $payout = ['webhook_type' => 'payout', 'event' => 'payout.success', 'status' => 'success',
            'mode' => 'test', 'chapa_reference' => 'CHSIMPO', 'updated_at' => '2026-11-13T10:00:00Z'];
        $report->add('webhooks.payout_success_accepted', $harness->receive($payout) === 'accepted_for_reconciliation');
        $report->add('webhooks.payout_reversal_after_success', $harness->receive(array_replace($payout, [
            'event' => 'payout.reversed', 'status' => 'reversed', 'updated_at' => '2026-11-13T10:05:00Z',
        ])) === 'accepted_for_reconciliation');
        $report->add('webhooks.invalid_status_rejected', $harness->receive(array_replace($base, [
            'event' => 'payment.success', 'status' => 'failed',
        ])) === 'rejected_signature_or_payload');
    }

    /** @param array<string,mixed> $data */
    private function response(array $data): RawResponse
    {
        return new RawResponse(200, json_encode(['status' => 'success', 'data' => $data], JSON_THROW_ON_ERROR));
    }
}
