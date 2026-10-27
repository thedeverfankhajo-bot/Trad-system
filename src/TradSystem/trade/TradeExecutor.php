<?php

declare(strict_types=1);

namespace TradSystem\trade;

use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use RuntimeException;
use Throwable;
use TradSystem\util\InventorySnapshotCodec;
use TradSystem\util\ItemFingerprint;

final class TradeExecutor{
    public function __construct(
        private readonly TradeJournalStore $journal,
        private readonly TradePlayerDataSaver $dataSaver
    ){}

    public function commit(TradeSession $session, Player $partyOne, Player $partyTwo) : TradeResult{
        if($session->getState() !== TradeState::COMMITTING || !$session->bothConfirmed()){
            return TradeResult::failure('invalid-state');
        }
        $uuidOne = $partyOne->getUniqueId()->toString();
        $uuidTwo = $partyTwo->getUniqueId()->toString();
        if($uuidOne !== $session->partyOneUuid || $uuidTwo !== $session->partyTwoUuid){
            return TradeResult::failure('invalid-participants');
        }
        if($this->journal->isUnsafe() || $this->journal->hasPendingTransaction()){
            return TradeResult::failure('journal-unavailable');
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
            $prepared = $this->journal->prepare(
                $uuidOne,
                $uuidTwo,
                InventorySnapshotCodec::capture($inventoryOne),
                InventorySnapshotCodec::capture($inventoryTwo),
                InventorySnapshotCodec::capture($simulationOne),
                InventorySnapshotCodec::capture($simulationTwo)
            );
        }catch(Throwable $exception){
            return TradeResult::failure('journal-prepare-failed: ' . $exception->getMessage());
        }
        if(!$prepared){
            return TradeResult::failure('journal-unavailable');
        }

        try{
            $this->apply($inventoryOne, $planOne);
            if(!$this->contentsMatch($inventoryOne, $planOne) || !$this->contentsMatch($inventoryTwo, $beforeTwo)){
                throw new RuntimeException('Inventory changed while applying the first side of the transaction');
            }
            $this->apply($inventoryTwo, $planTwo);
            if(!$this->contentsMatch($inventoryOne, $planOne) || !$this->contentsMatch($inventoryTwo, $planTwo)){
                throw new RuntimeException('Post-commit inventory verification failed');
            }
        }catch(Throwable $exception){
            return $this->rollbackPreparedTransaction(
                $partyOne,
                $partyTwo,
                $beforeOne,
                $beforeTwo,
                'apply-failed: ' . $exception->getMessage()
            );
        }

        // COMMITTED is durable only after both after-states have been verified.
        // If the marker cannot be written and PREPARED is still authoritative,
        // rollback to the recorded before-state and save both players.
        $committed = false;
        try{
            $committed = $this->journal->markCommitted();
        }catch(Throwable){
            $committed = false;
        }
        $currentRecord = $this->journal->getRecord();
        if(!$committed && ($currentRecord['state'] ?? null) !== 'COMMITTED'){
            return $this->rollbackPreparedTransaction(
                $partyOne,
                $partyTwo,
                $beforeOne,
                $beforeTwo,
                'journal-commit-marker-failed'
            );
        }

        // Retain the COMMITTED journal until both player saves finish. If the
        // server stops before cleanup, reconnect recovery reapplies the after-state.
        $savedOne = $this->dataSaver->save($partyOne);
        $savedTwo = $this->dataSaver->save($partyTwo);
        if(!$savedOne || !$savedTwo){
            return TradeResult::criticalFailure('committed-player-save-failed');
        }
        if(!$this->journal->clearAfterCommittedSave()){
            return TradeResult::criticalFailure('committed-journal-cleanup-failed');
        }
        return TradeResult::success();
    }

    /**
     * @param array<int, Item> $beforeOne
     * @param array<int, Item> $beforeTwo
     */
    private function rollbackPreparedTransaction(
        Player $partyOne,
        Player $partyTwo,
        array $beforeOne,
        array $beforeTwo,
        string $reason
    ) : TradeResult{
        if(!$this->restoreBoth($partyOne->getInventory(), $partyTwo->getInventory(), $beforeOne, $beforeTwo)){
            return TradeResult::criticalFailure('rollback-failed: ' . $reason);
        }
        // PREPARED remains on disk until both restored inventories are saved.
        $savedOne = $this->dataSaver->save($partyOne);
        $savedTwo = $this->dataSaver->save($partyTwo);
        if(!$savedOne || !$savedTwo){
            return TradeResult::criticalFailure('rollback-player-save-failed: ' . $reason);
        }
        if(!$this->journal->clearAfterRollback()){
            return TradeResult::criticalFailure('rollback-journal-cleanup-failed: ' . $reason);
        }
        return TradeResult::failure($reason);
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
            throw new RuntimeException('Transaction plan does not cover every inventory slot');
        }
        for($slot = 0; $slot < $inventory->getSize(); ++$slot){
            if(!isset($contents[$slot])){
                throw new RuntimeException("Transaction plan is missing slot $slot");
            }
        }
        $inventory->setContents($contents);
    }

    /**
     * @param array<int, Item> $beforeOne
     * @param array<int, Item> $beforeTwo
     */
    private function restoreBoth(
        PlayerInventory $inventoryOne,
        PlayerInventory $inventoryTwo,
        array $beforeOne,
        array $beforeTwo
    ) : bool{
        $restoredOne = $this->restore($inventoryOne, $beforeOne);
        $restoredTwo = $this->restore($inventoryTwo, $beforeTwo);
        return $restoredOne && $restoredTwo &&
            $this->contentsMatchSafely($inventoryOne, $beforeOne) &&
            $this->contentsMatchSafely($inventoryTwo, $beforeTwo);
    }

    /** @param array<int, Item> $contents */
    private function restore(PlayerInventory $inventory, array $contents) : bool{
        try{
            $inventory->setContents($contents);
        }catch(Throwable){
            // Continue with slot-by-slot restoration and verify the result.
        }
        if(!$this->contentsMatchSafely($inventory, $contents)){
            foreach($contents as $slot => $item){
                try{
                    $inventory->setItem($slot, clone $item);
                }catch(Throwable){
                    // Final verification determines whether restoration succeeded.
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
