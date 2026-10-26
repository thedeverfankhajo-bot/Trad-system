<?php

declare(strict_types=1);

namespace TradSystem\util;

/**
 * Makes item-controlled names safe to place in multi-line forms and audit summaries.
 * This is display-only; the original Item instance and its metadata are never modified.
 */
final class ItemDisplayName{
    public static function sanitize(string $name) : string{
        $formatted = preg_replace('/§[0-9a-fk-or]/iu', '', $name);
        if($formatted !== null){
            $name = $formatted;
        }
        $name = str_replace('§', '', $name);

        $withoutControls = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', ' ', $name);
        $name = $withoutControls ?? '';
        $withoutBidiControls = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = $withoutBidiControls ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        $name = trim($collapsed ?? $name);

        if($name === ''){
            return 'Unnamed item';
        }

        $matched = preg_match_all('/./us', $name, $characters);
        if($matched === false){
            return 'Unnamed item';
        }
        if($matched > 80){
            return implode('', array_slice($characters[0], 0, 79)) . '…';
        }
        return $name;
    }
}
