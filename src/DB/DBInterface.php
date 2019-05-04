<?php declare(strict_types=1);

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

interface DBInterface
{
    public function getPdo(): \PDO;

    public function createWalletTable();

    public function createTxTable();

    public function createUtxoTable();

    public function createKeyTable();

    public function createScriptTable();

    public function createHeaderTable();

    public function getHeader(BufferInterface $hash): ?DbHeader;

    public function getGenesisHeader(): ?DbHeader;

    public function deleteBlockIndex();

    public function deleteBlocksFromIndex();

    public function deleteWalletTxs();

    public function deleteWalletUtxos();

    public function markBirthdayHistoryValid(int $height);

    public function addHeader(int $height, \GMP $work, BufferInterface $hash, BlockHeaderInterface $header, int $status): bool;

    public function setBlockReceived(BufferInterface $hash): bool;

    /**
     * @return DbWallet[]
     */
    public function loadAllWallets(): array;

    public function loadWallet(string $identifier): DbWallet;

    public function checkWalletExists(string $identifier): bool;

    public function loadBip44WalletKey(int $walletId): DbKey;

    public function createWallet(string $identifier, int $type, ?int $gapLimit, ?BlockRef $birthday): int;

    public function createBip32Key(int $walletId, Base58ExtendedKeySerializer $serializer, string $path, HierarchicalKey $key, NetworkInterface $network, int $keyIndex, bool $isLeaf): int;
    public function createElectrumKey(int $walletId, PublicKeyInterface $key, int $keyIndex, int $purpose): int;

    public function loadKeyByPath(int $walletId, string $path, int $keyIndex): DbKey;

    /**
     * @param int $walletId
     * @param string $path
     * @return DbKey[]
     */
    public function loadKeysByPath(int $walletId, string $path): array;

    public function createScript(int $walletId, string $keyIdentifier, string $scriptPubKey, string $redeemScript = null, string $witnessScript = null): int;

    public function loadScriptByKeyId(int $walletId, string $keyIdentifier): ?DbScript;

    public function loadScriptByScriptPubKey(int $walletId, ScriptInterface $script): ?DbScript;

    /**
     * @param ScriptInterface $script
     * @return int[]
     */
    public function loadWalletIDsByScriptPubKey(ScriptInterface $script): array;

    public function markUtxoSpent(int $walletId, OutPointInterface $utxoOutPoint, BufferInterface $spendTxid, int $spendIdx);


    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getWalletUtxosWithUnspentUtxo(OutPointInterface $outPoint): array;

    public function createUtxo(int $walletId, int $dbScriptId, OutPointInterface $outPoint, TransactionOutputInterface $txOut);
    public function deleteUtxo(int $walletId, BufferInterface $txId, int $vout);

    public function searchUnspentUtxo(int $walletId, OutPointInterface $outPoint): ?DbUtxo;

    /**
     * @param int $walletId
     * @return DbUtxo[]
     */
    public function getUnspentWalletUtxos(int $walletId): array;

    /**
     * @param BufferInterface $hash
     * @param array $walletIds
     * @return DbBlockTx[]
     */
    public function fetchBlockTxs(BufferInterface $hash, array $walletIds): array;
    public function deleteTxUtxos(BufferInterface $txId, array $walletIds);
    public function unspendTxUtxos(BufferInterface $txId, array $walletIds);
    public function createTx(int $walletId, BufferInterface $txid, int $valueChange, int $status, bool $coinbase, ?string $blockHashHex, ?int $blockHeight): bool;
    public function updateTxStatus(int $walletId, BufferInterface $txid, int $status): bool;

    public function getConfirmedBalance(int $walletId): int;

    public function getTransactions(int $walletId, bool $includeRejected = false): \PDOStatement;
    public function getTransaction(int $walletId, BufferInterface $txId): ?DbWalletTx;
    public function saveRawBlock(BufferInterface $blockHash, BufferInterface $blockData): bool;
    public function getRawBlock(BufferInterface $blockHash): ?string;
    public function deleteRawBlock(BufferInterface $blockHash): bool;
}
