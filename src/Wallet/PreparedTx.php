<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Transaction\Factory\SignData;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;

class PreparedTx
{
    /**
     * Required parameter - the transaction to sign
     * @var TransactionInterface
     */
    private $tx;

    /**
     * Required state for each transaction input. Each
     * txOut must be provided to at least be able to
     * validate signatures.
     * @var TransactionOutputInterface[]
     */
    private $inputTxOuts;

    /**
     * Optional state, containing per-input ScriptAndSignData.
     * Not usually required for partially signed transactions,
     * but IS absolutely necessary to add the first signatures.
     * @var SignData[]
     */
    private $inputScriptData;

    /**
     * Optional state, containing per-input key identifier.
     * Required in any event in order to sign a transaction,
     * normally influences how the signing private key is obtained.
     * @var string[]
     */
    private $inputKeyIdentifiers;

    /**
     * Private internal state
     * @var int
     */
    private $numInputs;

    /**
     * PreparedTx constructor.
     * @param TransactionInterface $tx
     * @param TransactionOutputInterface[] $txOuts
     * @param SignData[] $scripts
     * @param string[] $keyIdentifiers
     */
    public function __construct(TransactionInterface $tx, array $txOuts, array $scripts, array $keyIdentifiers)
    {
        $this->numInputs = count($tx->getInputs());
        if ($this->numInputs !== count($txOuts)) {
            throw new \InvalidArgumentException("All input txouts are required");
        }
        if ($this->numInputs < count($scripts)) {
            throw new \InvalidArgumentException("passed too many scripts");
        }
        if ($this->numInputs < count($keyIdentifiers)) {
            throw new \InvalidArgumentException("passed too many key identifiers");
        }

        $this->tx = $tx;
        $this->inputTxOuts = $txOuts;
        $this->inputScriptData = $scripts;
        $this->inputKeyIdentifiers = $keyIdentifiers;
    }

    public function getTx(): TransactionInterface
    {
        return $this->tx;
    }

    public function getTxOut(int $i): TransactionOutputInterface
    {
        // check i is within range
        if ($i > $this->numInputs - 1) {
            throw new \LogicException();
        }
        return $this->inputTxOuts[$i];
    }

    public function getSignData(int $i): ?SignData
    {
        // check i is within range
        if ($i > $this->numInputs - 1) {
            throw new \LogicException();
        }
        // script might be set, if so return it
        if (array_key_exists($i, $this->inputScriptData)) {
            return $this->inputScriptData[$i];
        }
        return null;
    }

    public function getKeyIdentifier(int $i): ?string
    {
        // check i is within range
        if ($i > $this->numInputs - 1) {
            throw new \LogicException();
        }
        // script might be set, if so return it
        if (array_key_exists($i, $this->inputKeyIdentifiers)) {
            return $this->inputKeyIdentifiers[$i];
        }
        return null;
    }
}
