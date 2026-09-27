<?php
declare(strict_types=1);
namespace Packvium\Validation;
use Packvium\Serialization\InvalidRequestException;
/**
 * The request's fixed placements are malformed (`reason()` `malformed`, `field()` the bad
 * value) or cannot all hold (`cannot_hold`, `field()` `/fixed_placements`), so no search is
 * attempted. A request error like any other, so catching `InvalidRequestException` catches it;
 * its message keeps the `invalid_fixed_placement: <detail>` spelling the revisions corpus pins.
 */
final class FixedPlacementException extends InvalidRequestException
{
    public function __construct(string $detail, string $reason = 'cannot_hold', string $field = '/fixed_placements')
    {
        parent::__construct($reason, $field, $detail);
    }

    public function errorCode(): string
    {
        return 'invalid_fixed_placement';
    }

    protected function composeMessage(): string
    {
        return "{$this->errorCode()}: {$this->detail()}";
    }
}
