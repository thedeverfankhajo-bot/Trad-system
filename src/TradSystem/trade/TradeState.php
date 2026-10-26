<?php

declare(strict_types=1);

namespace TradSystem\trade;

enum TradeState : string{
    case OFFERING = 'offering';
    case CONFIRMING = 'confirming';
    case COMMITTING = 'committing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function isTerminal() : bool{
        return $this === self::COMPLETED || $this === self::CANCELLED;
    }
}
