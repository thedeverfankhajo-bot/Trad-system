<?php

declare(strict_types=1);

namespace TradSystem\util;

use pocketmine\item\Item;

/**
 * Compares the complete public Item representation used by PMMP, including
 * damage and compound NBT. Counts are checked separately because Item::equals()
 * is intended for item identity rather than stack quantity.
 */
final class ItemFingerprint{
    public static function matches(Item $current, Item $snapshot, bool $compareCount = true) : bool{
        if($current->isNull() || $snapshot->isNull()){
            return $current->isNull() && $snapshot->isNull() && (!$compareCount || $current->getCount() === $snapshot->getCount());
        }
        if(!$current->equals($snapshot, true, true)){
            return false;
        }
        return !$compareCount || $current->getCount() === $snapshot->getCount();
    }
}
