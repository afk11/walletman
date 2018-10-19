<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Utxo\Utxo;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class DB
{
    private $pdo;

    private $addWalletStmt;
    private $createKeyStmt;
    private $loadKeyByPathStmt;
    private $loadScriptBySpkStmt;
    private $addHeaderStmt;
    private $getHashesStmt;
    private $getBestHeaderStmt;
    private $getBlockHashStmt;
    private $createScriptStmt;
    private $loadScriptByKeyIdStmt;
    private $getWalletStmt;
    private $getBip44WalletKey;
    private $getBlockCountStmt;
    private $getWalletUtxosStmt;
    private $createTxStmt;
    private $searchUnspentUtxoStmt;

    public function __construct(string $dsn)
    {
        $this->pdo = new \PDO($dsn);
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function createWalletTable()
    {
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

    public function createTxTable()
    {
        if (!$this->pdo->exec("CREATE TABLE `tx` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`     INTEGER,
            `valueChange`  INTEGER,
            `txid`         TEXT
        );")) {
            throw new \RuntimeException("failed to create tx table");
        }

        if (!$this->pdo->exec("CREATE UNIQUE INDEX unique_tx on tx(walletId, txid)")) {
            throw new \RuntimeException("failed add index on tx table");
        }
    }

    public function createUtxoTable()
    {
        if (!$this->pdo->exec("CREATE TABLE `utxo` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`     INTEGER,
            `scriptId`     INTEGER,
            `txid`         TEXT,
            `vout`         INTEGER,
            `spentTxid`    TEXT,
            `spentIdx`     INTEGER,
            `value`        INTEGER,
            `scriptPubKey` TEXT
        );")) {
            throw new \RuntimeException("failed to create wallet table");
        }

        if (!$this->pdo->exec("CREATE UNIQUE INDEX unique_utxo on utxo(walletId, txid, vout, spentTxid, spentIdx)")) {
            throw new \RuntimeException("failed add index on utxo table");
        }
        if (!$this->pdo->exec("CREATE INDEX index_scriptId on utxo(walletId, scriptId)")) {
            throw new \RuntimeException("failed add index on utxo table");
        }
        if (!$this->pdo->exec("CREATE INDEX index_scriptPubKey on utxo(walletId, scriptId)")) {
            throw new \RuntimeException("failed add index on utxo table");
        }
    }
    public function createKeyTable()
    {
        if (!$this->pdo->exec("CREATE TABLE `key` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`	INTEGER,
            `path`	TEXT,
            `childSequence`	INTEGER,
            `depth`	INTEGER,
            `key`	TEXT,
            `keyIndex`	INTEGER,
            `status`    INTEGER DEFAULT 0,
            `isLeaf`	TINYINT
        );")) {
            throw new \RuntimeException("failed to create key table");
        }
        if (!$this->pdo->exec("CREATE UNIQUE INDEX unique_key_at_index on key(walletId, path, keyIndex)")) {
            throw new \RuntimeException("failed add index on wallet table");
        }

    }

    public function createScriptTable()
    {
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

    public function createHeaderTable()
    {
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

    public function getBlockHash(int $height): BufferInterface
    {
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

    public function loadWallet(string $identifier): DbWallet
    {
        if (null === $this->getWalletStmt) {
            $this->getWalletStmt = $this->pdo->prepare("SELECT * FROM wallet WHERE identifier = ?");
        }
        if (!$this->getWalletStmt->execute([
            $identifier
        ])) {
            throw new \RuntimeException("Failed to find wallet");
        }

        return $this->getWalletStmt->fetchObject(DbWallet::class);
    }

    public function loadBip44WalletKey(int $walletId): DbKey
    {
        if (null === $this->getBip44WalletKey) {
            $this->getBip44WalletKey = $this->pdo->prepare("SELECT * FROM key WHERE walletId = ? and depth = 3");
        }
        if (!$this->getBip44WalletKey->execute([
            $walletId
        ])) {
            throw new \RuntimeException("Failed to find bip44 wallet key");
        }

        return $this->getBip44WalletKey->fetchObject(DbKey::class);
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

    public function createKey(int $walletId, string $path, HierarchicalKey $key, NetworkInterface $network, int $keyIndex, bool $isLeaf): int
    {
        if (null === $this->createKeyStmt) {
            $this->createKeyStmt = $this->pdo->prepare("INSERT INTO key (walletId, path, childSequence, depth, key, keyIndex, isLeaf) values (?,?,?,?,?,?,?)");
        }

        if (!$this->createKeyStmt->execute([
            $walletId, $path, 0, $key->getDepth(),
            $key->toExtendedPublicKey($network), $keyIndex, $isLeaf,
        ])) {
            throw new \RuntimeException("Failed to create key");
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function loadKeyByPath(int $walletId, string $path, int $keyIndex): DbKey
    {
        if (null === $this->loadKeyByPathStmt) {
            $this->loadKeyByPathStmt = $this->pdo->prepare("SELECT * FROM key WHERE walletId = ? AND path = ? AND keyIndex = ?");
        }

        if (!$this->loadKeyByPathStmt->execute([
            $walletId, $path, $keyIndex
        ])) {
            throw new \RuntimeException("Failed to find key");
        }

        return $this->loadKeyByPathStmt->fetchObject(DbKey::class);
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

    public function loadScriptByKeyId(int $walletId, string $keyIdentifier): ?DbScript
    {
        if (null === $this->loadScriptByKeyIdStmt) {
            $this->loadScriptByKeyIdStmt = $this->pdo->prepare("SELECT id, keyIdentifier, scriptPubKey, redeemScript, witnessScript from script where walletId = ? and keyIdentifier = ?");
        }
        if (!$this->loadScriptByKeyIdStmt->execute([
            $walletId, $keyIdentifier,
        ])) {
            throw new \RuntimeException("Failed to query script");
        }

        if ($result = $this->loadScriptByKeyIdStmt->fetchObject(DbScript::class)) {
            return $result;
        }
        return null;
    }

    public function loadScriptByScriptPubKey(int $walletId, ScriptInterface $script): ?DbScript
    {
        if (null === $this->loadScriptBySpkStmt) {
            $this->loadScriptBySpkStmt = $this->pdo->prepare("SELECT id, keyIdentifier, scriptPubKey, redeemScript, witnessScript from script where walletId = ? and scriptPubKey = ?");
        }
        if (!$this->loadScriptBySpkStmt->execute([
            $walletId, $script->getHex(),
        ])) {
            throw new \RuntimeException("Failed to query script");
        }

        if ($result = $this->loadScriptBySpkStmt->fetchObject(DbScript::class)) {
            return $result;
        }
        return null;
    }

    public function deleteSpends(int $walletId, OutPointInterface $utxoOutPoint, OutPointInterface $spendByOutPoint) {
        $sql = sprintf("UPDATE utxo SET spentTxid = ?, spentIdx = ? WHERE walletId = ? and txid = ? and vout = ?");
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute([
            $spendByOutPoint->getTxId()->getHex(), $spendByOutPoint->getVout(), $walletId,
            $utxoOutPoint->getTxId()->getHex(), $utxoOutPoint->getVout(),
        ])) {
            throw new \RuntimeException("Failed to update utxos with spend");
        }
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("failed to delete utxo");
        }
    }
    public function createUtxos(int $walletId, array $utxoAndDbScripts)
    {
        $columns = ["walletId", "scriptId", "txid", "vout", "value", "scriptPubKey"];
        $numCols = count($columns);
        $numRows = count($utxoAndDbScripts);
        $placeholder = "(" . implode(",", array_fill(0, $numCols, "?")) . ")";
        $sql = sprintf("INSERT INTO utxo (walletId, scriptId, txid, vout, value, scriptPubKey) values %s", implode(",", array_fill(0, $numRows, $placeholder)));

        $stmt = $this->pdo->prepare($sql);
        for ($i = 0; $i < $numRows; $i++) {
            /** @var Utxo $utxo */
            /** @var DbScript $dbScript */
            list ($utxo, $dbScript) = $utxoAndDbScripts[$i];
            $stmt->bindValue($i * $numCols + 1, $walletId);
            $stmt->bindValue($i * $numCols + 2, $dbScript->getId());
            $stmt->bindValue($i * $numCols + 3, $utxo->getOutPoint()->getTxId()->getHex());
            $stmt->bindValue($i * $numCols + 4, $utxo->getOutPoint()->getVout());
            $stmt->bindValue($i * $numCols + 5, $utxo->getOutput()->getValue());
            $stmt->bindValue($i * $numCols + 6, $utxo->getOutput()->getScript()->getHex());
        }
        if (!$stmt->execute()) {
            throw new \RuntimeException("Failed to insert utxos");
        }
    }

    public function searchUnspentUtxo(int $walletId, OutPointInterface $outPoint): ?DbUtxo
    {
        if (null === $this->searchUnspentUtxoStmt) {
            $this->searchUnspentUtxoStmt = $this->pdo->prepare("SELECT * from utxo where walletId = ? and txid = ? and vout = ? and spentTxid IS NULL");
        }
        if (!$this->searchUnspentUtxoStmt->execute([$walletId, $outPoint->getTxId()->getHex(), $outPoint->getVout()])) {
            throw new \RuntimeException("Failed to query utxo");
        }
        if ($utxo = $this->searchUnspentUtxoStmt->fetchObject(DbUtxo::class)) {
            return $utxo;
        }
        return null;
    }

    /**
     * @param int $walletId
     * @return DbUtxo[]
     */
    public function getWalletUtxos(int $walletId): array
    {
        if (null === $this->getWalletUtxosStmt) {
            $this->getWalletUtxosStmt = $this->pdo->prepare("SELECT * from utxo where walletId = ? and spentTxid IS NULL");
        }
        if (!$this->getWalletUtxosStmt->execute([$walletId])) {
            throw new \RuntimeException("Failed to query utxos");
        }
        $utxos = [];
        while ($utxo = $this->getWalletUtxosStmt->fetchObject(DbUtxo::class)) {
            $utxos[] = $utxo;
        }
        return $utxos;
    }

    public function createTx(int $walletId, BufferInterface $txid, int $valueChange)
    {
        if (null === $this->createTxStmt) {
            $this->createTxStmt = $this->pdo->prepare("INSERT INTO tx (walletId, txid, valueChange) values (?,?,?)");
        }
        $this->createTxStmt->execute([
            $walletId, $txid->getHex(), $valueChange,
        ]);
    }
}
