<?php

declare(strict_types=1);

namespace pocketmine\item;

/**
 * Tiny test-only stand-in for the Item methods used by TradeOffer.
 * This does not model PMMP NBT, serialization, equality, or inventory behavior.
 */
final class Item{
    public function __construct(
        private int $count = 1,
        private string $name = 'Test Item'
    ){}

    public function getCount() : int{
        return $this->count;
    }

    public function setCount(int $count) : void{
        $this->count = $count;
    }

    public function getName() : string{
        return $this->name;
    }
}
