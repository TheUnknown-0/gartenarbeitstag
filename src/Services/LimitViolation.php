<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Wird geworfen, wenn eine Einschreibung an Limits scheitert.
 * `violations()` liefert die Details aus LimitCheck; `overridable()` sagt,
 * ob eine Orga-Kraft mit dem passenden Recht die Buchung erzwingen könnte.
 */
final class LimitViolation extends RuntimeException
{
    /** @param list<array{code: string, message: string, hard: bool}> $violations */
    public function __construct(private readonly array $violations)
    {
        parent::__construct(LimitCheck::messages($violations));
    }

    /** @return list<array{code: string, message: string, hard: bool}> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function overridable(): bool
    {
        return !LimitCheck::hasHard($this->violations);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->violations, 'code');
    }
}
