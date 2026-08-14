<?php
declare(strict_types=1);
namespace Packvium\Policy;
/** One prohibition, carrying the identity, dating and priority the tag fields cannot express. */
final readonly class PolicyRule
{
    /** Wire name => [constructor keys in order]. Exactly one may appear on a rule: a rule
     *  naming two forms would have no single meaning for a citation. */
    public const FORMS = [
        'separate_tags' => ['tag', 'from_tag'],
        'require_container_tag' => ['item_tag', 'container_tag'],
        'limit_tag_per_container' => ['tag', 'max'],
    ];

    public function __construct(
        public string $id,
        public int $version,
        public int $effectiveAt,
        public int $priority,
        public ShipmentContext $appliesTo,
        public RuleForm $form,
    ) {}

    public function citation(): string
    {
        return "{$this->id}@{$this->version}";
    }

    public static function fromArray(mixed $raw, int $index): self
    {
        $where = "policy.rules[{$index}]";
        if (!is_array($raw)) throw new PolicyException("{$where} must be an object");
        $named = array_values(array_filter(array_keys(self::FORMS), static fn(string $f): bool => array_key_exists($f, $raw)));
        if (count($named) !== 1) {
            $forms = array_keys(self::FORMS);
            sort($forms);
            throw new PolicyException(
                "{$where} must name exactly one rule form (" . implode(', ', $forms) . '), not ' . count($named)
            );
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || $id === '') throw new PolicyException("{$where}.id must be a non-empty string");
        return new self(
            $id,
            self::integer($raw['version'] ?? null, "{$where}.version", 1),
            self::integer($raw['effective_at'] ?? null, "{$where}.effective_at", 0),
            self::integer($raw['priority'] ?? null, "{$where}.priority", 0),
            ShipmentContext::fromArray($raw['applies_to'] ?? null, "{$where}.applies_to"),
            self::form($named[0], $raw[$named[0]], "{$where}.{$named[0]}"),
        );
    }

    public static function integer(mixed $value, string $where, int $minimum): int
    {
        // `is_int` already excludes PHP's bool, but a JSON `true` decodes to bool and
        // would otherwise be cast to 1 by an int type juggle.
        if (!is_int($value) || $value < $minimum) {
            throw new PolicyException("{$where} must be an integer >= {$minimum}");
        }
        return $value;
    }

    private static function form(string $name, mixed $raw, string $where): RuleForm
    {
        $keys = self::FORMS[$name];
        if (!is_array($raw)) throw new PolicyException("{$where} must be an object");
        $unknown = array_diff(array_keys($raw), $keys);
        sort($unknown);
        if ($unknown !== []) throw new PolicyException("{$where} has unknown keys: " . implode(', ', $unknown));
        $missing = array_values(array_diff($keys, array_keys($raw)));
        if ($missing !== []) throw new PolicyException("{$where} is missing " . implode(', ', $missing));
        $values = [];
        foreach ($keys as $key) {
            $value = $raw[$key];
            if ($key === 'max') {
                $values[] = self::integer($value, "{$where}.max", 0);
            } elseif (!is_string($value) || $value === '') {
                throw new PolicyException("{$where}.{$key} must be a non-empty string");
            } else {
                $values[] = $value;
            }
        }
        return match ($name) {
            'separate_tags' => new SeparateTags($values[0], $values[1]),
            'require_container_tag' => new RequireContainerTag($values[0], $values[1]),
            'limit_tag_per_container' => new LimitTagPerContainer($values[0], $values[1]),
        };
    }
}
