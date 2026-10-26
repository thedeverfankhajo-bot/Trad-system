<?php

declare(strict_types=1);

namespace TradSystem\form;

use Closure;
use JsonSerializable;
use pocketmine\form\Form;
use pocketmine\player\Player;

/** A dependency-free Bedrock simple form backed by PMMP's public Form interface. */
final class SimpleForm implements Form, JsonSerializable{
    /** @param list<string> $buttons @param Closure(Player, int): void $callback */
    public function __construct(
        private readonly string $ownerUuid,
        private readonly string $title,
        private readonly string $content,
        private readonly array $buttons,
        private readonly Closure $callback
    ){}

    public function jsonSerialize() : array{
        return [
            'type' => 'form',
            'title' => $this->title,
            'content' => $this->content,
            'buttons' => array_map(static fn(string $text) : array => ['text' => $text], $this->buttons)
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        if($player->getUniqueId()->toString() !== $this->ownerUuid || !is_int($data) || !array_key_exists($data, $this->buttons)){
            return;
        }
        ($this->callback)($player, $data);
    }
}
