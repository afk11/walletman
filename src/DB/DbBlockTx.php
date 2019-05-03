<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class DbBlockTx
{
    private $txid;
    private $coinbase;

    public function getTxId(): BufferInterface
    {
        return Buffer::hex($this->txid);
    }
    public function isCoinbase(): bool
    {
        return (bool) $this->coinbase;
    }
}
