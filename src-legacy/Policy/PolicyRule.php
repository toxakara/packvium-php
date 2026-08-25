<?php
declare(strict_types=1);
namespace Packvium\Policy;
/** One prohibition, carrying the identity, dating and priority the tag fields cannot express. */
final class PolicyRule
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @readonly
     * @var int
     */
    public $version;
    /**
     * @readonly
     * @var int
     */
    public $effectiveAt;
    /**
     * @readonly
     * @var int
     */
    public $priority;
    /**
     * @readonly
     * @var \Packvium\Policy\ShipmentContext
     */
    public $appliesTo;
    /**
     * @readonly
     * @var \Packvium\Policy\RuleForm
     */
    public $form;
    /** Wire name => [constructor keys in order]. Exactly one may appear on a rule: a rule
     *  naming two forms would have no single meaning for a citation. */
    public const FORMS = [
        'separate_tags' => ['tag', 'from_tag'],
        'require_container_tag' => ['item_tag', 'container_tag'],
        'limit_tag_per_container' => ['tag', 'max'],
    ];

    public function __construct(string $id, int $version, int $effectiveAt, int $priority, ShipmentContext $appliesTo, RuleForm $form)
    {
        $this->id = $id;
        $this->version = $version;
        $this->effectiveAt = $effectiveAt;
        $this->priority = $priority;
        $this->appliesTo = $appliesTo;
        $this->form = $form;
    }

    public function citation(): string
    {
        return "{$this->id}@{$this->version}";
    }

    /**
     * @param mixed $raw
     */
    public static function fromArray($raw, int $index): self
    {
        $where = "policy.rules[{$index}]";
        if (!is_array($raw)) throw new PolicyException("{$where} must be an object");
        $named = array_values(array_filter(array_keys(self::FORMS), static function (string $f) use ($raw): bool {
            return array_key_exists($f, $raw);
        }));
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

    /**
     * @param mixed $value
     */
    public static function integer($value, string $where, int $minimum): int
    {
        // `is_int` already excludes PHP's bool, but a JSON `true` decodes to bool and
        // would otherwise be cast to 1 by an int type juggle.
        if (!is_int($value) || $value < $minimum) {
            throw new PolicyException("{$where} must be an integer >= {$minimum}");
        }
        return $value;
    }

    /**
     * @param mixed $raw
     */
    private static function form(string $name, $raw, string $where): RuleForm
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
        switch ($name) {
            case 'separate_tags':
                return new SeparateTags($values[0], $values[1]);
            case 'require_container_tag':
                return new RequireContainerTag($values[0], $values[1]);
            case 'limit_tag_per_container':
                return new LimitTagPerContainer($values[0], $values[1]);
        }
    }
}
