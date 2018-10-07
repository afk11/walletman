<?php

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class Chain
{
    // Blocks

    /**
     * @var int
     */
    private $bestBlockHeight;

    /**
     * @var BlockRef
     */
    private $startBlockRef;

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

    public function __construct(array $hashes, BlockHeaderInterface $bestHeader, int $bestBlockHeight)
    {
//        $this->bestBlockHeight = 0;
        $this->bestBlockHeight = $bestBlockHeight;

//        $this->bestHeader = $genesis->getHeader();
//        $this->bestHeaderHash = $this->bestHeader->getHash();
//        $this->bestHeaderHeight = 0;
        $this->bestHeader = $bestHeader;
        $this->bestHeaderHash = $bestHeader->getHash();
        $this->bestHeaderHeight = count($hashes);

        foreach ($hashes as $i => $hash) {
            // todo: what happens if i=0?
            $this->hashMapToHeight[$hash->getBinary()] = $i;
            $this->heightMapToHash[$i] = $hash->getBinary();
        }
    }

    public function setStartBlock(BlockRef $blockRef) {
        $this->startBlockRef = $blockRef;
        $this->bestBlockHeight = $blockRef->getHeight();
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
            throw new \RuntimeException("Failed to find block height {$headerHeight}");
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
        if ($this->startBlockRef && $this->startBlockRef->getHeight() === $height) {
            if (!$hash->equals($this->startBlockRef->getHash())) {
                throw new \RuntimeException("header doesn't match start block");
            }
        }
        $this->bestHeaderHeight = $height;
        $this->bestHeader = $header;
        $this->bestHeaderHash = $hash;
        $this->hashMapToHeight[$hash->getBinary()] = $height;
        $this->heightMapToHash[$height] = $hash->getBinary();
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
        $this->bestBlockHeight = $height;
    }
}
