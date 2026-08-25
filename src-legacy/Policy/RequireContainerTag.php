<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * An item carrying `itemTag` may only enter a container carrying `containerTag`.
 * Compiles to the container-eligibility constraint.
 */
final class RequireContainerTag implements RuleForm
{
    /**
     * @readonly
     * @var string
     */
    public $itemTag;
    /**
     * @readonly
     * @var string
     */
    public $containerTag;
    public function __construct(string $itemTag, string $containerTag)
    {
        $this->itemTag = $itemTag;
        $this->containerTag = $containerTag;
    }
}
