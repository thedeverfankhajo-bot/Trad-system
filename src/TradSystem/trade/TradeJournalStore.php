<?php

declare(strict_types=1);

namespace TradSystem\trade;

use Closure;
use JsonException;
use Throwable;

/**
 * Durable write-ahead record for one unresolved trade.
 *
 * PREPARED means recovery must restore both pre-trade inventories.
 * COMMITTED means recovery must restore both post-trade inventories.
 * The record remains until both player saves are complete or recovery has
 * individually saved and acknowledged each participant's inventory.
 */
final class TradeJournalStore{
    private const VERSION = 1;
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @var array<string, mixed>|null */
    private ?array $record = null;
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

    public function hasPendingTransaction() : bool{
        return $this->record !== null;
    }

    /** @return array<string, mixed>|null */
    public function getRecord() : ?array{
        return $this->record;
    }

    /**
     * @param array{size: int, slots: array<int, string>} $beforeOne
     * @param array{size: int, slots: array<int, string>} $beforeTwo
     * @param array{size: int, slots: array<int, string>} $afterOne
     * @param array{size: int, slots: array<int, string>} $afterTwo
     */
    public function prepare(
        string $uuidOne,
        string $uuidTwo,
        array $beforeOne,
        array $beforeTwo,
        array $afterOne,
        array $afterTwo
    ) : bool{
        if($this->unsafe || $this->record !== null || $uuidOne === "" || $uuidTwo === "" || $uuidOne === $uuidTwo){
            return false;
        }
        if(
            !$this->isSnapshot($beforeOne) ||
            !$this->isSnapshot($beforeTwo) ||
            !$this->isSnapshot($afterOne) ||
            !$this->isSnapshot($afterTwo)
        ){
            $this->report("Refusing to prepare a TradeSystem journal with invalid inventory snapshots.");
            return false;
        }

        $record = [
            "version" => self::VERSION,
            "transaction_id" => bin2hex(random_bytes(16)),
            "state" => "PREPARED",
            "party_one" => $uuidOne,
            "party_two" => $uuidTwo,
            "before" => [
                $uuidOne => $beforeOne,
                $uuidTwo => $beforeTwo
            ],
            "after" => [
                $uuidOne => $afterOne,
                $uuidTwo => $afterTwo
            ],
            "recovered" => [
                $uuidOne => false,
                $uuidTwo => false
            ],
            "created_at" => gmdate(DATE_ATOM)
        ];
        return $this->persistRecord($record);
    }

    public function markCommitted() : bool{
        if($this->unsafe || $this->record === null || ($this->record["state"] ?? null) !== "PREPARED"){
            return false;
        }
        $next = $this->record;
        $next["state"] = "COMMITTED";
        return $this->persistRecord($next);
    }

    public function markRecovered(string $uuid) : bool{
        if($this->unsafe || $this->record === null || !$this->isParticipant($uuid)){
            return false;
        }
        if(($this->record["recovered"][$uuid] ?? false) === true){
            return true;
        }
        $next = $this->record;
        $next["recovered"][$uuid] = true;
        return $this->persistRecord($next);
    }

    public function isParticipant(string $uuid) : bool{
        return $this->record !== null &&
            ($uuid === ($this->record["party_one"] ?? null) || $uuid === ($this->record["party_two"] ?? null));
    }

    public function isRecovered(string $uuid) : bool{
        return $this->record !== null && ($this->record["recovered"][$uuid] ?? false) === true;
    }

    public function bothParticipantsRecovered() : bool{
        if($this->record === null){
            return false;
        }
        $one = $this->record["party_one"] ?? null;
        $two = $this->record["party_two"] ?? null;
        return is_string($one) && is_string($two) &&
            ($this->record["recovered"][$one] ?? false) === true &&
            ($this->record["recovered"][$two] ?? false) === true;
    }

    /**
     * Clears a PREPARED record only after rollback was verified and both player
     * inventories were saved, or a COMMITTED record after both after-states were saved.
     */
    public function clearAfterRollback() : bool{
        if($this->record === null || ($this->record["state"] ?? null) !== "PREPARED"){
            return false;
        }
        return $this->deleteRecord();
    }

    public function clearAfterCommittedSave() : bool{
        if($this->record === null || ($this->record["state"] ?? null) !== "COMMITTED"){
            return false;
        }
        return $this->deleteRecord();
    }

    public function clearIfBothRecovered() : bool{
        if(!$this->bothParticipantsRecovered()){
            return false;
        }
        return $this->deleteRecord();
    }

    private function load() : void{
        $lockPath = $this->path . ".unsafe";
        if(file_exists($lockPath)){
            $this->unsafe = true;
            $this->report("TradeSystem journal safety lock exists; verify both player inventories and resolve the journal before restarting trading.");
            return;
        }

        if(!file_exists($this->path)){
            // A first PREPARED write must finish before inventory mutation starts.
            // A lone temp file therefore cannot represent a transaction that began.
            if(file_exists($this->path . ".tmp") && !@unlink($this->path . ".tmp")){
                $this->report("Could not remove a stale temporary TradeSystem journal file; new trade preparation may fail until storage is writable.");
            }
            return;
        }
        if(!is_file($this->path)){
            $this->markUnsafe("TradeSystem journal path exists but is not a regular file.");
            return;
        }

        $content = @file_get_contents($this->path);
        if($content === false){
            $this->markUnsafe("Unable to read the TradeSystem transaction journal.");
            return;
        }
        try{
            $record = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        }catch(JsonException){
            $this->markUnsafe("TradeSystem transaction journal contains invalid JSON.");
            return;
        }
        if(!is_array($record) || !$this->isValidRecord($record)){
            $this->markUnsafe("TradeSystem transaction journal is malformed, unsupported or has an invalid checksum.");
            return;
        }
        $this->record = $record;

        // If an update was interrupted, the existing canonical record remains the
        // recovery decision. PREPARED recovers to before; COMMITTED recovers to after.
        if(file_exists($this->path . ".tmp") && !@unlink($this->path . ".tmp")){
            $this->report("A stale temporary journal could not be removed; the verified canonical record remains authoritative.");
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isValidRecord(array $record) : bool{
        $checksum = $record["checksum"] ?? null;
        if(!is_string($checksum)){
            return false;
        }
        unset($record["checksum"]);
        try{
            $canonical = json_encode($record, self::JSON_FLAGS);
        }catch(JsonException){
            return false;
        }
        if(!hash_equals($checksum, hash("sha256", $canonical))){
            return false;
        }

        $one = $record["party_one"] ?? null;
        $two = $record["party_two"] ?? null;
        if(
            ($record["version"] ?? null) !== self::VERSION ||
            !is_string($record["transaction_id"] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/', $record["transaction_id"]) ||
            !in_array($record["state"] ?? null, ["PREPARED", "COMMITTED"], true) ||
            !is_string($one) || $one === "" ||
            !is_string($two) || $two === "" || $one === $two ||
            !is_string($record["created_at"] ?? null) ||
            !is_array($record["before"] ?? null) ||
            !is_array($record["after"] ?? null) ||
            !is_array($record["recovered"] ?? null)
        ){
            return false;
        }

        foreach([$one, $two] as $uuid){
            if(
                !isset($record["before"][$uuid]) ||
                !isset($record["after"][$uuid]) ||
                !$this->isSnapshot($record["before"][$uuid]) ||
                !$this->isSnapshot($record["after"][$uuid]) ||
                !is_bool($record["recovered"][$uuid] ?? null)
            ){
                return false;
            }
        }
        return true;
    }

    private function isSnapshot(mixed $snapshot) : bool{
        if(
            !is_array($snapshot) ||
            !is_int($snapshot["size"] ?? null) ||
            $snapshot["size"] < 1 ||
            $snapshot["size"] > 256 ||
            !is_array($snapshot["slots"] ?? null)
        ){
            return false;
        }
        foreach($snapshot["slots"] as $slot => $encoded){
            if(
                !is_int($slot) || $slot < 0 || $slot >= $snapshot["size"] ||
                !is_string($encoded) || base64_decode($encoded, true) === false
            ){
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function persistRecord(array $record) : bool{
        if($this->unsafe){
            return false;
        }
        unset($record["checksum"]);
        try{
            $canonical = json_encode($record, self::JSON_FLAGS);
            $record["checksum"] = hash("sha256", $canonical);
            $serialized = json_encode($record, self::JSON_FLAGS) . PHP_EOL;
        }catch(JsonException $exception){
            $this->report("Could not encode the TradeSystem journal: " . $exception->getMessage());
            return false;
        }

        $temporaryPath = $this->path . ".tmp";
        $handle = @fopen($temporaryPath, "wb");
        if($handle === false){
            $this->report("Could not open the temporary TradeSystem journal for writing.");
            return false;
        }

        $offset = 0;
        $length = strlen($serialized);
        $writeOk = true;
        while($offset < $length){
            $written = @fwrite($handle, substr($serialized, $offset));
            if($written === false || $written === 0){
                $writeOk = false;
                break;
            }
            $offset += $written;
        }
        if($writeOk){
            $writeOk = @fflush($handle);
        }
        if($writeOk){
            $writeOk = function_exists("fsync") && @fsync($handle);
        }
        $closeOk = @fclose($handle);
        if(!$writeOk || !$closeOk || $offset !== $length){
            @unlink($temporaryPath);
            $this->report("The TradeSystem journal write was incomplete; the prior canonical record has not been changed.");
            return false;
        }
        if(!@rename($temporaryPath, $this->path)){
            $this->report("Could not atomically replace the TradeSystem journal; the prior canonical record remains authoritative.");
            @unlink($temporaryPath);
            return false;
        }

        $this->syncDirectoryBestEffort();
        $this->record = $record;
        return true;
    }

    private function deleteRecord() : bool{
        if($this->unsafe || $this->record === null){
            return false;
        }
        $temporaryPath = $this->path . ".tmp";
        if(file_exists($temporaryPath) && !@unlink($temporaryPath)){
            $this->report("Could not remove the temporary journal before clearing a resolved transaction.");
            return false;
        }
        if(!is_file($this->path) || !@unlink($this->path)){
            $this->report("Could not clear the resolved TradeSystem journal; trading remains locked until cleanup succeeds.");
            return false;
        }
        $this->syncDirectoryBestEffort();
        $this->record = null;
        return true;
    }

    private function markUnsafe(string $message) : void{
        $this->unsafe = true;
        $lockPath = $this->path . ".unsafe";
        if(!file_exists($lockPath)){
            $lockMessage = "TradeSystem transaction journal cannot be trusted. Stop the server, reconcile both player inventories from trusted state, and review the journal before removing this safety lock." . PHP_EOL;
            $written = @file_put_contents($lockPath, $lockMessage, LOCK_EX);
            if($written === false || $written !== strlen($lockMessage)){
                $message .= " The persistent journal safety lock could not be written; stop the server immediately.";
            }
        }
        $this->report($message);
    }

    private function syncDirectoryBestEffort() : void{
        if(!function_exists("fsync")){
            return;
        }
        $directory = @fopen(dirname($this->path), "r");
        if(is_resource($directory)){
            @fsync($directory);
            @fclose($directory);
        }
    }

    private function report(string $message) : void{
        if($this->onError !== null){
            ($this->onError)($message);
        }
    }
}
