<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class TradeOfferForm{
    public static function send(TradeManager $manager, Player $player, string $sessionId) : void{
        if($manager->isShuttingDown()){
            return;
        }
        $session = $manager->getSessionFor($player, $sessionId);
        if($session === null){
            $manager->getMessages()->send($player, 'trade-expired');
            MainForm::send($manager, $player);
            return;
        }
        $messages = $manager->getMessages();
        $partnerUuid = $session->getOtherUuid($player->getUniqueId()->toString());
        if($partnerUuid === null){
            MainForm::send($manager, $player);
            return;
        }
        $content = $messages->get('ui-offer-content', [
            'partner' => $session->getName($partnerUuid),
            'own_offer' => $manager->formatOffer($session, $player->getUniqueId()->toString()),
            'partner_offer' => $manager->formatOffer($session, $partnerUuid)
        ]);
        $buttons = [
            $messages->get('ui-add-item'),
            $messages->get('ui-remove-item'),
            $messages->get('ui-review-confirm'),
            $messages->get('ui-cancel-trade'),
            $messages->get('ui-back-menu')
        ];
        $player->sendForm(new SimpleForm(
            $player->getUniqueId()->toString(),
            $messages->get('ui-offer-title'),
            $content,
            $buttons,
            static function(Player $responsePlayer, int $index) use ($manager, $sessionId) : void{
                switch($index){
                    case 0:
                        $manager->openSlotSelectionForm($responsePlayer, $sessionId);
                        break;
                    case 1:
                        $manager->openRemoveOfferForm($responsePlayer, $sessionId);
                        break;
                    case 2:
                        $manager->openConfirmationForm($responsePlayer, $sessionId);
                        break;
                    case 3:
                        $manager->cancelTrade($responsePlayer, $sessionId);
                        break;
                    default:
                        MainForm::send($manager, $responsePlayer);
                        break;
                }
            }
        ));
    }
}
