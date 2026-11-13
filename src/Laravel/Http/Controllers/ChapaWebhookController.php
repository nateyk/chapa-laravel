<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nateyk\Chapa\Exceptions\ChapaException;
use Nateyk\Chapa\Laravel\Webhooks\WebhookInbox;

/** Explicit opt-in route; stores a verified event before responding. Never fulfills orders. */
final class ChapaWebhookController
{
    public function __invoke(Request $request, WebhookInbox $inbox): JsonResponse
    {
        try {
            $received = $inbox->accept($request->getContent(), $request->header('x-chapa-signature'));
        } catch (\LengthException) {
            return response()->json(['message' => 'Payload too large.'], 413);
        } catch (ChapaException) {
            return response()->json(['message' => 'Invalid webhook signature or payload.'], 401);
        } catch (\UnexpectedValueException) {
            return response()->json(['message' => 'Invalid webhook event.'], 422);
        }
        // HTTP 200 acknowledges durable receipt, NOT business fulfillment.
        return response()->json(['received' => true, 'duplicate' => !$received->newDelivery], 200);
    }
}
