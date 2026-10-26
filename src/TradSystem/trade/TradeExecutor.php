<?php

declare(strict_types=1);

namespace TradSystem\trade;

use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use Throwable;
use TradSystem\util\ItemFingerprint;

final class TradeExecutor{
    public function commit(TradeSession $session, Player $partyOne, Player $partyTwo) : TradeResult{
        if($session->getState() !== TradeState::COMMITTING || !$session->bothConfirmed()){
            return TradeResult::failure('invalid-state');
        }
        $uuidOne = $partyOne->getUniqueId()->toString();
        $uuidTwo = $partyTwo->getUniqueId()->toString();
        if($uuidOne !== $session->partyOneUuid || $uuidTwo !== $session->partyTwoUuid){
            return TradeResult::failure('invalid-participants');
        }
        $inventoryOne = $partyOne->getInventory();
        $inventoryTwo = $partyTwo->getInventory();
        $offersOne = $session->getOffersFor($uuidOne);
        $offersTwo = $session->getOffersFor($uuidTwo);
        if(!$this->validateOffers($inventoryOne, $offersOne) || !$this->validateOffers($inventoryTwo, $offersTwo)){
            return TradeResult::failure('inventory-changed');
        }
        $simulationOne = $this->simulate($inventoryOne, $offersOne, $offersTwo);
        if($simulationOne === null){
            return TradeResult::failure('no-space');
        }
        $simulationTwo = $this->simulate($inventoryTwo, $offersTwo, $offersOne);
        if($simulationTwo === null){
            return TradeResult::failure('no-space');
        }
        $beforeOne = $this->snapshot($inventoryOne);
        $beforeTwo = $this->snapshot($inventoryTwo);
        $planOne = $this->snapshot($simulationOne);
        $planTwo = $this->snapshot($simulationTwo);
        try{
            $this->apply($inventoryOne, $planOne);
            if(!$this->contentsMatch($inventoryOne, $planOne) || !$this->contentsMatch($inventoryTwo, $beforeTwo)){
                throw new \RuntimeException('Inventory changed while applying the first side of the transaction');
            }
            $this->apply($inventoryTwo, $planTwo);
            if(!$this->contentsMatch($inventoryOne, $planOne) || !$this->contentsMatch($inventoryTwo, $planTwo)){
                throw new \RuntimeException('Post-commit inventory verification failed');
            }
        }catch(Throwable $exception){
            $restoredOne = $this->restore($inventoryOne, $beforeOne);
            $restoredTwo = $this->restore($inventoryTwo, $beforeTwo);
            if(!$restoredOne || !$restoredTwo){
                return TradeResult::criticalFailure('rollback-failed: ' . $exception->getMessage());
            }
            return TradeResult::failure('apply-failed: ' . $exception->getMessage());
        }
        return TradeResult::success();
    }

    /** @param array<int, TradeOffer> $offers */
    private function validateOffers(PlayerInventory $inventory, array $offers) : bool{
        $usedSlots = [];
        foreach($offers as $key => $offer){
            $slot = $offer->sourceSlot;
            if($key !== $slot || isset($usedSlots[$slot]) || !$inventory->slotExists($slot)){
                return false;
            }
            $usedSlots[$slot] = true;
            $current = $inventory->getItem($slot);
            if($current->isNull() || $offer->offeredCount < 1 || $offer->offeredCount > $current->getCount()){
                return false;
            }
            if(!ItemFingerprint::matches($current, $offer->getSourceSnapshot(), true)){
                return false;
            }
        }
        return true;
    }

    /** @param array<int, TradeOffer> $outgoing @param array<int, TradeOffer> $incoming */
    private function simulate(PlayerInventory $source, array $outgoing, array $incoming) : ?SimpleInventory{
        $simulation = new SimpleInventory($source->getSize());
        $simulation->setMaxStackSize($source->getMaxStackSize());
        for($slot = 0; $slot < $source->getSize(); ++$slot){
            $simulation->setItem($slot, clone $source->getItem($slot));
        }
        foreach($outgoing as $offer){
            $current = $simulation->getItem($offer->sourceSlot);
            $remaining = $current->getCount() - $offer->offeredCount;
            if($remaining < 0){
                return null;
            }
            if($remaining === 0){
                $simulation->clear($offer->sourceSlot);
            }else{
                $current->setCount($remaining);
                $simulation->setItem($offer->sourceSlot, $current);
            }
        }
        foreach($incoming as $offer){
            if($simulation->addItem($offer->getTransferItem()) !== []){
                return null;
            }
        }
        return $simulation;
    }

    /** @return array<int, Item> */
    private function snapshot(PlayerInventory|SimpleInventory $inventory) : array{
        $snapshot = [];
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            $snapshot[$slot] = clone $inventory->getItem($slot);
        }
        return $snapshot;
    }

    /** @param array<int, Item> $contents */
    private function apply(PlayerInventory $inventory, array $contents) : void{
        if(count($contents) !== $inventory->getSize()){
            throw new \RuntimeException('Transaction plan does not cover every inventory slot');
        }
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            if(!isset($contents[$slot])){
                throw new \RuntimeException("Transaction plan is missing slot $slot");
            }
        }

        // Apply a complete, pre-validated snapshot in one public inventory operation.
        $inventory->setContents($contents);
    }

    /** @param array<int, Item> $contents */
    private function restore(PlayerInventory $inventory, array $contents) : bool{
        // First try a whole-inventory restore. If a listener or implementation throws,
        // fall back to slot-by-slot restoration and verify the result either way.
        try{
            $inventory->setContents($contents);
        }catch(Throwable){
            // Continue with the recovery path below.
        }

        if(!$this->contentsMatchSafely($inventory, $contents)){
            foreach($contents as $slot => $item){
                try{
                    $inventory->setItem($slot, clone $item);
                }catch(Throwable){
                    // Keep attempting other slots; final verification determines safety.
                }
            }
        }
        return $this->contentsMatchSafely($inventory, $contents);
    }

    /** @param array<int, Item> $expected */
    private function contentsMatchSafely(PlayerInventory $inventory, array $expected) : bool{
        try{
            return $this->contentsMatch($inventory, $expected);
        }catch(Throwable){
            return false;
        }
    }

    /** @param array<int, Item> $expected */
    private function contentsMatch(PlayerInventory $inventory, array $expected) : bool{
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            if(!isset($expected[$slot]) || !ItemFingerprint::matches($inventory->getItem($slot), $expected[$slot], true)){
                return false;
            }
        }
        return true;
    }
}
