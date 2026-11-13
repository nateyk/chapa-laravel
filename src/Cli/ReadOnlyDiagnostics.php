<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Exceptions\ApiException;
use Nateyk\Chapa\Http\ApiResponse;

/** Read-only Chapa TEST API checks, with no raw response or credential logging. */
final class ReadOnlyDiagnostics
{
    public function run(ChapaClient $client, bool $publicKeyPresent): DiagnosticReport
    {
        $report = new DiagnosticReport('chapa-test-api', 'connectivity');
        $this->tryOperation($report, 'private.payment_methods', static fn (): ApiResponse => $client->payments()->methods());
        $this->tryOperation($report, 'private.etb_balance', static fn (): ApiResponse => $client->balances()->get('ETB'));
        if ($publicKeyPresent) {
            $this->tryOperation($report, 'public.html_checkout', static fn (): ApiResponse => $client->payments()->htmlStatus());
        } else {
            $report->skip('public.html_checkout', 'No test public key provided.');
        }
        return $report;
    }

    private function tryOperation(DiagnosticReport $report, string $id, callable $operation): void
    {
        try {
            $response = $operation();
            $report->add($id, $response->operationSucceeded(), 'HTTP '.$response->statusCode);
        } catch (ApiException $exception) {
            $report->add($id, false, 'HTTP '.$exception->statusCode);
        } catch (\Throwable) {
            // API bodies, customer objects, headers and raw exception text are not included.
            $report->add($id, false, 'Network or response validation error.');
        }
    }
}
