<?php

declare(strict_types=1);

namespace TradSystem\trade;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDataSaveEvent;
use pocketmine\player\Player;
use Throwable;

/**
 * Checks the public player-data save event around an explicit Player::save() call.
 *
 * PocketMine's player-data provider may report storage failures by logging them
 * internally. This listener detects cancelled/missing save events, not arbitrary
 * I/O failures hidden by the provider implementation.
 */
final class TradePlayerDataSaver implements Listener{
    /** @var array<string, array<int, bool|null>> */
    private array $saveResults = [];

    public function save(Player $player) : bool{
        $uuid = $player->getUniqueId()->toString();
        $this->saveResults[$uuid][] = null;
        $index = array_key_last($this->saveResults[$uuid]);

        try{
            $player->save();
        }catch(Throwable){
            unset($this->saveResults[$uuid][$index]);
            if($this->saveResults[$uuid] === []){
                unset($this->saveResults[$uuid]);
            }
            return false;
        }

        $saved = $this->saveResults[$uuid][$index] ?? null;
        unset($this->saveResults[$uuid][$index]);
        if($this->saveResults[$uuid] === []){
            unset($this->saveResults[$uuid]);
        }
        return $saved === true;
    }

    /** @priority MONITOR */
    public function onPlayerDataSave(PlayerDataSaveEvent $event) : void{
        $player = $event->getPlayer();
        if($player === null){
            return;
        }
        $uuid = $player->getUniqueId()->toString();
        if(!isset($this->saveResults[$uuid]) || $this->saveResults[$uuid] === []){
            return;
        }
        $index = array_key_last($this->saveResults[$uuid]);
        $this->saveResults[$uuid][$index] = !$event->isCancelled();
    }
}
