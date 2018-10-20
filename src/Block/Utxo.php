<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Block;

use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;

class Utxo
{
    /**
     * @var TransactionOutputInterface
     */
    private $txOut;

    /**
     * @var OutPointInterface
     */
    private $outPoint;

    /**
     * @var OutPointInterface|null
     */
    private $spentOutPoint;

    public function __construct(OutPointInterface $outPoint, TransactionOutputInterface $txOut, ?OutPointInterface $spentOutPoint)
    {
        $this->txOut = $txOut;
        $this->outPoint = $outPoint;
        $this->spentOutPoint = $spentOutPoint;
    }

    /**
     * @return TransactionOutputInterface
     */
    public function getTxOut(): TransactionOutputInterface
    {
        return $this->txOut;
    }

    /**
     * @return OutPointInterface
     */
    public function getOutPoint(): OutPointInterface
    {
        return $this->outPoint;
    }

    /**
     * @return OutPointInterface|null
     */
    public function getSpentOutPoint(): ?OutPointInterface
    {
        return $this->spentOutPoint;
    }

    /**
     * @param OutPointInterface $outPoint
     * @return Utxo
     */
    public function withSpendOutPoint(OutPointInterface $outPoint): self
    {
        if (null !== $this->spentOutPoint) {
            throw new \LogicException();
        }
        $utxo = clone $this;
        $utxo->spentOutPoint = $outPoint;
        return $utxo;
    }
}
