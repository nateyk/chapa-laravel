{{-- Copy to resources/views/admin/chapa-transfer-requests.blade.php.
     Only render behind your application's authenticated merchant-admin Gate.
     Integrate this minimal markup into your app's existing layout and design system. --}}
<section aria-labelledby="transfer-approval-heading">
    <h1 id="transfer-approval-heading">Transfer approvals</h1>
    <p>Review requested payouts before authorizing any money movement. A Chapa payout is not complete until verified.</p>

    @if (session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif

    <div style="overflow-x: auto">
        <table>
            <caption>Outstanding transfer requests</caption>
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Recipient</th>
                    <th scope="col">Bank / Account</th>
                    <th scope="col">State</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $request)
                    <tr>
                        <th scope="row">{{ $request['merchant_reference'] }}</th>
                        <td>{{ number_format($request['amount_cents'] / 100, 2) }} {{ $request['currency'] }}</td>
                        <td>{{ $request['account_name'] }}</td>
                        <td>{{ $request['bank_slug'] }} · {{ $request['account_number_masked'] }}</td>
                        <td>{{ $request['state'] }}</td>
                        <td>
                            @if ($request['state'] === 'requested')
                                <form method="POST" action="{{ route('admin.chapa-transfers.approve', $request['merchant_reference']) }}">
                                    @csrf
                                    <button type="submit" aria-label="Approve {{ $request['merchant_reference'] }}">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('admin.chapa-transfers.reject', $request['merchant_reference']) }}">
                                    @csrf
                                    <button type="submit" aria-label="Reject {{ $request['merchant_reference'] }}">Reject</button>
                                </form>
                            @elseif ($request['state'] === 'approved')
                                <form method="POST" action="{{ route('admin.chapa-transfers.dispatch', $request['merchant_reference']) }}">
                                    @csrf
                                    <button type="submit" aria-label="Submit approved {{ $request['merchant_reference'] }} to Chapa">Submit to Chapa</button>
                                </form>
                            @else
                                <span>Investigate; do not retry with a new idempotency key</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No transfer requests need review.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
