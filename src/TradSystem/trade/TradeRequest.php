<?php

declare(strict_types=1);

namespace TradSystem\trade;

final class TradeRequest{
    public function __construct(
        public readonly string $id,
        public readonly string $senderUuid,
        public readonly string $receiverUuid,
        public readonly string $senderName,
        public readonly string $receiverName,
        public readonly int $expiresAt
    ){}

    public static function create(string $senderUuid, string $receiverUuid, string $senderName, string $receiverName, int $expiresAt) : self{
        return new self(bin2hex(random_bytes(16)), $senderUuid, $receiverUuid, $senderName, $receiverName, $expiresAt);
    }

    public function isExpired(int $now) : bool{
        return $now >= $this->expiresAt;
    }
}
