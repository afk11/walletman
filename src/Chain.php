<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbHeader;

class Chain
{
    // Blocks


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
     * Initialized in init
     * @var int
     */
    private $bestBlockHeight;

    /**
     * allows for tracking more than one chain
     * @var array - maps hashes to height
     */
    private $hashMapToHeight = [];

    /**
     * locked to a single chain
     * @var array - maps height to hash
     */
    private $heightMapToHash = [];

    public function init(DB $db, ParamsInterface $params)
    {
        $genesisHeader = $params->getGenesisBlockHeader();
        if (($genesisHash = $db->getBlockHash(0))) {
            $haveGenesis = true;
            if (!$genesisHeader->getHash()->equals($genesisHash)) {
                throw new \RuntimeException("Database has different genesis hash!");
            }
        } else {
            $genesisHash = $genesisHeader->getHash();
            $haveGenesis = false;
        }

        if (!$haveGenesis) {
            $this->acceptHeaderToIndex($db, 0, $genesisHash, $genesisHeader);
            $db->setBlockReceived($genesisHash);
            $bestHeader = $genesisHeader;
            $bestHeaderHash = $genesisHash;
            $bestBlockHeight = 0;
        } else {
            // todo: this just takes the last received header.
            // need to solve for this instead
            $bestIndex = $db->getBestHeader();
            // EOB
            $bestHeader = $bestIndex->getHeader();
            $bestHeaderHash = $bestIndex->getHash();
            $bestBlockHeight = $db->getBestBlockHeight();

            $tailHashes = $db->getTailHashes($bestIndex->getHeight());
            $numHashes = count($tailHashes);
            $this->heightMapToHash = $tailHashes;
            $this->heightMapToHash[] = $bestHeaderHash->getBinary();
            for ($height = 0; $height <= $numHashes; $height++) {
                $this->hashMapToHeight[$this->heightMapToHash[$height]] = $height;
            }

            $this->experimentalInit($db);
        }

        $this->bestHeader = $bestHeader;
        $this->bestHeaderHash = $bestHeaderHash;
        $this->bestBlockHeight = $bestBlockHeight;
    }
    private function experimentalInit(DB $db) {
        echo "EXPERIMENTAL INIT\n";
        // step 1: load (or iterate over) ALL height/hash/headers
        $stmt = $db->getPdo()->prepare("SELECT height, hash, status, version, prevBlock, merkleRoot, nbits, time, nonce FROM header order by height ASC");
        if (!$stmt->execute()) {
            throw new \RuntimeException("Failed to load block / header index");
        }

        // associate height/hash/prevHash
        // tmpPrev allows us to build up heightMapToHash, ie, bestChain
        // by linking hash => hashPrev. it contains links from all chains
        // genesis hash points to \x00 * 32
        $tmpPrev = [];
        // candidates maps hash => candidate, we prune this as we
        // sync
        $candidates = [];
        $hashMapToHeight = [];
        while ($row = $stmt->fetchObject(DbHeader::class)) {
            /** @var DbHeader $row */
            $hash = $row->getHash();
            $height = $row->getHeight();
            $header = $row->getHeader();
            $hashMapToHeight[$hash->getBinary()] = $height;
            $tmpPrev[$hash->getBinary()] = $header->getPrevBlock()->getBinary();

            if ($height === 0) {
                $candidate = new ChainCandidate();
                $candidate->work = 0;
                $candidate->dbHeader = $row;
                $candidates[$hash->getBinary()] = $candidate;
            } else {
                if ($row->getStatus() === DbHeader::HEADER_VALID) {

                }
            }
        }
        print_r($hashMapToHeight);
        print_r($candidates);
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

        $headerIndex = $this->acceptHeaderToIndex($db, $height, $hash, $header);

        // todo: shouldn't really be here, need to verify with respect to chain
        $this->bestHeaderHash = $hash;
        $this->bestHeader = $header;

        return true;
    }

    public function addNextBlock(DB $db, int $height, BufferInterface $hash, BlockInterface $block)
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

    private function acceptHeaderToIndex(DB $db, int $height, BufferInterface $hash, BlockHeaderInterface $header): DbHeader
    {
        $db->addHeader($height, $hash, $header, DbHeader::HEADER_VALID);
        $headerIndex = $db->getHeader($hash);

        $this->hashMapToHeight[$hash->getBinary()] = $height;
        $this->heightMapToHash[$height] = $hash->getBinary();

        return $headerIndex;
    }

    public function processBlock(DB $db, int $height, BufferInterface $hash, BlockInterface $block)
    {
    }
}
