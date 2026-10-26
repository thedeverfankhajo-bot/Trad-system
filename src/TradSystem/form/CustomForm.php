<?php

declare(strict_types=1);

namespace TradSystem\form;

use Closure;
use JsonSerializable;
use pocketmine\form\Form;
use pocketmine\player\Player;

/** A minimal custom form. Every value must still be validated by the server-side callback. */
final class CustomForm implements Form, JsonSerializable{
    /** @param list<array<string, mixed>> $content @param Closure(Player, array<mixed>): void $callback */
    public function __construct(
        private readonly string $ownerUuid,
        private readonly string $title,
        private readonly array $content,
        private readonly Closure $callback
    ){}

    public function jsonSerialize() : array{
        return [
            'type' => 'custom_form',
            'title' => $this->title,
            'content' => $this->content
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        if($player->getUniqueId()->toString() !== $this->ownerUuid || !is_array($data) || count($data) !== count($this->content)){
            return;
        }
        ($this->callback)($player, $data);
    }
}
