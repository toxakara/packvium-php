<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * At most `maxCount` items carrying the tag may share one container. Compiles to the
 * tag-count constraint.
 */
final readonly class LimitTagPerContainer implements RuleForm
{
    public function __construct(public string $tag, public int $maxCount) {}
}
