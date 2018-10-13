<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\SignData;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\Buffer;

class DbUtxo
{
    /**
     * @var int
     */
    private $id;
    /**
     * @var int
     */
    private $walletId;
    /**
     * @var int
     */
    private $scriptId;
    /**
     * @var string
     */
    private $txid;
    /**
     * @var int
     */
    private $vout;
    /**
     * @var int
     */
    private $value;
    /**
     * @var string
     */
    private $scriptPubKey;
    /**
     * @var string
     */
    private $spendTxid;
    /**
     * @var int
     */
    private $spendIdx;

    public function getTxOut(): TransactionOutputInterface
    {
        return new TransactionOutput((int) $this->value, ScriptFactory::fromHex($this->scriptPubKey));
    }
    public function getDbScript(DB $db): DbScript
    {
        return $db->loadScriptByScriptPubKey((int) $this->walletId, ScriptFactory::fromHex($this->scriptPubKey));
    }
    public function getOutPoint(): OutPointInterface
    {
        return new OutPoint(Buffer::hex($this->txid), (int) $this->vout);
    }
    public function getSpendOutPoint(): ?OutPointInterface
    {
        if ($this->spendTxid && $this->spendIdx) {
            return new OutPoint(Buffer::hex($this->spendTxid), (int) $this->spendIdx);
        }
        return null;
    }
}
