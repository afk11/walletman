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
     * @var DbHeader
     */
    private $bestHeaderIndex;

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
            $db->addHeader($genesisHeight, $work, $genesisHash, $genesisHeader, DbHeader::HEADER_VALID | DbHeader::BLOCK_VALID);
        }

        // step 1: load (or iterate over) ALL height/hash/headers
        $stmt = $db->getPdo()->prepare("SELECT * FROM header order by height   ASC");
        $stmt->execute();

        // tmpPrev; associate hash => [0:prevHash, 1:status]
        // candidates: tip-hash => chain info
        // tmpPrev allows us to build up heightMapToHash, ie, bestChain
        // by linking hash => hashPrev. it contains links from all chains
        // genesis hash points to \x00 * 32. with status, we can determine lastBlock
        $tmpPrev = [];
        $candidates = [];
        while ($row = $stmt->fetchObject(DbHeader::class)) {
            /** @var DbHeader $row */
            $hash = $row->getHash();
            $hashKey = $hash->getBinary();
            $height = $row->getHeight();
            $header = $row->getHeader();

            // every header is added to hashMapToHeight
            $this->hashMapToHeight[$hashKey] = $height;
            $tmpPrev[$hashKey] = [$header->getPrevBlock()->getBinary(), $row->getStatus()];

            if ($height === 0) {
                $candidate = new ChainCandidate();
                $candidate->dbHeader = $row;
                $candidate->bestBlockHeight = 0;
                $candidates[$hashKey] = $candidate;
            } else if (($row->getStatus() & DbHeader::HEADER_VALID) != 0) {
                $prevKey = $header->getPrevBlock()->getBinary();
                $candidate = new ChainCandidate();
                $candidate->dbHeader = $row;
                if (array_key_exists($prevKey, $candidates)) {
                    /** @var ChainCandidate $prevTip */
                    $prevTip = $candidates[$prevKey];
                    if (($row->getStatus() & DbHeader::BLOCK_VALID) != 0) {
                        $candidate->bestBlockHeight = $row->getHeight();
                    } else {
                        $candidate->bestBlockHeight = $prevTip->bestBlockHeight;
                    }
                    unset($candidates[$prevKey]);
                } else {
                    // prev is not already a tip.
                    $prev = $db->getHeader($header->getPrevBlock());
                    if (!$prev) {
                        throw new \RuntimeException("FATAL: could not find prev block");
                    }
                    // reduce bestBlockHeight until that index is BLOCK_VALID
                    $candidate->bestBlockHeight = $row->getHeight();
                    for ($blkHash = $row->getHash()->getBinary();
                         ($tmpPrev[$blkHash][1] & DbHeader::BLOCK_VALID) === 0;
                         $blkHash = $tmpPrev[$blkHash][0]
                    ) {
                        $candidate->bestBlockHeight--;
                    }
                }
                $candidates[$hashKey] = $candidate;
            }
        }

        // Sort for greatest work candidate
        usort($candidates, function (ChainCandidate $a, ChainCandidate $b): int {
            return gmp_cmp($a->dbHeader->getWork(), $b->dbHeader->getWork());
        });

        $best = $candidates[count($candidates) - 1];
        $bestKey = $best->dbHeader->getHash()->getBinary();
        $height = $best->dbHeader->getHeight();

        // build up our view of the best chain
        while (array_key_exists($bestKey, $tmpPrev)) {
            $this->heightMapToHash[$height] = $bestKey;
            $bestKey = $tmpPrev[$bestKey][0];
            $height--;
        }

        $this->bestHeaderIndex = $best->dbHeader;
        $this->bestBlockHeight = $best->bestBlockHeight;
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

    public function getBestHeader(): DbHeader
    {
        return $this->bestHeaderIndex;
    }

    public function getBlockHash(int $headerHeight)
    {
        if (!array_key_exists($headerHeight, $this->heightMapToHash)) {
            throw new \RuntimeException("Failed to find block height {$headerHeight}");
        }
        return new Buffer($this->heightMapToHash[$headerHeight]);
    }

    public function acceptHeader(DBInterface $db, BufferInterface $hash, BlockHeaderInterface $header, DbHeader &$headerIndex = null)
    {
        $headerIndex = $db->getHeader($hash);
        if ($headerIndex) {
            if (($headerIndex->getStatus() & DbHeader::HEADER_VALID) == 0) {
                return false;
            }
            return true;
        }

        $prevIndex = $db->getHeader($header->getPrevBlock());
        if ($prevIndex) {
            if (($prevIndex->getStatus() & DbHeader::HEADER_VALID) == 0) {
                echo "invalid header: check status: {$prevIndex->getStatus()}\n";
                return false;
            }
        } else {
            // prev header not known
            return false;
        }

        // PREVHASH in chain to find height somehow
        $height = $prevIndex->getHeight() + 1;
        if (null !== $this->startBlockRef && $this->startBlockRef->getHeight() === $height) {
            if (!$hash->equals($this->startBlockRef->getHash())) {
                throw new \RuntimeException("header {$hash->getHex()}) doesn't match start block {$this->startBlockRef->getHash()->getHex()}");
            }
        }

        $work = gmp_add(gmp_init($prevIndex->getWork(), 10), $this->proofOfWork->getWork($header->getBits()));
        $chainWork = $this->getBestHeader()->getWork();

        $headerIndex = $this->acceptHeaderToIndex($db, $height, $work, $hash, $header);

        if (gmp_cmp($work, $chainWork) > 0) {
            $candidateHashes = [$headerIndex->getHeight() => $headerIndex->getHash()->getBinary()];
            // Unwind until lastCommonHeight and lastCommonHash are determined.

            $lastCommonHash = $header->getPrevBlock();
            $lastCommonHeight = $headerIndex->getHeight() - 1;
            while ($lastCommonHeight != 0 && $this->heightMapToHash[$lastCommonHeight] !== $lastCommonHash->getBinary()) {
                // If the hashes differ, keep our previous attempt
                // in candidateHashes because we need to apply them later
                $candidateHashes[$lastCommonHeight] = $lastCommonHash->getBinary();
                $p = $db->getHeader($lastCommonHash);
                if (null === $p) {
                    throw new \RuntimeException("failed to find prevblock");
                }
                // need for prevBlock, and arguably status too             }
                $lastCommonHash = $p->getHeader()->getPrevBlock();
                $lastCommonHeight--;
            }
            // Delete [lastCommonHeight+1, currentTipHeight]
            for ($i = $lastCommonHeight + 1; $i <= $this->getBestHeader()->getHeight(); $i++) {
                unset($this->heightMapToHash[$i]);
            }
            // Insert [lastCommonHeight+1, candidateTipHeight]
            for ($i = $lastCommonHeight + 1; $i <= $headerIndex->getHeight(); $i++) {
                $this->heightMapToHash[$i] = $candidateHashes[$i];
            }
            $this->bestHeaderIndex = $headerIndex;
            // reality is we shouldn't have to update this unless
            // it was between lastCommonHeight and our old tip.
            if ($this->bestBlockHeight > $lastCommonHeight) {
                $this->bestBlockHeight = $lastCommonHeight;
            }
        }

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

        if (!$this->acceptHeader($db, $hash, $header, $headerIdx)) {
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
        if (gmp_cmp($work, $this->bestHeaderIndex->getWork()) > 0) {
            $this->bestHeaderIndex = $headerIndex;
        }

        return $headerIndex;
    }
}
