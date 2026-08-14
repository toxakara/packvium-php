<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * Declared facts about the shipment, or the absence of one.
 *
 * A fact nobody declared is not a wildcard: a rule naming it simply never participates,
 * so a rule written for one customer cannot silently apply to a shipment that named no
 * customer at all.
 */
final readonly class ShipmentContext
{
    /** Facts a rule may select on. Properties of the shipment rather than of any item or
     *  container, which is why the request had nowhere to put them before. */
    public const FACTS = ['facility', 'customer', 'carrier', 'service'];

    public function __construct(
        public ?string $facility = null,
        public ?string $customer = null,
        public ?string $carrier = null,
        public ?string $service = null,
    ) {}

    public static function fromArray(mixed $raw, string $where): self
    {
        if ($raw === null) return new self();
        if (!is_array($raw)) throw new PolicyException("{$where} must be an object");
        $unknown = array_diff(array_keys($raw), self::FACTS);
        sort($unknown);
        if ($unknown !== []) {
            throw new PolicyException("{$where} names unknown shipment facts: " . implode(', ', $unknown));
        }
        foreach ($raw as $name => $value) {
            if (!is_string($value) || $value === '') {
                throw new PolicyException("{$where}.{$name} must be a non-empty string");
            }
        }
        return new self($raw['facility'] ?? null, $raw['customer'] ?? null, $raw['carrier'] ?? null, $raw['service'] ?? null);
    }

    /** Whether every fact this selector names equals the shipment's own. */
    public function satisfiedBy(self $shipment): bool
    {
        foreach (self::FACTS as $fact) {
            if ($this->{$fact} !== null && $this->{$fact} !== $shipment->{$fact}) return false;
        }
        return true;
    }
}
