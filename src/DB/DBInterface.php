<?php
/**
 * Created by PhpStorm.
 * User: tk
 * Date: 12/23/18
 * Time: 6:21 PM
 */

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
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

    public function createKey(int $walletId, Base58ExtendedKeySerializer $serializer, string $path, HierarchicalKey $key, NetworkInterface $network, int $keyIndex, bool $isLeaf): int;

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

    public function deleteSpends(int $walletId, OutPointInterface $utxoOutPoint, BufferInterface $spendTxid, int $spendIdx);

    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getWalletUtxosWithUnspentUtxo(OutPointInterface $outPoint): array;

    public function createUtxo(DbWallet $dbWallet, DbScript $dbScript, OutPointInterface $outPoint, TransactionOutputInterface $txOut);

    public function searchUnspentUtxo(int $walletId, OutPointInterface $outPoint): ?DbUtxo;

    /**
     * @param int $walletId
     * @return DbUtxo[]
     */
    public function getUnspentWalletUtxos(int $walletId): array;

    /**
     * @param int $walletId
     * @return string[]
     */
    public function getWalletScriptPubKeys(int $walletId): array;

    public function createTx(int $walletId, BufferInterface $txid, int $valueChange, int $status, ?string $blockHashHex, ?int $blockHeight): bool;

    public function getConfirmedBalance(int $walletId): int;

    public function getTransactions(int $walletId): \PDOStatement;
}
