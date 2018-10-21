<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Transaction\Factory\SignData;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbUtxo;

class PreparedTx
{
    /**
     * @var TransactionInterface
     */
    private $unsignedTx;

    /**
     * @var int
     */
    private $numInputs;

    /**
     * @var DbUtxo[]
     */
    private $utxos;

    /**
     * @var DbScript[]
     */
    private $scripts;

    /**
     *
     * PreparedTx constructor.
     * @param TransactionInterface $tx
     * @param DbUtxo[] $utxos
     * @param DbScript[] $scripts
     */
    public function __construct(TransactionInterface $tx, array $utxos, array $scripts)
    {
        $this->numInputs = count($tx->getInputs());
        if ($this->numInputs !== count($utxos)) {
            throw new \InvalidArgumentException("num scripts should equal num inputs");
        }
        if ($this->numInputs !== count($scripts)) {
            throw new \InvalidArgumentException("num scripts should equal num inputs");
        }

        $this->unsignedTx = $tx;
        $this->utxos = $utxos;
        $this->scripts = $scripts;
    }

    /**
     * @return TransactionInterface
     */
    public function getUnsignedTx(): TransactionInterface
    {
        return $this->unsignedTx;
    }

    public function getTxOut(int $i): TransactionOutputInterface
    {
        if (!array_key_exists($i, $this->utxos)) {
            throw new \LogicException();
        }
        return $this->utxos[$i]->getTxOut();
    }
    /**
     * @param int $i
     * @return SignData
     */
    public function getSignData(int $i): SignData
    {
        if (!array_key_exists($i, $this->scripts)) {
            throw new \LogicException();
        }
        return $this->scripts[$i]->getSignData();
    }
    /**
     * @param int $i
     * @return string
     */
    public function getKeyIdentifier(int $i): string
    {
        if (!array_key_exists($i, $this->scripts)) {
            throw new \LogicException();
        }
        return $this->scripts[$i]->getKeyIdentifier();
    }
}
