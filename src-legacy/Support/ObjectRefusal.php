<?php
declare(strict_types=1);
namespace Packvium\Support;

use Packvium\Serialization\InvalidRequestException;

/**
 * A model refused a value while being built on its own, before it is part of a request, so
 * there is no field to name; `Packer::pack` names the field for what it checks itself.
 *
 * Held to `packvium._request_error.ObjectRefusal`. The JSON path reports a refusal its rules
 * did not name as `invalid_value` with this detail, the form every engine shares, so
 * `ArrayCodec` turns one of these back into that.
 */
final class ObjectRefusal extends InvalidRequestException
{
    public function __construct(string $reason, string $detail)
    {
        parent::__construct($reason, '', $detail);
    }

    /**
     * The reason for a ratio outside [0, 1]: below and above are told apart, and anything
     * else outside the range (NaN) is still refused.
     */
    public static function ratio(float $value, string $detail): self
    {
        $reason = $value < 0 ? 'below_minimum' : ($value > 1 ? 'above_maximum' : 'invalid_value');
        return new self($reason, $detail);
    }

    /**
     * The text a model has always thrown, so a wrapper that quotes it (a commerce document,
     * a measure verdict, cross-engine conformance) reads exactly what it read before.
     */
    protected function composeMessage(): string
    {
        return $this->detail();
    }
}
