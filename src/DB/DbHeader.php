<?php

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\Buffer;

class DbHeader
{
    private $id;
    private $height;
    private $hash;
    private $version;
    private $prevBlock;
    private $merkleRoot;
    private $nbits;
    private $time;
    private $nonce;

    public function getHeight(): int {
        return (int) $this->height;
    }
    public function getHeader(): BlockHeaderInterface {
        return new BlockHeader($this->version, Buffer::hex($this->prevBlock),
            Buffer::hex($this->merkleRoot), $this->time, $this->nbits, $this->nonce);
    }
}
