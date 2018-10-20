<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Bitcoin\Utxo\Utxo;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbScript;

class TxUpdate
{
    private $txid;
    private $valueChange = 0;
    private $spends = [];
    private $utxos = [];
    public function __construct(BufferInterface $txid)
    {
        $this->txid = $txid;
    }
    public function inputSpendsMine(int $i, OutPointInterface $utxoOutpoint, TransactionOutputInterface $txOut)
    {
        $this->valueChange -= $txOut->getValue();
        $this->spends[] = [new OutPoint($this->txid, $i), $utxoOutpoint];
    }
    public function outputIsMine(int $i, TransactionOutputInterface $txOut, DbScript $script)
    {
        $this->valueChange += $txOut->getValue();
        $this->utxos[] = [new Utxo(new OutPoint($this->txid, $i), $txOut), $script];
    }

    public function getTxId(): BufferInterface
    {
        return $this->txid;
    }
    public function getValueChange(): int
    {
        return $this->valueChange;
    }
    public function getSpends(): array
    {
        return $this->spends;
    }
    public function getUtxos(): array
    {
        return $this->utxos;
    }
}
