<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbWallet;

class DB
{
    private $pdo;

    private $addWalletStmt;
    private $addHeaderStmt;
    private $getHashesStmt;
    private $getBestHeaderStmt;
    private $getBlockHashStmt;
    private $createScriptStmt;
    private $loadScriptStmt;
    private $getBlockCountStmt;

    public function __construct(string $dsn)
    {
        $this->pdo = new \PDO($dsn);
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function getBlockHash(int $height): BufferInterface {
        if (null === $this->getBlockHashStmt) {
            $this->getBlockHashStmt = $this->pdo->prepare("SELECT hash from header where height = ?");
        }
        if (!$this->getBlockHashStmt->execute([
            $height
        ])) {
            throw new \RuntimeException("getblockhash query failed");
        }
        return Buffer::hex($this->getBestHeaderStmt->fetch()['hash']);
    }
    public function getTailHashes(int $height): array
    {
        if (null === $this->getHashesStmt) {
            $this->getHashesStmt = $this->pdo->prepare("SELECT hash from header where height < ? order by id ASC");
        }
        $this->getHashesStmt->execute([
            $height
        ]);
        $hashes = $this->getHashesStmt->fetchAll(\PDO::FETCH_COLUMN);
        $num = count($hashes);
        for ($i = 0; $i < $num; $i++) {
            $hashes[$i] = pack("H*", $hashes[$i]);
        }
        return $hashes;
    }
    public function getBestHeader(): DbHeader
    {
        if (null === $this->getBestHeaderStmt) {
            $this->getBestHeaderStmt = $this->pdo->prepare("SELECT id, height, hash, version, prevBlock, merkleRoot, merkleRoot, time, nbits, nonce from header order by id desc limit 1");
        }
        $this->getBestHeaderStmt->execute();
        return $this->getBestHeaderStmt->fetchObject(DbHeader::class);
    }
    public function getBlockCount(): int
    {
        if (null === $this->getBlockCountStmt) {
            $this->getBlockCountStmt = $this->pdo->prepare("SELECT count(*) as count from header");
        }
        $this->getBlockCountStmt->execute();
        return (int) $this->getBlockCountStmt->fetch()['count'];
    }

    public function createWalletTable() {
        if (!$this->pdo->exec("CREATE TABLE `wallet` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`type`         INTEGER,
	`identifier`   TEXT
);")) {
            throw new \RuntimeException("failed to create wallet table");
        }

        if (!$this->pdo->exec("CREATE UNIQUE INDEX unique_identifier on wallet(identifier)")) {
            throw new \RuntimeException("failed add index on wallet table");
        }
    }
    public function createKeyTable() {
        return $this->pdo->exec("CREATE TABLE `key` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`walletId`	INTEGER,
	`keyIdentifier`	TEXT,
	`idx`	INTEGER,
	`publicKey`	TEXT
);");
    }
    public function createScriptTable() {
        if (!$this->pdo->exec("CREATE TABLE `script` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`walletId`	INTEGER,
	`keyIdentifier`         TEXT,
	`scriptPubKey`	TEXT,
	`redeemScript`	TEXT,
	`witnessScript`	TEXT
);")) {
            throw new \RuntimeException("failed to create script table");
        }

        if (!$this->pdo->exec("CREATE UNIQUE INDEX unique_keyIdentifier on script(walletId, keyIdentifier)")) {
            throw new \RuntimeException("failed to add keyId index on script table");
        }
        if (!$this->pdo->exec("CREATE UNIQUE INDEX unique_scriptPubKey on script(walletId, scriptPubKey)")) {
            throw new \RuntimeException("failed to add spk index on script table");
        }
    }
    public function createHeaderTable() {
        $this->pdo->exec("CREATE TABLE `header` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`height`	INTEGER,
	`hash`	TEXT UNIQUE,
	`version`	INTEGER,
	`prevBlock`	TEXT,
	`merkleRoot`	TEXT,
	`time`	INTEGER,
	`nbits`	INTEGER,
	`nonce`	INTEGER
);");
    }
    public function addHeader(int $height, BufferInterface $hash, BlockHeaderInterface $header): bool
    {
        if (null === $this->addHeaderStmt) {
            $this->addHeaderStmt = $this->pdo->prepare("INSERT INTO header (height, hash, version, prevBlock, merkleRoot, time, nbits, nonce) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        }
        return $this->addHeaderStmt->execute([
            $height, $hash->getHex(), $header->getVersion(),
            $header->getPrevBlock()->getHex(), $header->getMerkleRoot()->getHex(),
            $header->getTimestamp(), $header->getBits(), $header->getNonce(),
        ]);
    }
    public function createWallet(string $identifier, int $type): int
    {
        if (null === $this->addWalletStmt) {
            $this->addWalletStmt = $this->pdo->prepare("INSERT INTO wallet (identifier, type) VALUES (?, ?)");
        }
        if (!$this->addWalletStmt->execute([
            $identifier, $type,
        ])) {
            throw new \RuntimeException("Failed to create wallet");
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function createScript(int $walletId, string $keyIdentifier, string $scriptPubKey, string $redeemScript = null, string $witnessScript = null): int
    {
        if (null === $this->createScriptStmt) {
            $this->createScriptStmt = $this->pdo->prepare("INSERT INTO script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (?,?,?,?,?)");
        }
        $this->createScriptStmt->execute([
            $walletId, $keyIdentifier, $scriptPubKey,
            $redeemScript, $witnessScript,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
    public function loadScript(int $walletId, string $keyIdentifier): DbScript
    {
        if (null === $this->loadScriptStmt) {
            $this->loadScriptStmt = $this->pdo->prepare("SELECT id, keyIdentifier, scriptPubKey, redeemScript, witnessScript from script where walletId = ? and keyIdentifier = ?");
        }
        $this->loadScriptStmt->execute([
            $walletId, $keyIdentifier,
        ]);
        return $this->loadScriptStmt->fetchObject(DbScript::class);
    }

}
