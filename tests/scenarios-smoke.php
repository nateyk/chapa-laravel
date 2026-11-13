<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Nateyk\Chapa\Cli\OfflineScenarios;
use Nateyk\Chapa\Cli\WebhookReplayHarness;
use Nateyk\Chapa\Webhooks\WebhookEvent;
use Nateyk\Chapa\Webhooks\WebhookStateGuard;

$asserts = 0;
$check = static function (bool $pass, string $id) use (&$asserts): void {
    ++$asserts;
    if (!$pass) { throw new RuntimeException('Offline scenario failed: '.$id); }
};
foreach (['direct','inline','states','webhooks','all'] as $scenario) {
    $report = (new OfflineScenarios())->run($scenario);
    $data = json_decode($report->toJson(), true, flags: JSON_THROW_ON_ERROR);
    $check($report->successful() && $data['summary']['pass'] > 0, $scenario.' pass');
    $check($data['network'] === 'none', $scenario.' no network');
    $check(!str_contains($report->toJson(), 'CHAPA_TEST_SYNTHETIC_PRIVATE'), $scenario.' secret redaction');
    $check(!str_contains($report->toJson(), '251900000000'), $scenario.' phone redaction');
}
$event = WebhookEvent::fromArray(['webhook_type'=>'payout','event'=>'payout.reversed','status'=>'reversed']);
$check($event->status === 'reversed', 'payout reversal recognized');
$old = WebhookEvent::fromArray(['webhook_type'=>'payment','event'=>'payment.success','status'=>'success','chapa_reference'=>'CH1','updated_at'=>'2026-11-13T10:00:00Z']);
$late = WebhookEvent::fromArray(['webhook_type'=>'payment','event'=>'payment.failed','status'=>'failed','chapa_reference'=>'CH1','updated_at'=>'2026-11-13T09:00:00Z']);
$check(WebhookStateGuard::decide($late,$old) === WebhookStateGuard::IGNORE_STALE,'out-of-order rejection');
try { WebhookEvent::fromArray(['webhook_type'=>'payment','event'=>'payment.success','status'=>'failed']); $check(false,'invalid status'); }
catch (UnexpectedValueException) { $check(true,'invalid status rejected'); }
$h = new WebhookReplayHarness();
$sample = ['webhook_type'=>'payment','event'=>'payment.success','status'=>'success','mode'=>'test','chapa_reference'=>'CH1'];
$check($h->receive($sample) === 'accepted_for_reconciliation','signed first delivery');
$check($h->receive($sample) === 'duplicate','repeated delivery');
$check($h->receive($sample,true) === 'rejected_signature_or_payload','tampered bytes');
printf("OK: %d offline scenario assertions passed\n", $asserts);
