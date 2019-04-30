<?php

namespace BitWasp\Wallet;

use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbHeader;

class PeerInfo
{
    /**
     * @var DbHeader
     */
    public $bestKnownBlock;
    /**
     * @var BufferInterface|null
     */
    public $lastUnknownBlockHash;

    /**
     * @var DbHeader
     */
    public $lastCommonBlock;
}
