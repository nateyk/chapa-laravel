<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/** @method static \Nateyk\Chapa\Resources\Payments payments() */
/** @method static \Nateyk\Chapa\Resources\Payouts payouts() */
/** @method static \Nateyk\Chapa\Resources\Refunds refunds() */
/** @method static \Nateyk\Chapa\Resources\Subaccounts subaccounts() */
/** @method static \Nateyk\Chapa\Resources\Balances balances() */
final class Chapa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'chapa';
    }
}
