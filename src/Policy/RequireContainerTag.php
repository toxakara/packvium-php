<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * An item carrying `itemTag` may only enter a container carrying `containerTag`.
 * Compiles to the container-eligibility constraint.
 */
final readonly class RequireContainerTag implements RuleForm
{
    public function __construct(public string $itemTag, public string $containerTag) {}
}
