<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Nateyk\Chapa\Laravel\Transfers\TransferApprovalGate;
use Symfony\Component\HttpFoundation\Response;

/** Register explicitly on a CSRF-exempt HTTPS callback URL; never automatic. */
final class ChapaTransferApprovalController
{
    public function __invoke(Request $request, TransferApprovalGate $gate): Response
    {
        // Chapa's legacy transfer approval contract specifies only HTTP 200/400.
        if ($gate->check($request->getContent(), $request->header('Chapa-Signature'))) {
            return new Response('', 200);
        }
        return new Response('', 400);
    }
}
