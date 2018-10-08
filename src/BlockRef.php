<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Buffertools\BufferInterface;

class BlockRef
{
    private $hash;
    private $height;

    public function __construct(int $height, BufferInterface $hash) {
        $this->height = $height;
        $this->hash = $hash;
    }

    public function getHash(): BufferInterface {
        return $this->hash;
    }
    public function getHeight(): int {
        return $this->height;
    }
}
