<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * At most `maxCount` items carrying the tag may share one container. Compiles to the
 * tag-count constraint.
 */
final class LimitTagPerContainer implements RuleForm
{
    /**
     * @readonly
     * @var string
     */
    public $tag;
    /**
     * @readonly
     * @var int
     */
    public $maxCount;
    public function __construct(string $tag, int $maxCount)
    {
        $this->tag = $tag;
        $this->maxCount = $maxCount;
    }
}
