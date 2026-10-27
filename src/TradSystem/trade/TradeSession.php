<?php

declare(strict_types=1);

namespace TradSystem\trade;

use pocketmine\item\Item;

final class TradeSession{
    /** @var array<string, array<int, TradeOffer>> */
    private array $offers;
    /** @var array<string, bool> */
    private array $confirmed;
    private TradeState $state = TradeState::OFFERING;
    private int $revision = 0;

    public function __construct(
        public readonly string $id,
        public readonly string $partyOneUuid,
        public readonly string $partyTwoUuid,
        public readonly string $partyOneName,
        public readonly string $partyTwoName,
        public readonly int $expiresAt
    ){
        $this->offers = [
            $partyOneUuid => [],
            $partyTwoUuid => []
        ];
        $this->confirmed = [
            $partyOneUuid => false,
            $partyTwoUuid => false
        ];
    }

    public function getState() : TradeState{
        return $this->state;
    }

    public function getRevision() : int{
        return $this->revision;
    }

    public function isParticipant(string $uuid) : bool{
        return $uuid === $this->partyOneUuid || $uuid === $this->partyTwoUuid;
    }

    public function getOtherUuid(string $uuid) : ?string{
        if($uuid === $this->partyOneUuid){
            return $this->partyTwoUuid;
        }
        if($uuid === $this->partyTwoUuid){
            return $this->partyOneUuid;
        }
        return null;
    }

    public function getName(string $uuid) : string{
        return match($uuid){
            $this->partyOneUuid => $this->partyOneName,
            $this->partyTwoUuid => $this->partyTwoName,
            default => ''
        };
    }

    /** @return array<int, TradeOffer> */
    public function getOffersFor(string $uuid) : array{
        return $this->offers[$uuid] ?? [];
    }

    public function getOffer(string $uuid, int $slot) : ?TradeOffer{
        return $this->offers[$uuid][$slot] ?? null;
    }

    public function setOffer(string $uuid, int $slot, int $amount, Item $sourceSnapshot) : bool{
        if(!$this->isParticipant($uuid) || !in_array($this->state, [TradeState::OFFERING, TradeState::CONFIRMING], true) || $slot < 0 || $amount < 1){
            return false;
        }
        $this->offers[$uuid][$slot] = new TradeOffer($slot, $amount, $sourceSnapshot);
        ++$this->revision;
        $this->resetConfirmations();
        return true;
    }

    public function removeOffer(string $uuid, int $slot) : bool{
        if(!$this->isParticipant($uuid) || !in_array($this->state, [TradeState::OFFERING, TradeState::CONFIRMING], true) || !isset($this->offers[$uuid][$slot])){
            return false;
        }
        unset($this->offers[$uuid][$slot]);
        ++$this->revision;
        $this->resetConfirmations();
        return true;
    }

    public function confirm(string $uuid) : bool{
        if(!$this->isParticipant($uuid) || !in_array($this->state, [TradeState::OFFERING, TradeState::CONFIRMING], true)){
            return false;
        }
        $this->confirmed[$uuid] = true;
        $this->state = TradeState::CONFIRMING;
        return true;
    }

    public function isConfirmed(string $uuid) : bool{
        return $this->confirmed[$uuid] ?? false;
    }

    public function bothConfirmed() : bool{
        return $this->confirmed[$this->partyOneUuid] && $this->confirmed[$this->partyTwoUuid];
    }

    public function beginCommit() : bool{
        if($this->state !== TradeState::CONFIRMING || !$this->bothConfirmed()){
            return false;
        }
        $this->state = TradeState::COMMITTING;
        return true;
    }

    public function resetAfterValidationFailure() : void{
        if(in_array($this->state, [TradeState::CONFIRMING, TradeState::COMMITTING], true)){
            ++$this->revision;
            $this->resetConfirmations();
        }
    }

    public function markCompleted() : bool{
        if($this->state !== TradeState::COMMITTING){
            return false;
        }
        $this->state = TradeState::COMPLETED;
        return true;
    }

    /**
     * Marks an in-flight commit as failed after the executor has returned without success.
     * Ordinary cancellation must never interrupt inventory mutation mid-transaction.
     */
    public function abortCommit() : bool{
        if($this->state !== TradeState::COMMITTING){
            return false;
        }
        $this->state = TradeState::CANCELLED;
        return true;
    }

    public function cancel() : bool{
        if($this->state->isTerminal() || $this->state === TradeState::COMMITTING){
            return false;
        }
        $this->state = TradeState::CANCELLED;
        return true;
    }

    public function isExpired(int $now) : bool{
        return $now >= $this->expiresAt;
    }

    private function resetConfirmations() : void{
        $this->confirmed[$this->partyOneUuid] = false;
        $this->confirmed[$this->partyTwoUuid] = false;
        $this->state = TradeState::OFFERING;
    }
}
