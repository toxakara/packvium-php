<?php
declare(strict_types=1);

namespace Packvium\Result;

use InvalidArgumentException;

/**
 * One open, forward-compatible result axis.
 *
 * The code is deliberately a string rather than an enum so an older client can parse
 * and preserve a value introduced by a newer producer.
 */
final readonly class ResultFact
{
    /** @param array<string,mixed> $attributes */
    public function __construct(public string $code, public array $attributes = [])
    {
        if ($code === '') {
            throw new InvalidArgumentException('Result fact code must not be empty');
        }
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $code = $raw['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new InvalidArgumentException('Result fact requires a non-empty string code');
        }
        unset($raw['code']);
        return new self($code, $raw);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, ...$this->attributes];
    }
}
