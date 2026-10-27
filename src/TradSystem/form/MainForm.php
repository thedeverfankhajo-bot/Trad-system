<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class MainForm{
    public static function send(TradeManager $manager, Player $player) : void{
        if($manager->isShuttingDown()){
            return;
        }
        $messages = $manager->getMessages();
        $incomingCount = count($manager->getIncomingRequestsFor($player));
        $buttons = [
            $messages->get('ui-start-trade'),
            $messages->get('ui-incoming', ['count' => $incomingCount]),
            $messages->get('ui-active-trade'),
            $messages->get('ui-help'),
            $messages->get('ui-close')
        ];
        $player->sendForm(new SimpleForm(
            $player->getUniqueId()->toString(),
            $messages->get('ui-main-title'),
            $messages->get('ui-main-content'),
            $buttons,
            static function(Player $responsePlayer, int $index) use ($manager) : void{
                switch($index){
                    case 0:
                        $manager->openPlayerSelectionForm($responsePlayer);
                        break;
                    case 1:
                        $manager->openIncomingRequestsForm($responsePlayer);
                        break;
                    case 2:
                        $manager->openTradeOfferForm($responsePlayer);
                        break;
                    case 3:
                        $manager->getMessages()->send($responsePlayer, 'trade-help');
                        self::send($manager, $responsePlayer);
                        break;
                    default:
                        // Close button intentionally has no side effect.
                        break;
                }
            }
        ));
    }
}
