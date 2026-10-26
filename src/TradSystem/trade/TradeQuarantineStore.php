<?php

declare(strict_types=1);

namespace TradSystem\trade;

use Closure;
use JsonException;

/**
 * Persists UUIDs that must not trade after an inventory rollback failure.
 *
 * Corrupt or unwritable quarantine storage is a fail-closed condition: callers
 * must disable trading rather than risk allowing affected players to trade.
 */
final class TradeQuarantineStore{
    /** @var array<string, array{at: string, reason: string}> */
    private array $entries = [];
    private bool $unsafe = false;

    public function __construct(
        private readonly string $path,
        private readonly ?Closure $onError = null
    ){
        $this->load();
    }

    public function isUnsafe() : bool{
        return $this->unsafe;
    }

    public function contains(string $uuid) : bool{
        return isset($this->entries[$uuid]);
    }

    /**
     * @return array<string, array{at: string, reason: string}>
     */
    public function getEntries() : array{
        return $this->entries;
    }

    public function quarantine(string $uuid, string $reason) : bool{
        if(trim($uuid) === ""){
            $this->markUnsafe("Refusing to persist an empty player UUID in the quarantine store.");
            return false;
        }

        $safeReason = preg_replace('/[^A-Za-z0-9_.-]/', '-', $reason) ?? "inventory-rollback-failed";
        $safeReason = substr($safeReason, 0, 64);
        if($safeReason === ""){
            $safeReason = "inventory-rollback-failed";
        }
        $this->entries[$uuid] = [
            "at" => gmdate(DATE_ATOM),
            "reason" => $safeReason
        ];

        if($this->unsafe){
            return false;
        }
        return $this->persist();
    }

    private function load() : void{
        // A surviving temp file means a prior persistence attempt may have
        // stopped between writing the journal and atomically replacing the
        // canonical file. Never trust the older file in that situation.
        if(file_exists($this->path . ".tmp")){
            $this->markUnsafe("Found an incomplete TradeSystem quarantine write; all trading is disabled.");
            return;
        }
        if(!file_exists($this->path)){
            return;
        }
        if(!is_file($this->path)){
            $this->markUnsafe("Trade quarantine path exists but is not a regular file.");
            return;
        }

        $content = @file_get_contents($this->path);
        if($content === false){
            $this->markUnsafe("Unable to read the TradeSystem quarantine file.");
            return;
        }

        try{
            // The persisted format is an object keyed by player UUID. Decode as
            // objects so a valid empty object ({}) cannot be confused with the
            // invalid empty/list array ([]) when associative decoding is used.
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        }catch(JsonException){
            $this->markUnsafe("TradeSystem quarantine file contains invalid JSON; all trading is disabled.");
            return;
        }
        if(!$decoded instanceof \stdClass){
            $this->markUnsafe("TradeSystem quarantine file has an invalid root type; all trading is disabled.");
            return;
        }

        foreach($decoded as $uuid => $entry){
            if(!is_string($uuid) || $uuid === "" || !$entry instanceof \stdClass || !isset($entry->at, $entry->reason) || !is_string($entry->at) || !is_string($entry->reason)){
                $this->markUnsafe("TradeSystem quarantine file has an invalid entry; all trading is disabled.");
                return;
            }
            $this->entries[$uuid] = [
                "at" => $entry->at,
                "reason" => $entry->reason
            ];
        }
    }

    private function persist() : bool{
        if($this->unsafe){
            return false;
        }

        try{
            $json = json_encode((object) $this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }catch(JsonException){
            $this->markUnsafe("Unable to encode TradeSystem quarantine data; all trading is disabled.");
            return false;
        }

        $serialized = $json . PHP_EOL;
        $temporaryPath = $this->path . ".tmp";
        $written = @file_put_contents($temporaryPath, $serialized, LOCK_EX);
        if($written === false || $written !== strlen($serialized)){
            // Keep a possible partial temp file as a startup fail-closed marker.
            $this->markUnsafe("Unable to write TradeSystem quarantine data; all trading is disabled.");
            return false;
        }
        if(!@rename($temporaryPath, $this->path)){
            // Keep the complete temp file on disk. On restart, load() sees it
            // and refuses to trust the potentially stale canonical quarantine.
            $this->markUnsafe("Unable to atomically replace TradeSystem quarantine data; all trading is disabled.");
            return false;
        }
        return true;
    }

    private function markUnsafe(string $message) : void{
        $this->unsafe = true;
        if($this->onError !== null){
            ($this->onError)($message);
        }
    }
}
