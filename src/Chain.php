<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbHeader;

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
     * @var array - maps hashes to height
     */
    private $hashMapToHeight = [];

    /**
     * @var array - maps height to hash
     */
    private $heightMapToHash = [];

    public function __construct(array $tailHashes, BlockHeaderInterface $bestHeader, int $bestBlockHeight)
    {
        $this->bestHeader = $bestHeader;
        $this->bestHeaderHash = $bestHeader->getHash();
        $this->bestBlockHeight = $bestBlockHeight;

        $numHashes = count($tailHashes);
        $this->heightMapToHash = $tailHashes;
        $this->heightMapToHash[] = $this->bestHeaderHash->getBinary();
        for ($height = 0; $height <= $numHashes; $height++) {
            $this->hashMapToHeight[$this->heightMapToHash[$height]] = $height;
        }
    }

    public function setStartBlock(BlockRef $blockRef)
    {
        $this->startBlockRef = $blockRef;
        $this->bestBlockHeight = $blockRef->getHeight();
    }

    public function getBestBlockHeight(): int
    {
        return $this->bestBlockHeight;
    }

    public function getBestHeaderHeight(): int
    {
        return $this->hashMapToHeight[$this->bestHeaderHash->getBinary()];
    }

    public function getBestHeader(): BlockHeaderInterface
    {
        return $this->bestHeader;
    }

    public function getBestHeaderHash(): BufferInterface
    {
        return $this->bestHeaderHash;
    }

    public function getBlockHash(int $headerHeight)
    {
        if (!array_key_exists($headerHeight, $this->heightMapToHash)) {
            throw new \RuntimeException("Failed to find block height {$headerHeight}");
        }
        return new Buffer($this->heightMapToHash[$headerHeight]);
    }

    public function acceptHeader(DB $db, BufferInterface $hash, BlockHeaderInterface $header, DbHeader &$headerIndex = null)
    {
        $hashBin = $hash->getBinary();
        if (array_key_exists($hashBin, $this->hashMapToHeight)) {
            $headerIndex = $db->getHeader($hash);
            return true;
        }

        $prevBin = $header->getPrevBlock()->getBinary();
        if (!array_key_exists($prevBin, $this->hashMapToHeight)) {
            throw new \RuntimeException("prevHeader not known");
        }

        $height = $this->hashMapToHeight[$prevBin] + 1;
        if (null !== $this->startBlockRef && $this->startBlockRef->getHeight() === $height) {
            if (!$hash->equals($this->startBlockRef->getHash())) {
                throw new \RuntimeException("header {$hash->getHex()}) doesn't match start block {$this->startBlockRef->getHash()->getHex()}");
            }
        }

        $db->addHeader($height, $hash, $header, 1);
        $headerIndex = $db->getHeader($hash);

        $this->bestHeader = $header;
        $this->bestHeaderHash = $hash;
        $this->hashMapToHeight[$hash->getBinary()] = $height;
        $this->heightMapToHash[$height] = $hash->getBinary();
        return true;
    }

    public function addNextBlock(DB $db, int $height, BufferInterface $hash, $block)
    {
        if ($height !== 1 + $this->bestBlockHeight) {
            throw new \RuntimeException("height $height != 1 + {$this->bestBlockHeight}");
        }
        if (!array_key_exists($hash->getBinary(), $this->hashMapToHeight)) {
            throw new \RuntimeException("block hash doesn't exist in map: {$hash->getHex()}");
        }
        if ($this->hashMapToHeight[$hash->getBinary()] !== $height) {
            throw new \RuntimeException("height for hash {$this->hashMapToHeight[$hash->getBinary()]} != input $height");
        }
        $this->bestBlockHeight = $height;

        $db->setBlockReceived($hash);
    }
}
