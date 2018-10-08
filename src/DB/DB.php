<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbWallet;

class DB
{
    private $pdo;

    private $addWalletStmt;
    private $addHeaderStmt;
    private $getBestHeaderStmt;
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

    public function getBestHeader(): DbHeader
    {
        if (null === $this->getBestHeaderStmt) {
            $this->getBestHeaderStmt = $this->pdo->prepare("SELECT id, height, hash, version, prevBlock, merkleRoot, merkleRoot, time, nbits, nonce from header");
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
        return $this->pdo->exec("CREATE TABLE `wallet` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`type`         INTEGER,
	`identifier`   TEXT
);");
    }
    public function createKeyTable() {
        return $this->pdo->exec("CREATE TABLE `key` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`walletId`	INTEGER,
	`keyIdentifier`	TEXT,
	`idx`	INTEGER,
	`publicKey`	TEXT,
);");
    }
    public function createScriptTable() {
        return $this->pdo->exec("CREATE TABLE `script` (
	`id`	INTEGER PRIMARY KEY AUTOINCREMENT,
	`walletId`	INTEGER,
	`keyIdentifier`         TEXT,
	`scriptPubKey`	TEXT,
	`redeemScript`	TEXT,
	`witnessScript`	TEXT
);");
    }
    public function createHeaderTable() {
        return $this->pdo->exec("CREATE TABLE `header` (
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
