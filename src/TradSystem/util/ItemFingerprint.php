<?php

declare(strict_types=1);

namespace TradSystem\util;

use pocketmine\item\Item;

/**
 * Compares item type, damage, compound NBT and (optionally) stack count using
 * PMMP's public exact-comparison API. Inputs are never mutated.
 */
final class ItemFingerprint{
    public static function matches(Item $current, Item $snapshot, bool $compareCount = true) : bool{
        if($current->isNull() || $snapshot->isNull()){
            return $current->isNull() && $snapshot->isNull() && (!$compareCount || $current->getCount() === $snapshot->getCount());
        }
        if(!$compareCount){
            // Item::equalsExact() also compares count. Normalize clones to one item
            // so the caller can request identity-and-metadata matching only.
            $current = clone $current;
            $snapshot = clone $snapshot;
            $current->setCount(1);
            $snapshot->setCount(1);
        }
        return $current->equalsExact($snapshot);
    }
}
