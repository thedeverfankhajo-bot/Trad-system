<?php

declare(strict_types=1);

namespace TradSystem\trade;

use pocketmine\item\Item;

final class TradeOffer{
    private Item $sourceSnapshot;

    public function __construct(
        public readonly int $sourceSlot,
        public readonly int $offeredCount,
        Item $sourceSnapshot
    ){
        $this->sourceSnapshot = clone $sourceSnapshot;
    }

    public function getSourceSnapshot() : Item{
        return clone $this->sourceSnapshot;
    }

    public function getTransferItem() : Item{
        $item = clone $this->sourceSnapshot;
        $item->setCount($this->offeredCount);
        return $item;
    }

    public function getItemName() : string{
        return $this->sourceSnapshot->getName();
    }

    public function getSourceCount() : int{
        return $this->sourceSnapshot->getCount();
    }
}
