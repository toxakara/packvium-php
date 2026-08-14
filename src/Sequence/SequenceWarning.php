<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/** Non-fatal, localization-ready instruction warning. */
final class SequenceWarning
{
    /** @param array<string,string> $arguments */
    public function __construct(
        public readonly string $code,
        public readonly int $index,
        public readonly string $messageKey,
        public readonly array $arguments,
    ) {}

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
