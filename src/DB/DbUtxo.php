<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\Buffer;

class DbUtxo
{
    /**
     * @var string
     */
    private $id;
    /**
     * @var string
     */
    private $walletId;
    /**
     * @var string
     */
    private $scriptId;
    /**
     * @var string
     */
    private $txid;
    /**
     * @var string
     */
    private $vout;
    /**
     * @var string
     */
    private $value;
    /**
     * @var string
     */
    private $scriptPubKey;
    /**
     * @var string
     */
    private $spentTxid;
    /**
     * @var string
     */
    private $spentIdx;

    public function getWalletId(): int
    {
        return (int) $this->walletId;
    }

    public function getValue(): int
    {
        return (int) $this->value;
    }

    public function getTxOut(): TransactionOutputInterface
    {
        return new TransactionOutput((int) $this->value, ScriptFactory::fromHex($this->scriptPubKey));
    }

    public function getDbScript(DBInterface $db): DbScript
    {
        $script = $db->loadScriptByScriptPubKey((int) $this->walletId, ScriptFactory::fromHex($this->scriptPubKey));
        if ($script === null) {
            throw new \RuntimeException("DbScript not in database");
        }
        return $script;
    }

    public function getOutPoint(): OutPointInterface
    {
        return new OutPoint(Buffer::hex($this->txid), (int) $this->vout);
    }

    public function isSpent(): bool
    {
        return $this->spentTxid !== null;
    }
    public function getSpendOutPoint(): ?OutPointInterface
    {
        if ($this->spentTxid != null && $this->spentIdx !== null) {
            return new OutPoint(Buffer::hex($this->spentTxid), (int) $this->spentIdx);
        }
        return null;
    }
}
