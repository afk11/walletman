<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Serializer\Block\BlockHeaderSerializer;
use BitWasp\Bitcoin\Serializer\Block\BlockSerializer;
use BitWasp\Bitcoin\Serializer\Block\BlockSerializerInterface;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializer;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Buffertools\Parser;
use BitWasp\Wallet\DB\DbBlockTx;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbWalletTx;
use BitWasp\Wallet\Wallet\WalletInterface;

class BlockProcessor
{
    /**
     * @var DBInterface
     */
    private $db;

    /**
     * @var BlockSerializerInterface
     */
    private $blockSerializer;

    /**
     * @var WalletInterface[]
     */
    private $wallets = [];

    public function __construct(DBInterface $db, WalletInterface... $wallets)
    {
        $this->db = $db;
        $this->blockSerializer = new BlockSerializer(new Math(), new BlockHeaderSerializer(), new TransactionSerializer());
        foreach ($wallets as $wallet) {
            $this->wallets[$wallet->getDbWallet()->getId()] = $wallet;
        }
    }

    /**
     * just save the block here. process for txs/utxos when we 'activate' this block
     * @param int $height
     * @param BufferInterface $blockHash
     * @param BufferInterface $blockData
     */
    public function saveBlock(int $height, BufferInterface $blockHash, BufferInterface $blockData)
    {
        // 1. receive only wallet
        try {
            if (!$this->db->saveRawBlock($blockHash, $blockData)) {
                throw new \RuntimeException("failed to save block data");
            }
        } catch (\Error $e) {
            echo $e->getMessage().PHP_EOL;
            echo $e->getTraceAsString().PHP_EOL;
            sleep(20);
            die();
        } catch (\Exception $e) {
            echo $e->getMessage().PHP_EOL;
            echo $e->getTraceAsString().PHP_EOL;
            sleep(20);
            die();
        }
    }

    /**
     * apply block loads the block from disk and applies it's effect
     * to the utxo set
     * @param int $height
     * @param BufferInterface $blockHash
     * @param BlockInterface|null $rawBlock
     * @throws \BitWasp\Bitcoin\Exceptions\InvalidHashLengthException
     * @throws \BitWasp\Buffertools\Exceptions\ParserOutOfRange
     */
    public function applyBlock(int $height, BufferInterface $blockHash, BlockInterface & $rawBlock = null)
    {
        if (null === $rawBlock) {
            $raw = $this->db->getRawBlock($blockHash);
            if (!$raw) {
                throw new \RuntimeException("no raw data for block");
            }
            $rawBlock = $this->blockSerializer->fromParser(new Parser(new Buffer($raw)));
        }

        foreach ($rawBlock->getTransactions() as $tx) {
            $this->applyConfirmedTx($height, $blockHash, $tx);
        }
    }

    /**
     * mark txins spent, create new txouts, create tx record for every effected wallet
     * @param int $height
     * @param BufferInterface $blockHash
     * @param TransactionInterface $tx
     * @throws \BitWasp\Bitcoin\Exceptions\InvalidHashLengthException
     */
    public function applyConfirmedTx(int $height, BufferInterface $blockHash, TransactionInterface $tx)
    {
        $txId = null;
        $valueChange = [];
        $isCoinbase = $tx->isCoinbase();
        if (!$isCoinbase) {
            $ins = $tx->getInputs();
            $nIn = count($ins);
            for ($iIn = 0; $iIn < $nIn; $iIn++) {
                $outPoint = $ins[$iIn]->getOutPoint();
                // load this utxo from wallets, and mark spent
                $dbUtxos = $this->db->getWalletUtxosWithUnspentUtxo($outPoint);
                $nUtxos = count($dbUtxos);
                for ($i = 0; $i < $nUtxos; $i++) {
                    $dbUtxo = $dbUtxos[$i];
                    $walletId = $dbUtxo->getWalletId();
                    if (!array_key_exists($walletId, $this->wallets)) {
                        continue;
                    } else if (!array_key_exists($walletId, $valueChange)) {
                        $valueChange[$walletId] = 0;
                    }

                    if (null === $txId) {
                        $txId = $tx->getTxId();
                    }
                    $valueChange[$walletId] -= $dbUtxo->getValue();
                    $this->db->markUtxoSpent($walletId, $outPoint, $txId, $iIn);
                    echo "wallet({$walletId}).utxoSpent {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}\n";
                }
            }
        }

        $outs = $tx->getOutputs();
        $nOut = count($outs);
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $txOut = $outs[$iOut];
            $scriptWalletIds = $this->db->loadWalletIDsByScriptPubKey($txOut->getScript());

            $numIds = count($scriptWalletIds);
            for ($i = 0; $i < $numIds; $i++) {
                $walletId = $scriptWalletIds[$i];
                // does this allow skipping wallets which are already synced? so resync?
                if (!array_key_exists($walletId, $this->wallets)) {
                    continue;
                } else if (!array_key_exists($walletId, $valueChange)) {
                    $valueChange[$walletId] = 0;
                }
                $valueChange[$walletId] += $txOut->getValue();

                $wallet = $this->wallets[$walletId];
                $dbWallet = $wallet->getDbWallet();
                if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
                    if (null === $txId) {
                        $txId = $tx->getTxId();
                    }
                    echo "wallet({$dbWallet->getId()}).newUtxo {$txId->getHex()} {$iOut} {$txOut->getValue()}\n";
                    $this->db->createUtxo($dbWallet->getId(), $script->getId(), new OutPoint($txId, $iOut), $txOut);
                }
            }
        }

        if (count($valueChange) > 0) {
            if (null === $txId) {
                $txId = $tx->getTxId();
            }
            foreach ($valueChange as $walletId => $change) {
                if (!$this->db->createTx($walletId, $txId, $change, DbWalletTx::STATUS_CONFIRMED, $isCoinbase, $blockHash->getHex(), $height)) {
                    throw new \RuntimeException("failed to update tx status");
                }
            }
        }
    }

    /**
     * load block and undo it's effects on the utxo set
     * @param BufferInterface $blockHash
     */
    public function undoBlock(BufferInterface $blockHash)
    {
        $walletIds = [];
        foreach ($this->wallets as $wallet) {
            $walletIds[] = $wallet->getDbWallet()->getId();
        }

        $txs = $this->db->fetchBlockTxs($blockHash, $walletIds);
        foreach ($txs as $tx) {
            /** @var DbBlockTx $tx */
            $this->undoConfirmedTx($tx->getTxId(), $tx->isCoinbase(), $walletIds);
        }
    }

    /**
     * deletes tx utxos, marks inputs unspent, and marks tx rejected
     * @param BufferInterface $txId
     * @param bool $isCoinbase
     * @param int[] $walletIds
     */
    public function undoConfirmedTx(BufferInterface $txId, bool $isCoinbase, array $walletIds)
    {
        if (!$isCoinbase) {
            $this->db->unspendTxUtxos($txId, $walletIds);
        }
        $this->db->deleteTxUtxos($txId, $walletIds);
        foreach ($walletIds as $walletId) {
            if (!$this->db->updateTxStatus($walletId, $txId, DbWalletTx::STATUS_REJECT)) {
                throw new \RuntimeException("failed to update tx status");
            }
        }
    }
}
