<?php

/**
 * ILLUSTRATIVE APPLICATION ROUTES, NOT loaded by the package.
 * Copy/adapt inside an existing Laravel 11/12/13 project's routes/web.php.
 * Define the Gate 'manage-chapa-transfers' against YOUR application's actual
 * admin role and tenant/merchant scope. Do not put these routes in routes/api.php
 * without equivalent authentication and CSRF protection.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\Laravel\Transfers\TransferApprovalManager;

// Example: Gate::define('manage-chapa-transfers', fn (User $user) =>
//     $user->hasVerifiedMerchantAdminRole($merchantId));

Route::middleware(['auth', 'verified', 'can:manage-chapa-transfers'])
    ->prefix('admin/chapa-transfers')->group(function (): void {
        Route::get('/', static function (Request $request) {
            Gate::authorize('manage-chapa-transfers');
            // Always apply a host application tenant/merchant filter, matching
            // the SDK's configured integration, BEFORE showing any records.
            $manager = app(TransferApprovalManager::class);
            // Restricted and masked review data; never render full account numbers.
            $requests = $manager->reviewQueue(50);
            return view('admin.chapa-transfer-requests', compact('requests'));
        })->name('admin.chapa-transfers.index');

        Route::post('/{reference}/approve', static function (Request $request, string $reference, TransferApprovalManager $manager) {
            Gate::authorize('manage-chapa-transfers');
            abort_unless($manager->approve($reference, 'admin:'.$request->user()->getAuthIdentifier()), 409);
            return back()->with('status', 'Approved; transfer is not yet submitted to Chapa.');
        })->name('admin.chapa-transfers.approve');

        Route::post('/{reference}/reject', static function (Request $request, string $reference, TransferApprovalManager $manager) {
            Gate::authorize('manage-chapa-transfers');
            abort_unless($manager->reject($reference, 'admin:'.$request->user()->getAuthIdentifier()), 409);
            return back()->with('status', 'Transfer request rejected.');
        })->name('admin.chapa-transfers.reject');

        Route::post('/{reference}/dispatch', static function (string $reference, TransferApprovalManager $manager) {
            Gate::authorize('manage-chapa-transfers');
            // Prefer dispatching a protected queue job rather than blocking the
            // web request; manager enforces one POST per approved reference.
            $created = $manager->dispatchApproved($reference);
            return back()->with('status', 'Chapa initiated transfer '.$created->chapaReference.'; await verification.');
        })->name('admin.chapa-transfers.dispatch');
    });
