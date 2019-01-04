<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\BlockRef;

class DBDecorator implements DBInterface
{
    private $db;

    public function __construct(DB $db)
    {
        $this->db = $db;
    }

    public function getPdo(): \PDO
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createWalletTable()
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createTxTable()
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createUtxoTable()
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }
    public function createKeyTable()
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createScriptTable()
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createHeaderTable()
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getBlockHash(int $height): ?BufferInterface
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getTailHashes(int $height): array
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getHeader(BufferInterface $hash): ?DbHeader
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getBestHeader(): DbHeader
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getHeaderCount(): int
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getBestBlockHeight(): int
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function markBirthdayHistoryValid(int $height)
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function addHeader(int $height, \GMP $work, BufferInterface $hash, BlockHeaderInterface $header, int $status): bool
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function setBlockReceived(BufferInterface $hash): bool
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    /**
     * @return DbWallet[]
     */
    public function loadAllWallets(): array
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }
    public function loadWallet(string $identifier): DbWallet
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function checkWalletExists(string $identifier): bool
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function loadBip44WalletKey(int $walletId): DbKey
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createWallet(string $identifier, int $type, ?int $gapLimit, ?BlockRef $birthday): int
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createKey(int $walletId, Base58ExtendedKeySerializer $serializer, string $path, HierarchicalKey $key, NetworkInterface $network, int $keyIndex, bool $isLeaf): int
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function loadKeyByPath(int $walletId, string $path, int $keyIndex): DbKey
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function loadKeysByPath(int $walletId, string $path): array
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function loadScriptByKeyIdentifier(int $walletId, string $keyIdentifier): ?DbScript
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createScript(int $walletId, string $keyIdentifier, string $scriptPubKey, string $redeemScript = null, string $witnessScript = null): int
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function loadScriptByKeyId(int $walletId, string $keyIdentifier): ?DbScript
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function loadScriptByScriptPubKey(int $walletId, ScriptInterface $script): ?DbScript
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function deleteSpends(int $walletId, OutPointInterface $utxoOutPoint, BufferInterface $spendTxid, int $spendIdx)
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getWalletUtxosWithUnspentUtxo(OutPointInterface $outPoint): array
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createUtxo(DbWallet $dbWallet, DbScript $dbScript, \BitWasp\Wallet\Block\Utxo $utxo)
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function searchUnspentUtxo(int $walletId, OutPointInterface $outPoint): ?DbUtxo
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    /**
     * @param int $walletId
     * @return DbUtxo[]
     */
    public function getUnspentWalletUtxos(int $walletId): array
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function createTx(int $walletId, BufferInterface $txid, int $valueChange)
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getConfirmedBalance(int $walletId): int
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }

    public function getTransactions(int $walletId): \PDOStatement
    {
        echo __FUNCTION__.PHP_EOL;
        return call_user_func_array([$this->db, __FUNCTION__], func_get_args());
    }
}
