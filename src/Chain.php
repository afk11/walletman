<?php

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class Chain
{
    /**
     * @var Params
     */
    private $chainParams;

    // Blocks

    /**
     * @var int
     */
    private $bestBlockHeight;

    // Headers

    /**
     * @var BlockHeaderInterface
     */
    private $bestHeader;

    /**
     * @var BufferInterface
     */
    private $bestHeaderHash;

    /**
     * @var int
     */
    private $bestHeaderHeight;

    private $hashMapToHeight = [];
    private $heightMapToHash = [];

    public function __construct(Params $params)
    {
        $this->chainParams = new \BitWasp\Bitcoin\Chain\Params(new Math());
        $genesis = $this->chainParams->getGenesisBlock();

        $this->bestBlockHeight = 0;

        $this->bestHeader = $genesis->getHeader();
        $this->bestHeaderHash = $this->bestHeader->getHash();
        $this->bestHeaderHeight = 0;

        $this->hashMapToHeight[$this->bestHeaderHash->getBinary()] = 0;
        $this->heightMapToHash[0] = $this->bestHeaderHash->getBinary();
    }

    public function getBestBlockHeight() {
        return $this->bestBlockHeight;
    }
    public function getBestHeaderHeight() {
        return $this->bestHeaderHeight;
    }
    public function getBestHeader() {
        return $this->bestHeader;
    }
    public function getBestHeaderHash() {
        return $this->bestHeaderHash;
    }
    public function getBlockHash(int $headerHeight) {
        if (!array_key_exists($headerHeight, $this->heightMapToHash)) {
            throw new \RuntimeException();
        }
        return new Buffer($this->heightMapToHash[$headerHeight]);
    }
    public function addNextHeader(int $height, BufferInterface $hash, BlockHeaderInterface $header) {
        if ($height !== 1 + $this->bestHeaderHeight) {
            throw new \RuntimeException();
        }
        if (!$this->bestHeaderHash->equals($header->getPrevBlock())) {
            throw new \RuntimeException();
        }
        $this->bestHeaderHeight = $height;
        $this->bestHeader = $header;
        $this->bestHeaderHash = $hash;
        $this->hashMapToHeight[$hash->getBinary()] = $height;
        $this->heightMapToHash[$height] = $hash->getBinary();
        echo "new header: $height {$hash->getHex()}\n";
    }
    public function addNextBlock(int $height, BufferInterface $hash, $block) {
        if ($height !== 1 + $this->bestBlockHeight) {
            throw new \RuntimeException("height $height != 1 + {$this->bestBlockHeight}");
        }
        if (!array_key_exists($hash->getBinary(), $this->hashMapToHeight)) {
            throw new \RuntimeException("block hash doesn't exist in map");
        }
        if ($this->hashMapToHeight[$hash->getBinary()] !== $height) {
            throw new \RuntimeException("height for hash {$this->hashMapToHeight[$hash->getBinary()]} != input $height");
        }
        //echo "new block: $height {$hash->getHex()}\n";
        $this->bestBlockHeight = $height;
    }
}
