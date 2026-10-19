<?php

namespace App\Domain\Shared;

use App\Domain\Shared\Exceptions\InvalidTransition;

/**
 * State machine helpers for backed enums. Each enum lists its allowed next
 * states in next(); the database triggers enforce the same map, and a test
 * checks that both agree.
 */
trait HasTransitions
{
    /**
     * @return list<self>
     */
    abstract public function next(): array;

    abstract public function label(): string;

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /**
     * @throws InvalidTransition
     */
    public function assertCanTransitionTo(self $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw InvalidTransition::between($this, $target);
        }
    }

    public function isTerminal(): bool
    {
        return $this->next() === [];
    }
}
