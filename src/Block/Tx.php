<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Block;

use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\BufferInterface;

class Tx
{
    /**
     * @var BufferInterface
     */
    private $txid;
    /**
     * @var TransactionInterface
     */
    private $tx;

    /**
     * During block processing this contains references to spendBy outpoints
     * @var Utxo[]
     */
    private $outputs;

    /**
     * Tx constructor.
     * @param BufferInterface $txid
     * @param TransactionInterface $tx
     * @param Utxo[] $outputs
     */
    public function __construct(BufferInterface $txid, TransactionInterface $tx, array $outputs)
    {
        $this->txid = $txid;
        $this->tx = $tx;
        $this->outputs = $outputs;
    }

    public function getTxId(): BufferInterface
    {
        return $this->txid;
    }
    public function getTx(): TransactionInterface
    {
        return $this->tx;
    }

    /**
     * @return Utxo[]
     */
    public function getOutputs(): array
    {
        return $this->outputs;
    }

    public function spendOutput(int $i, OutPointInterface $spendOutPoint)
    {
        if (!array_key_exists($i, $this->outputs)) {
            throw new \InvalidArgumentException();
        }
        if (null !== $this->outputs[$i]->getSpentOutPoint()) {
            throw new \RuntimeException("Already spent");
        }
        $this->outputs[$i] = $this->outputs[$i]->withSpendOutPoint($spendOutPoint);
    }
}
