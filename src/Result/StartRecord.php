<?php
declare(strict_types=1);

namespace Packvium\Result;

final readonly class StartRecord
{
    public function __construct(
        public string $id,
        public bool $started,
        public bool $completed,
        public bool $truncated,
        public bool $selected = false,
        public bool $globalDeadlineReached = false,
    ) {}

    public function withSelected(bool $selected): self
    {
        return new self(
            $this->id,
            $this->started,
            $this->completed,
            $this->truncated,
            $selected,
            $this->globalDeadlineReached,
        );
    }

    public function withGlobalDeadline(bool $reached): self
    {
        return new self(
            $this->id,
            $this->started,
            $this->completed,
            $this->truncated,
            $this->selected,
            $reached,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'started' => $this->started,
            'completed' => $this->completed,
            'truncated' => $this->truncated,
            'selected' => $this->selected,
            'global_deadline_reached' => $this->globalDeadlineReached,
        ];
    }
}
