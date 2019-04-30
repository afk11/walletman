<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\BlockRef;

class DB implements DBInterface
{
    private $pdo;

    private $addWalletStmt;
    private $createBip32KeyStmt;
    private $createElectrumKeyStmt;
    private $loadKeyByPathStmt;
    private $loadKeysByPathStmt;
    private $loadScriptBySpkStmt;
    private $loadWalletIdsBySpkStmt;
    private $addHeaderStmt;
    private $setBlockReceivedStmt;
    private $getHeaderStmt;
    private $getBlockHashStmt;
    private $createScriptStmt;
    private $loadScriptByKeyIdStmt;
    private $getWalletStmt;
    private $checkWalletExistsStmt;
    private $allWalletsStmt;
    private $getBip44WalletKey;
    private $getWalletUtxosStmt;
    private $createTxStmt;
    private $updateTxStatusStmt;
    private $deleteTxStmt;
    private $getConfirmedBalanceStmt;
    private $createUtxoStmt;
    private $deleteUtxoStmt;
    private $findWalletsWithUtxoStmt;
    private $searchUnspentUtxoStmt;
    private $getWalletScriptPubKeysStmt;

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
        $this->pdo->exec("CREATE TABLE `wallet` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `type`         INTEGER,
            `identifier`   TEXT,
            `birthday_hash`TEXT,
            `birthday_height` INTEGER,
            `gapLimit`     INTEGER
        );");

        $this->pdo->exec("CREATE UNIQUE INDEX unique_identifier on wallet(identifier)");
    }

    public function createTxTable()
    {
        if (false === $this->pdo->exec("CREATE TABLE `tx` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`     INTEGER NOT NULL,
            `valueChange`  INTEGER NOT NULL,
            `status`       INTEGER NOT NULL,
            `txid`         VARCHAR(64) NOT NULL,
            `confirmedHash`    TEXT,
            `confirmedHeight`  TEXT
        );")) {
            throw new \RuntimeException("failed to create tx table");
        }

        if (false === $this->pdo->exec("CREATE UNIQUE INDEX unique_tx on tx(walletId, txid)")) {
            throw new \RuntimeException("failed add index on tx table");
        }
    }

    public function createRawTxTable()
    {
        if (false === $this->pdo->exec("CREATE TABLE `rawTx` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `txid`  VARCHAR(64) NOT NULL,
            `tx`    TEXT NOT NULL
        );")) {
            throw new \RuntimeException("failed to create raw tx table");
        }

        if (false === $this->pdo->exec("CREATE UNIQUE INDEX unique_rawTx on rawTx(txid)")) {
            throw new \RuntimeException("failed add index on rawtx table");
        }
    }

    public function createUtxoTable()
    {
        if (false === $this->pdo->exec("CREATE TABLE `utxo` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`     INTEGER NOT NULL,
            `scriptId`     INTEGER,
            `txid`         TEXT NOT NULL,
            `vout`         INTEGER NOT NULL,
            `spentTxid`    TEXT,
            `spentIdx`     INTEGER,
            `value`        INTEGER NOT NULL,
            `scriptPubKey` TEXT NOT NULL
        );")) {
            throw new \RuntimeException("failed to create utxo table");
        }

        if (false === $this->pdo->exec("CREATE UNIQUE INDEX unique_utxo on utxo(walletId, txid, vout, spentTxid, spentIdx)")) {
            throw new \RuntimeException("failed add index on utxo table");
        }
        if (false === $this->pdo->exec("CREATE INDEX index_scriptId on utxo(walletId, scriptId)")) {
            throw new \RuntimeException("failed add index on utxo table");
        }
        if (false === $this->pdo->exec("CREATE INDEX index_scriptPubKey on utxo(walletId, scriptPubKey, scriptId)")) {
            throw new \RuntimeException("failed add index on utxo table");
        }
    }
    public function createKeyTable()
    {
        if (false === $this->pdo->exec("CREATE TABLE `key` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`	INTEGER NOT NULL,
            `path`	TEXT NOT NULL,
            `childSequence`	INTEGER,
            `depth`	INTEGER,
            `key`	TEXT NOT NULL,
            `keyIndex`	INTEGER,
            `status`    INTEGER DEFAULT 0,
            `isLeaf`	TINYINT
        );")) {
            throw new \RuntimeException("failed to create key table");
        }
        if (false === $this->pdo->exec("CREATE UNIQUE INDEX unique_key_at_index on key(walletId, path, keyIndex)")) {
            throw new \RuntimeException("failed add index on key table");
        }
    }

    public function createScriptTable()
    {
        if (false === $this->pdo->exec("CREATE TABLE `script` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `walletId`	INTEGER,
            `keyIdentifier`         TEXT,
            `scriptPubKey`	TEXT,
            `redeemScript`	TEXT,
            `witnessScript`	TEXT
        );")) {
            throw new \RuntimeException("failed to create script table");
        }

        if (false === $this->pdo->exec("CREATE UNIQUE INDEX unique_keyIdentifier on script(walletId, keyIdentifier)")) {
            throw new \RuntimeException("failed to add keyId index on script table");
        }
        if (false === $this->pdo->exec("CREATE UNIQUE INDEX unique_scriptPubKey on script(walletId, scriptPubKey)")) {
            throw new \RuntimeException("failed to add spk index on script table");
        }
    }

    public function createHeaderTable()
    {
        $this->pdo->exec("CREATE TABLE `header` (
            `id`	INTEGER PRIMARY KEY AUTOINCREMENT,
            `status`	INTEGER,
            `height`	INTEGER,
            `work`	TEXT NOT NULL,
            `hash`	TEXT UNIQUE,
            `version`	INTEGER,
            `prevBlock`	TEXT,
            `merkleRoot`	TEXT,
            `time`	INTEGER,
            `nbits`	INTEGER,
            `nonce`	INTEGER
        );");
    }
    public function getGenesisHeader(): ?DbHeader
    {
        if (null === $this->getBlockHashStmt) {
            $this->getBlockHashStmt = $this->pdo->prepare("SELECT * from header where height = 0");
        }
        if (!$this->getBlockHashStmt->execute()) {
            throw new \RuntimeException("getblockhash query failed");
        }
        if ($header = $this->getBlockHashStmt->fetchObject(DbHeader::class)) {
            return $header;
        }
        return null;
    }

    public function getHeader(BufferInterface $hash): ?DbHeader
    {
        if (null === $this->getHeaderStmt) {
            $this->getHeaderStmt = $this->pdo->prepare("SELECT * from header where hash = ?");
        }
        $this->getHeaderStmt->execute([
            $hash->getHex(),
        ]);
        if ($header = $this->getHeaderStmt->fetchObject(DbHeader::class)) {
            return $header;
        }
        return null;
    }

    public function deleteBlocksFromIndex()
    {
        return $this->pdo->exec("UPDATE header set status = 1 where status = 3");
    }

    public function deleteBlockIndex()
    {
        return $this->pdo->exec("DELETE FROM header WHERE 1");
    }

    public function deleteWalletTxs()
    {
        return $this->pdo->exec("DELETE FROM tx");
    }

    public function deleteWalletUtxos()
    {
        return $this->pdo->exec("DELETE FROM utxo");
    }

    public function markBirthdayHistoryValid(int $height)
    {
        $stmt = $this->pdo->prepare("UPDATE header set status = 3 where status = 1 and height <= ?");
        $stmt->execute([
            $height,
        ]);
    }

    public function addHeader(int $height, \GMP $work, BufferInterface $hash, BlockHeaderInterface $header, int $status): bool
    {
        if (null === $this->addHeaderStmt) {
            $this->addHeaderStmt = $this->pdo->prepare("INSERT INTO header (height, hash, status, version, prevBlock, merkleRoot, time, nbits, nonce, work) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        }
        if (!$this->addHeaderStmt->execute([
            $height, $hash->getHex(), $status, $header->getVersion(),
            $header->getPrevBlock()->getHex(), $header->getMerkleRoot()->getHex(),
            $header->getTimestamp(), $header->getBits(), $header->getNonce(),
            gmp_strval($work, 10),
        ])) {
            throw new \RuntimeException("failed to insert header");
        }
        return true;
    }

    public function setBlockReceived(BufferInterface $hash): bool
    {
        if (null === $this->setBlockReceivedStmt) {
            $this->setBlockReceivedStmt = $this->pdo->prepare("UPDATE header set status = status | ? where hash = ?");
        }
        if (!$this->setBlockReceivedStmt->execute([
            DbHeader::BLOCK_VALID, $hash->getHex(),
        ])) {
            throw new \RuntimeException("failed to update index");
        }
        return true;
    }

    /**
     * @return DbWallet[]
     */
    public function loadAllWallets(): array
    {
        if (null === $this->allWalletsStmt) {
            $this->allWalletsStmt = $this->pdo->prepare("SELECT * FROM wallet ORDER BY id ASC");
        }
        if (!$this->allWalletsStmt->execute()) {
            throw new \RuntimeException("Failed to find wallet");
        }

        $wallets = [];
        while ($dbWallet = $this->allWalletsStmt->fetchObject(DbWallet::class)) {
            $wallets[] = $dbWallet;
        }
        return $wallets;
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

    public function checkWalletExists(string $identifier): bool
    {
        if (null === $this->checkWalletExistsStmt) {
            $this->checkWalletExistsStmt = $this->pdo->prepare("SELECT COUNT(*) FROM wallet WHERE identifier = ?");
        }
        $this->checkWalletExistsStmt->execute([$identifier]);
        return $this->checkWalletExistsStmt->fetchColumn(0) == 1;
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

    public function createWallet(string $identifier, int $type, ?int $gapLimit, ?BlockRef $birthday): int
    {
        if (null === $this->addWalletStmt) {
            $this->addWalletStmt = $this->pdo->prepare("INSERT INTO wallet (identifier, type, birthday_hash, birthday_height, gapLimit) VALUES (?, ?, ?, ?, ?)");
        }
        if (!$this->addWalletStmt->execute([
            $identifier, $type,
            null === $birthday ? null : $birthday->getHash()->getHex(),
            null === $birthday ? null : $birthday->getHeight(),
            $gapLimit,
        ])) {
            throw new \RuntimeException("Failed to create wallet");
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function createBip32Key(int $walletId, Base58ExtendedKeySerializer $serializer, string $path, HierarchicalKey $key, NetworkInterface $network, int $keyIndex, bool $isLeaf): int
    {
        if (null === $this->createBip32KeyStmt) {
            $this->createBip32KeyStmt = $this->pdo->prepare("INSERT INTO key (walletId, path, childSequence, depth, key, keyIndex, isLeaf) values (?,?,?,?,?,?,?)");
        }

        if (!$this->createBip32KeyStmt->execute([
            $walletId, $path, 0, $key->getDepth(),
            $serializer->serialize($network, $key->withoutPrivateKey()), $keyIndex, $isLeaf,
        ])) {
            throw new \RuntimeException("Failed to create key");
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function createElectrumKey(int $walletId, PublicKeyInterface $key, int $keyIndex, int $purpose): int
    {
        if (null === $this->createElectrumKeyStmt) {
            $this->createElectrumKeyStmt = $this->pdo->prepare("INSERT INTO key (walletId, childSequence, key, keyIndex, path) values (?,?,?,?,?)");
        }
        $childSequence = 0;
        if (!$this->createElectrumKeyStmt->execute([
            $walletId, $childSequence, $key->getHex(), $keyIndex, $purpose,
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

    /**
     * @param int $walletId
     * @param string $path
     * @return array
     */
    public function loadKeysByPath(int $walletId, string $path): array
    {
        if (null === $this->loadKeyByPathStmt) {
            $this->loadKeysByPathStmt = $this->pdo->prepare("SELECT * FROM key WHERE walletId = ? AND path = ? ORDER BY keyIndex");
        }

        if (!$this->loadKeysByPathStmt->execute([
            $walletId, $path,
        ])) {
            throw new \RuntimeException("Failed to find keys");
        }

        $keys = [];
        while ($key = $this->loadKeysByPathStmt->fetchObject(DbKey::class)) {
            $keys[] = $key;
        }
        return $keys;
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

    public function loadWalletIDsByScriptPubKey(ScriptInterface $script): array
    {
        if (null === $this->loadWalletIdsBySpkStmt) {
            $this->loadWalletIdsBySpkStmt = $this->pdo->prepare("SELECT walletId from script where scriptPubKey = ?");
        }
        if (!$this->loadWalletIdsBySpkStmt->execute([
            $script->getHex(),
        ])) {
            throw new \RuntimeException("Failed to query script");
        }
        return $this->loadWalletIdsBySpkStmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function markUtxoSpent(int $walletId, OutPointInterface $utxoOutPoint, BufferInterface $spendTxid, int $spendIdx)
    {
        $sql = sprintf("UPDATE utxo SET spentTxid = ?, spentIdx = ? WHERE walletId = ? and txid = ? and vout = ?");
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute([
            $spendTxid->getHex(), $spendIdx, $walletId,
            $utxoOutPoint->getTxId()->getHex(), $utxoOutPoint->getVout(),
        ])) {
            throw new \RuntimeException("Failed to update utxos with spend");
        }
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("failed to delete utxo");
        }
    }
    public function markUtxoUnspent(int $walletId, OutPointInterface $utxoOutPoint)
    {
        $sql = sprintf("UPDATE utxo SET spentTxid = NULL, spentIdx = NULL WHERE walletId = ? and txid = ? and vout = ?");
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute([
            $walletId, $utxoOutPoint->getTxId()->getHex(), $utxoOutPoint->getVout(),
        ])) {
            throw new \RuntimeException("Failed to mark utxo unspent");
        }
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("failed to mark utxo unspent - row != 1");
        }
    }

    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getWalletUtxosWithUnspentUtxo(OutPointInterface $outPoint): array
    {
        if (null === $this->findWalletsWithUtxoStmt) {
            $this->findWalletsWithUtxoStmt = $this->pdo->prepare("SELECT walletId, scriptId, txid, vout, spentTxid, spentIdx, value, scriptPubKey from utxo where txid = ? and vout = ? and spentTxid IS NULL");
        }
        if (!$this->findWalletsWithUtxoStmt->execute([
            $outPoint->getTxId()->getHex(),
            $outPoint->getVout(),
        ])) {
            throw new \RuntimeException("failed to search utxos");
        }
        $utxos = [];
        while ($utxo = $this->findWalletsWithUtxoStmt->fetchObject(DbUtxo::class)) {
            $utxos[] = $utxo;
        }
        return $utxos;
    }

    public function createUtxo(int $walletId, int $dbScriptId, OutPointInterface $outPoint, TransactionOutputInterface $txOut)
    {
        if (null === $this->createUtxoStmt) {
            $this->createUtxoStmt = $this->pdo->prepare("INSERT INTO utxo (walletId, scriptId, txid, vout, value, scriptPubKey) values (?, ?, ?, ?, ?, ?)");
        }

        if (!$this->createUtxoStmt->execute([
            $walletId, $dbScriptId,
            $outPoint->getTxId()->getHex(), $outPoint->getVout(),
            $txOut->getValue(), $txOut->getScript()->getHex(),
        ])) {
            throw new \RuntimeException("failed to create utxo");
        }
    }
    public function deleteUtxo(int $walletId, BufferInterface $txId, int $vout)
    {
        if (null === $this->deleteUtxoStmt) {
            $this->deleteUtxoStmt = $this->pdo->prepare("DELETE FROM utxo where walletId = ? and txid = ? and vout = ?");
        }

        if (!$this->deleteUtxoStmt->execute([
            $walletId, $txId->getHex(), $vout,
        ])) {
            throw new \RuntimeException("failed to delete utxo");
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
    public function getWalletScriptPubKeys(int $walletId): array
    {
        if (null === $this->getWalletScriptPubKeysStmt) {
            $this->getWalletScriptPubKeysStmt = $this->pdo->prepare("SELECT scriptPubKey from script where walletId = ?");
        }
        if (!$this->getWalletScriptPubKeysStmt->execute([$walletId])) {
            throw new \RuntimeException("Failed to query utxos");
        }
        $scriptPubKey = [];
        while ($utxo = $this->getWalletUtxosStmt->fetchColumn(0)) {
            $scriptPubKey[] = $utxo;
        }
        return $scriptPubKey;
    }
    /**
     * @param int $walletId
     * @return DbUtxo[]
     */
    public function getUnspentWalletUtxos(int $walletId): array
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

    public function fetchBlockTxs(BufferInterface $hash, array $walletIds): array
    {
        $stmt = $this->pdo->query("SELECT * FROM tx where confirmedHash = ? AND walletId IN (" . implode(",", $walletIds) . ")");
        if (!$stmt->execute([$hash->getHex()])) {
            throw new \RuntimeException("failed to fetch block txns");
        }
        $results = [];
        while ($tx = $stmt->fetchObject(DbWalletTx::class)) {
            $results[] = $tx;
        }
        return $results;
    }

    public function createTx(int $walletId, BufferInterface $txid, int $valueChange, int $status, ?string $blockHashHex, ?int $blockHeight): bool
    {
        if (null === $this->createTxStmt) {
            $this->createTxStmt = $this->pdo->prepare("INSERT INTO tx (walletId, txid, valueChange, status, confirmedHash, confirmedHeight) values (?, ?, ?, ?, ?, ?)");
        }
        return $this->createTxStmt->execute([
            $walletId, $txid->getHex(), $valueChange,
            $status, $blockHashHex, $blockHeight,
        ]);
    }

    public function updateTxStatus(int $walletId, BufferInterface $txid, int $status): bool
    {
        if (null === $this->updateTxStatusStmt) {
            $this->updateTxStatusStmt = $this->pdo->prepare("UPDATE tx SET status = ? where walletId = ? and txid = ?");
        }
        return $this->updateTxStatusStmt->execute([
            $status, $walletId, $txid->getHex(),
        ]);
    }

    public function deleteTx(int $walletId, BufferInterface $txid): bool
    {
        if (null === $this->deleteTxStmt) {
            $this->deleteTxStmt = $this->pdo->prepare("DELETE FROM tx WHERE walletId = ? and txid = ?");
        }
        return $this->deleteTxStmt->execute([
            $walletId, $txid->getHex(),
        ]);
    }

    public function deleteTxUtxos(BufferInterface $txId, array $walletIds): array
    {
        $stmt = $this->pdo->query("DELETE FROM utxo WHERE txid = ? and walletId IN (" . implode(",", $walletIds) . ")");
        if (!$stmt->execute([
            $txId->getHex(),
        ])) {
            throw new \RuntimeException("failed to delete utxos");
        }
    }

    public function unspendTxUtxos(BufferInterface $txId, array $walletIds)
    {
        // todo: index on spentTxid?
        $stmt = $this->pdo->query("UPDATE utxo SET spentTxid = NULL, spentIdx = NULL WHERE spentTxid = ? and walletId IN (" . implode(",", $walletIds) . ")");
        return $stmt->execute([
            $txId->getHex(),
        ]);
    }

    public function getConfirmedBalance(int $walletId): int
    {
        if (null === $this->getConfirmedBalanceStmt) {
            $this->getConfirmedBalanceStmt = $this->pdo->prepare("SELECT SUM(valueChange) as balance FROM tx WHERE walletId = ? and status = 1");
        }
        $this->getConfirmedBalanceStmt->execute([
            $walletId,
        ]);
        return (int) $this->getConfirmedBalanceStmt->fetch()['balance'];
    }

    public function getTransactions(int $walletId): \PDOStatement
    {
        $stmt = $this->pdo->prepare("select * from tx where walletId = ? order by id asc");
        $stmt->execute([
            $walletId,
        ]);
        return $stmt;
    }

    public function saveRawTx(BufferInterface $txId, BufferInterface $tx)
    {
        // todo prepared statement
        $stmt = $this->pdo->prepare("insert into rawTx (txId, tx) values (?, ?)");
        $stmt->execute([
            $txId->getHex(), $tx->getBinary(),
        ]);
        return $stmt;
    }

    public function getRawTx(BufferInterface $txId): string
    {
        // todo prepared statement
        $stmt = $this->pdo->prepare("select tx from rawTx where txId = ?");
        $stmt->execute([
            $txId->getHex(),
        ]);
        if (!($tx = $stmt->fetchColumn(0))) {
            throw new \RuntimeException("failed to find raw Tx?");
        }
        return $tx;
    }
}
