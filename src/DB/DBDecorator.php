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

class DBDecorator implements DBInterface
{
    /**
     * @var DB
     */
    private $db;

    private $writer;

    public function __construct(DB $db, callable $writerCallback = null)
    {
        $this->db = $db;
        $this->writer = $writerCallback ?: function (string $input) {
            echo $input;
        };
    }

    private function write(string $input)
    {
        call_user_func($this->writer, $input);
    }

    private function format($arg)
    {
        if ($arg instanceof \GMP) {
            return var_export(gmp_strval($arg, 10), true);
        } else if ($arg instanceof BufferInterface) {
            return var_export($arg->getHex(), true);
        } else if ($arg instanceof ScriptInterface) {
            return var_export($arg->getHex(), true);
        }
        return var_export($arg, true);
    }

    private function call(string $func, array $args)
    {
        $strArgs = [];
        foreach ($args as $arg) {
            $strArgs[] = $this->format($arg);
        }
        if (strpos($func, '::')) {
            list(, $func) = explode('::', $func);
        }
        $this->write($func.'('.implode(', ', $strArgs).')');
        $callable = [$this->db, $func];
        if (!is_callable($callable)) {
            throw new \RuntimeException("wut");
        }

        $res = call_user_func_array($callable, $args);
        $this->write(' => ' . $this->format($res) . PHP_EOL);
        return $res;
    }

    public function getPdo(): \PDO
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function getRawTx(BufferInterface $txId): string
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function saveRawTx(BufferInterface $txId, BufferInterface $tx)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createWalletTable()
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    public function createTxTable()
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    public function createUtxoTable()
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    public function createKeyTable()
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    public function createScriptTable()
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    public function createHeaderTable()
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    public function getHeader(BufferInterface $hash): ?DbHeader
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function getBestHeader(): DbHeader
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function getGenesisHeader(): ?DbHeader
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function deleteBlocksFromIndex()
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function deleteBlockIndex()
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function deleteWalletTxs()
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function deleteWalletUtxos()
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function markBirthdayHistoryValid(int $height)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function addHeader(int $height, \GMP $work, BufferInterface $hash, BlockHeaderInterface $header, int $status): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function setBlockReceived(BufferInterface $hash): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * @return DbWallet[]
     */
    public function loadAllWallets(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function loadWallet(string $identifier): DbWallet
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function checkWalletExists(string $identifier): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function loadBip44WalletKey(int $walletId): DbKey
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createWallet(string $identifier, int $type, ?int $gapLimit, ?BlockRef $birthday): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createBip32Key(int $walletId, Base58ExtendedKeySerializer $serializer, string $path, HierarchicalKey $key, NetworkInterface $network, int $keyIndex, bool $isLeaf): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createElectrumKey(int $walletId, PublicKeyInterface $key, int $keyIndex, int $purpose): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function loadKeyByPath(int $walletId, string $path, int $keyIndex): DbKey
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function loadKeysByPath(int $walletId, string $path): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createScript(int $walletId, string $keyIdentifier, string $scriptPubKey, string $redeemScript = null, string $witnessScript = null): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function loadScriptByKeyId(int $walletId, string $keyIdentifier): ?DbScript
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function loadScriptByScriptPubKey(int $walletId, ScriptInterface $script): ?DbScript
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function loadWalletIDsByScriptPubKey(ScriptInterface $script): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function markUtxoSpent(int $walletId, OutPointInterface $utxoOutPoint, BufferInterface $spendTxid, int $spendIdx)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getWalletUtxosWithUnspentUtxo(OutPointInterface $outPoint): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createUtxo(int $walletId, int $dbScriptId, OutPointInterface $outPoint, TransactionOutputInterface $txOut)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function searchUnspentUtxo(int $walletId, OutPointInterface $outPoint): ?DbUtxo
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * @param int $walletId
     * @return DbUtxo[]
     */
    public function getUnspentWalletUtxos(int $walletId): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function createTx(int $walletId, BufferInterface $txid, int $valueChange, int $status, ?string $blockHashHex, ?int $blockHeight): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function updateTxStatus(int $walletId, BufferInterface $txid, int $status): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function getConfirmedBalance(int $walletId): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function getWalletScriptPubKeys(int $walletId): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function getTransactions(int $walletId): \PDOStatement
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function fetchBlockTxs(BufferInterface $hash, array $walletIds): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function deleteTx(int $walletId, BufferInterface $txid): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function deleteTxUtxos(BufferInterface $txId, array $walletIds): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function deleteUtxo(int $walletId, BufferInterface $txId, int $vout)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function unspendTxUtxos(BufferInterface $txId, array $walletIds)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
    public function markUtxoUnspent(int $walletId, OutPointInterface $utxoOutPoint)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
