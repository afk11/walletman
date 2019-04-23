<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class DbHeader
{
    const HEADER_VALID = 1;
    const BLOCK_VALID = 2;
    private $id;
    private $height;
    private $work;
    private $hash;
    private $status;
    private $version;
    private $prevBlock;
    private $merkleRoot;
    private $nbits;
    private $time;
    private $nonce;

    public function getStatus(): int
    {
        return (int) $this->status;
    }
    public function getWork(): \GMP
    {
        return gmp_init($this->work, 10);
    }
    public function getHeight(): int
    {
        return (int) $this->height;
    }
    public function getHash(): BufferInterface
    {
        return Buffer::hex($this->hash);
    }
    public function getPrevBlock(): BufferInterface
    {
        return Buffer::hex($this->prevBlock);
    }
    public function getHeader(): BlockHeaderInterface
    {
        return new BlockHeader(
            (int) $this->version,
            Buffer::hex($this->prevBlock),
            Buffer::hex($this->merkleRoot),
            (int) $this->time,
            (int) $this->nbits,
            (int) $this->nonce
        );
    }
}
