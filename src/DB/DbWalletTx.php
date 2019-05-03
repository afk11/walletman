<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class DbWalletTx
{
    const STATUS_CONFIRMED = 1;
    const STATUS_UNCONFIRMED = 0;
    const STATUS_REJECT = -1;

    private $id;
    private $walletId;
    private $txid;
    private $valueChange;
    private $status;
    private $coinbase;
    private $confirmedHash;
    private $confirmedHeight;

    public function getTxId(): BufferInterface
    {
        return Buffer::hex($this->txid, 32);
    }

    public function getWalletId(): int
    {
        return (int) $this->walletId;
    }

    public function getValueChange(): int
    {
        return (int) $this->valueChange;
    }

    public function getStatus(): int
    {
        return (int) $this->status;
    }

    public function isCoinbase(): bool
    {
        return (bool) $this->coinbase;
    }

    public function getConfirmedHash(): ?BufferInterface
    {
        if ($this->confirmedHash === null) {
            return null;
        }
        return Buffer::hex($this->confirmedHash);
    }

    public function getConfirmedHeight(): ?int
    {
        if ($this->confirmedHeight === null) {
            return null;
        }
        return (int) $this->confirmedHeight;
    }
}
