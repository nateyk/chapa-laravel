<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Contracts;

/** An immutable, serializable request body; raw array payloads remain supported. */
interface RequestPayload
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
