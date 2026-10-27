<?php

declare(strict_types=1);

namespace TradSystem\util;

use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use RuntimeException;
use Throwable;

/**
 * Serializes complete main-inventory snapshots using PMMP's public item NBT API.
 * The encoding is versioned by TradeJournalStore; PHP object serialization is never used.
 */
final class InventorySnapshotCodec{

    /**
     * @return array{size: int, slots: array<int, string>}
     */
    public static function capture(Inventory $inventory) : array{
        $serializer = new LittleEndianNbtSerializer();
        $slots = [];
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            $item = $inventory->getItem($slot);
            if($item->isNull()){
                continue;
            }
            $bytes = $serializer->write(new TreeRoot($item->nbtSerialize($slot)));
            $slots[$slot] = base64_encode($bytes);
        }
        return [
            "size" => $inventory->getSize(),
            "slots" => $slots
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<int, Item>
     */
    public static function decode(array $snapshot, int $expectedSize) : array{
        if(!is_int($snapshot["size"] ?? null) || $snapshot["size"] !== $expectedSize || !is_array($snapshot["slots"] ?? null)){
            throw new RuntimeException("Inventory snapshot has an unsupported size or format");
        }

        $contents = [];
        for($slot = 0; $slot < $expectedSize; ++$slot){
            $contents[$slot] = VanillaItems::AIR();
        }

        $serializer = new LittleEndianNbtSerializer();
        foreach($snapshot["slots"] as $slot => $encoded){
            if(!is_int($slot) || $slot < 0 || $slot >= $expectedSize || !is_string($encoded)){
                throw new RuntimeException("Inventory snapshot contains an invalid slot entry");
            }
            $bytes = base64_decode($encoded, true);
            if($bytes === false || $bytes === ""){
                throw new RuntimeException("Inventory snapshot contains invalid encoded item data");
            }
            try{
                $item = Item::nbtDeserialize($serializer->read($bytes)->mustGetCompoundTag());
            }catch(Throwable $exception){
                throw new RuntimeException("Could not decode an item from the inventory journal", 0, $exception);
            }
            if($item->isNull()){
                throw new RuntimeException("Inventory snapshot contains a null item in a populated slot");
            }
            $contents[$slot] = $item;
        }
        return $contents;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function matches(Inventory $inventory, array $snapshot) : bool{
        try{
            $expected = self::decode($snapshot, $inventory->getSize());
            for($slot = 0; $slot < $inventory->getSize(); ++$slot){
                if(!ItemFingerprint::matches($inventory->getItem($slot), $expected[$slot], true)){
                    return false;
                }
            }
            return true;
        }catch(Throwable){
            return false;
        }
    }
}
