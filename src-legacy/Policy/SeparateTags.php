<?php
declare(strict_types=1);
namespace Packvium\Policy;
/** No container may hold both tags. Compiles to the compatibility constraint. */
final class SeparateTags implements RuleForm
{
    /**
     * @readonly
     * @var string
     */
    public $tag;
    /**
     * @readonly
     * @var string
     */
    public $fromTag;
    public function __construct(string $tag, string $fromTag)
    {
        $this->tag = $tag;
        $this->fromTag = $fromTag;
    }
}
