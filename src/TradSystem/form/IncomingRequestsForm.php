<?php

declare(strict_types=1);

namespace TradSystem\form;

use pocketmine\player\Player;
use TradSystem\trade\TradeManager;

final class IncomingRequestsForm{
    public static function send(TradeManager $manager, Player $player) : void{
        $messages = $manager->getMessages();
        $requests = $manager->getIncomingRequestsFor($player);
        if($requests === []){
            $messages->send($player, 'ui-no-requests');
            MainForm::send($manager, $player);
            return;
        }
        $buttons = [];
        $actions = [];
        foreach($requests as $request){
            $buttons[] = $messages->get('ui-accept', ['player' => $request->senderName]);
            $actions[] = ['accept', $request->id];
            $buttons[] = $messages->get('ui-deny', ['player' => $request->senderName]);
            $actions[] = ['deny', $request->id];
        }
        $buttons[] = $messages->get('ui-back-menu');
        $actions[] = ['back', ''];

        $player->sendForm(new SimpleForm(
            $player->getUniqueId()->toString(),
            $messages->get('ui-incoming-title'),
            $messages->get('ui-incoming-content'),
            $buttons,
            static function(Player $responsePlayer, int $index) use ($manager, $actions) : void{
                [$action, $requestId] = $actions[$index];
                if($action === 'accept'){
                    $manager->acceptRequestById($responsePlayer, $requestId);
                }elseif($action === 'deny'){
                    $manager->denyRequestById($responsePlayer, $requestId);
                }else{
                    MainForm::send($manager, $responsePlayer);
                }
            }
        ));
    }
}
