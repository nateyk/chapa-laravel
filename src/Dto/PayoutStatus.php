<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

/** Documented Chapa API v2 payout states. An action-needed state is not an approval URL. */
enum PayoutStatus: string
{
    case PENDING = 'PENDING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case BLOCKED = 'BLOCKED';
    case AUTH_NEEDED = 'AUTH_NEEDED';
    case OTP_NEEDED = 'OTP_NEEDED';
    case REVERSED = 'REVERSED';

    public function requiresAction(): bool
    {
        return $this === self::AUTH_NEEDED || $this === self::OTP_NEEDED;
    }

    public function delivered(): bool
    {
        return $this === self::SUCCESS;
    }
}
