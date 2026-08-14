<?php
declare(strict_types=1);
namespace Packvium\Policy;
/** No container may hold both tags. Compiles to the compatibility constraint. */
final readonly class SeparateTags implements RuleForm
{
    public function __construct(public string $tag, public string $fromTag) {}
}
