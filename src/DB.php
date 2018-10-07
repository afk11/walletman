<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbHeader;

class DB
{
    private $pdo;

    private $addHeaderStmt;
    private $getBestHeaderStmt;
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
}
