<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/** Non-fatal, localization-ready instruction warning. */
final class SequenceWarning
{
    /**
     * @readonly
     * @var string
     */
    public $code;
    /**
     * @readonly
     * @var int
     */
    public $index;
    /**
     * @readonly
     * @var string
     */
    public $messageKey;
    /**
     * @var array<string, string>
     * @readonly
     */
    public $arguments;
    /** @param array<string,string> $arguments */
    public function __construct(string $code, int $index, string $messageKey, array $arguments)
    {
        $this->code = $code;
        $this->index = $index;
        $this->messageKey = $messageKey;
        $this->arguments = $arguments;
    }

    /** @return array{code:string,index:int,message_key:string,arguments:array<string,string>} */
    public function toArray(): array
    {
        $arguments = $this->arguments;
        ksort($arguments);
        return [
            'code' => $this->code,
            'index' => $this->index,
            'message_key' => $this->messageKey,
            'arguments' => $arguments,
        ];
    }
}
