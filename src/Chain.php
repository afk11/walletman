<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DBInterface;

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
     * @var \GMP
     */
    private $bestHeaderWork;

    /**
     * Initialized in init
     * @var int
     */
    private $bestBlockHeight;

    /**
     * allows for tracking more than one chain
     * @var int[] - maps hashes to height
     */
    private $hashMapToHeight = [];

    /**
     * locked to a single chain
     * @var array - maps height to hash
     */
    private $heightMapToHash = [];

    /**
     * @var ProofOfWork
     */
    private $proofOfWork;

    public function __construct(ProofOfWork $proofOfWork)
    {
        $this->proofOfWork = $proofOfWork;
    }

    public function init(DBInterface $db, ParamsInterface $params)
    {
        $genesisHeader = $params->getGenesisBlockHeader();
        $genesisHash = $db->getBlockHash(0);
        if ($genesisHash instanceof BufferInterface) {
            $haveGenesis = true;
            if (!$genesisHeader->getHash()->equals($genesisHash)) {
                throw new \RuntimeException("Database has different genesis hash!");
            }
        } else {
            $genesisHash = $genesisHeader->getHash();
            $haveGenesis = false;
        }

        if (!$haveGenesis) {
            $work = $this->proofOfWork->getWork($genesisHeader->getBits());
            $genesisHeight = 0;
            $db->addHeader($genesisHeight, $work, $genesisHash, $genesisHeader, DbHeader::BLOCK_VALID);
            $this->hashMapToHeight[$genesisHash->getBinary()] = $genesisHeight;
            $this->heightMapToHash[$genesisHeight] = $genesisHash->getBinary();
            $bestHeader = $genesisHeader;
            $bestHeaderHash = $genesisHash;
            $bestBlockHeight = $genesisHeight;
            $bestHeaderWork = $work;
        } else {
            // todo: this just takes the last received header.
            // need to solve for this instead

            // step 1: load (or iterate over) ALL height/hash/headers
            $stmt = $db->getPdo()->prepare("SELECT * FROM header order by height ASC");
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
            while ($row = $stmt->fetchObject(DbHeader::class)) {
                /** @var DbHeader $row */
                $hash = $row->getHash();
                $hashKey = $hash->getBinary();
                $height = $row->getHeight();
                $header = $row->getHeader();
                $this->hashMapToHeight[$hashKey] = $height;
                $tmpPrev[$hashKey] = $header->getPrevBlock()->getBinary();

                if ($height === 0) {
                    $candidate = new ChainCandidate();
                    $candidate->work = gmp_init($row->getWork(), 10);
                    $candidate->status = $row->getStatus();
                    $candidate->dbHeader = $row;
                    $candidate->bestBlockHeight = 0;
                    $candidates[$hashKey] = $candidate;
                } else {
                    if ($row->getStatus() === DbHeader::HEADER_VALID || $row->getStatus() === DbHeader::BLOCK_VALID) {
                        $prevKey = $header->getPrevBlock()->getBinary();
                        $newWork = $this->proofOfWork->getWork($header->getBits());
                        if (array_key_exists($prevKey, $candidates)) {
                            /** @var ChainCandidate $old */
                            $old = $candidates[$prevKey];
                            $candidate = new ChainCandidate();
                            $candidate->work = gmp_add($old->work, $newWork);
                            $candidate->status = $row->getStatus();
                            if ($row->getStatus() === DbHeader::BLOCK_VALID) {
                                $candidate->bestBlockHeight = $row->getHeight();
                            } else {
                                $candidate->bestBlockHeight = $old->bestBlockHeight;
                            }
                            $candidate->dbHeader = $row;
                            $candidates[$hashKey] = $candidate;
                            unset($candidates[$prevKey]);
                        } else {
                            // previous is not a candidate, but it's valid
                            // we assume it extends a header we have in a chain already
                            if (!($prev = $db->getHeader($header->getPrevBlock()))) {
                                throw new \RuntimeException("FATAL: could not find prev block");
                            }

                            $candidate = new ChainCandidate();
                            $candidate->work = gmp_add(gmp_init($prev->getWork(), 10), $newWork);
                            $candidate->status = $row->getStatus();
                            if ($row->getStatus() === DbHeader::BLOCK_VALID) {
                                $candidate->bestBlockHeight = $row->getHeight();
                            }
                            // todo: how do we get bestBlockHeight here? abuse tmpPrev?
                            $candidate->dbHeader = $row;
                            $candidates[$hashKey] = $candidate;
                        }
                    }
                }
            }

            usort($candidates, function (ChainCandidate $a, ChainCandidate $b) {
                return gmp_cmp($a->work, $b->work);
            });

            /** @var ChainCandidate $best */
            $best = end($candidates);
            $bestKey = $best->dbHeader->getHash()->getBinary();
            $height = $best->dbHeader->getHeight();

            // unwind back to genesis block
            while (array_key_exists($bestKey, $tmpPrev)) {
                $this->heightMapToHash[$height] = $bestKey;
                $bestKey = $tmpPrev[$bestKey];
                $height--;
            }

            // EOB
            $bestHeader = $best->dbHeader->getHeader();
            $bestHeaderHash = $best->dbHeader->getHash();
            $bestBlockHeight = $db->getBestBlockHeight();
            $bestHeaderWork = $best->work;
        }

        $this->bestHeader = $bestHeader;
        $this->bestHeaderHash = $bestHeaderHash;
        $this->bestHeaderWork = $bestHeaderWork;
        $this->bestBlockHeight = $bestBlockHeight;
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

    public function acceptHeader(DBInterface $db, BufferInterface $hash, BlockHeaderInterface $header, DbHeader $headerPrev, DbHeader &$headerIndex = null)
    {
        $headerIndex = $db->getHeader($hash);
        if ($headerIndex) {
            if (($headerIndex->getStatus() & DbHeader::HEADER_VALID) == 0) {
                return false;
            }
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

        $work = gmp_add(gmp_init($headerPrev->getWork(), 10), $this->proofOfWork->getWork($header->getBits()));

        $headerIndex = $this->acceptHeaderToIndex($db, $height, $work, $hash, $header);

        return true;
    }

    public function acceptBlock(DBInterface $db, BufferInterface $hash, BlockInterface $block)
    {
        $header = $block->getHeader();
        $prevIdx = $db->getHeader($header->getPrevBlock());
        if (!$prevIdx) {
            // no prev block, rabble
            return false;
        }

        if (!$this->acceptHeader($db, $hash, $header, $prevIdx, $headerIdx)) {
            // who knows what that was
            return false;
        }

        /** @var DbHeader $headerIdx */
        if ($headerIdx->getStatus() == DbHeader::BLOCK_VALID) {
            return true;
        }

        $db->setBlockReceived($hash);

        if ($this->heightMapToHash[$headerIdx->getHeight()] == $hash->getBinary()) {
            $this->bestBlockHeight = $headerIdx->getHeight();
        }
    }

    public function addNextBlock(DBInterface $db, int $height, BufferInterface $hash, BlockInterface $block)
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

    private function acceptHeaderToIndex(DBInterface $db, int $height, \GMP $work, BufferInterface $hash, BlockHeaderInterface $header): DbHeader
    {
        $db->addHeader($height, $work, $hash, $header, DbHeader::HEADER_VALID);
        /** @var DbHeader $headerIndex */
        $headerIndex = $db->getHeader($hash);

        $this->hashMapToHeight[$hash->getBinary()] = $height;
        var_dump($work);
        var_dump($this->bestHeaderWork);
        if (gmp_cmp($work, $this->bestHeaderWork) > 0) {
            $this->bestHeader = $header;
            $this->bestHeaderHash = $hash;
            $this->bestHeaderWork = $work;
        }

        return $headerIndex;
    }
}
