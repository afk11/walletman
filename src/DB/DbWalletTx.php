<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class DbWalletTx
{
    private $id;
    private $walletId;
    private $txid;
    private $valueChange;

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
}
