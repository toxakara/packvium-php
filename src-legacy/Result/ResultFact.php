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
final class ResultFact
{
    /**
     * @readonly
     * @var string
     */
    public $code;
    /**
     * @var array<string, mixed>
     * @readonly
     */
    public $attributes = [];
    /** @param array<string,mixed> $attributes */
    public function __construct(string $code, array $attributes = [])
    {
        $this->code = $code;
        $this->attributes = $attributes;
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
        return array_merge(['code' => $this->code], $this->attributes);
    }
}
