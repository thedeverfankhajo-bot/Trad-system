<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class PlayerSelectionForm{
    public static function send(TradeManager $manager, Player $player) : void{
        $messages = $manager->getMessages();
        $targets = $manager->getEligiblePlayers($player);
        if($targets === []){
            $messages->send($player, 'ui-no-players');
            MainForm::send($manager, $player);
            return;
        }
        $names = array_map(static fn(Player $target) : string => $target->getName(), $targets);
        $player->sendForm(new SimpleForm(
            $player->getUniqueId()->toString(),
            $messages->get('ui-players-title'),
            $messages->get('ui-players-content'),
            $names,
            static function(Player $responsePlayer, int $index) use ($manager, $names) : void{
                $manager->sendRequest($responsePlayer, $names[$index]);
            }
        ));
    }
}
