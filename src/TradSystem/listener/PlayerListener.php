<?php

declare(strict_types=1);

namespace TradSystem\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use TradSystem\trade\TradeManager;

final class PlayerListener implements Listener{
    public function __construct(private readonly TradeManager $manager){}

    /** @priority MONITOR */
    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        $this->manager->onQuit($event->getPlayer());
    }
}
