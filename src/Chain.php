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
     * @var DbHeader
     */
    private $bestBlockIndex;

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
        $genesisIdx = $db->getGenesisHeader();
        if ($genesisIdx instanceof DbHeader) {
            if (!$genesisHeader->getHash()->equals($genesisIdx->getHash())) {
                throw new \RuntimeException("Database has different genesis hash!");
            }
        } else {
            $work = $this->proofOfWork->getWork($genesisHeader->getBits());
            $db->addHeader(0, $work, $genesisHeader->getHash(), $genesisHeader, DbHeader::HEADER_VALID | DbHeader::BLOCK_VALID);
        }

        // step 1: load (or iterate over) ALL height/hash/headers
        $stmt = $db->getPdo()->prepare("SELECT * FROM header order by height   ASC");
        $stmt->execute();

        // tmpPrev; associate hash => [0:prevHash, 1:status]
        // candidates: tip-hash => chain info
        // tmpPrev allows us to build up heightMapToHash, ie, bestChain
        // by linking hash => hashPrev. it contains links from all chains
        // genesis hash points to \x00 * 32. with status, we can determine lastBlock
        $candidates = [];
        while ($row = $stmt->fetchObject(DbHeader::class)) {
            /** @var DbHeader $row */
            $hash = $row->getHash();
            $hashKey = $hash->getBinary();
            $height = $row->getHeight();
            $header = $row->getHeader();

            // every header is added to hashMapToHeight
            $this->hashMapToHeight[$hashKey] = $height;

            if ($height === 0) {
                $candidates[$hashKey] = $row;
            } else if (($row->getStatus() & DbHeader::HEADER_VALID) != 0) {
                $prevKey = $header->getPrevBlock()->getBinary();
                if (array_key_exists($prevKey, $candidates)) {
                    /** @var DbHeader $prevTip */
                    $prevTip = $candidates[$prevKey];
                    if (($prevTip->getStatus() & DbHeader::BLOCK_VALID) == 0) {
                        // prevTip doesn't have block, so there's no reason to
                        // keep it now since $row replaces it
                        unset($candidates[$prevKey]);
                    } else if (($row->getStatus() & DbHeader::BLOCK_VALID) != 0) {
                        // prevTip does have a block, row has a block. delete.
                        unset($candidates[$prevKey]);
                    }
                    // only leave prevTip if it's BLOCK_VALID but row isn't
                } else {
                    // prev is not already a tip.
                    $prev = $db->getHeader($header->getPrevBlock());
                    if (!$prev) {
                        throw new \RuntimeException("FATAL: could not find prev block");
                    }
                    // reduce bestBlockHeight until that index is BLOCK_VALID
                    $bestBlock = $row;
                    while (($bestBlock->getStatus() & DbHeader::BLOCK_VALID) === 0) {
                        $bestBlock = $db->getHeader($bestBlock->getHeader()->getPrevBlock());
                        if (!$bestBlock) {
                            throw new \RuntimeException("FATAL: could not find prev block");
                        }
                    }

                    // don't already have an entry for the best block, add it
                    if (!array_key_exists($bestBlock->getHash()->getBinary(), $candidates)) {
                        $candidates[$bestBlock->getHash()->getBinary()] = $bestBlock;
                    }
                }
                $candidates[$hashKey] = $row;
            }
        }

        $headerTips = [];
        $blockTips = [];
        foreach ($candidates as $hash => $headerIndex) {
            if (($headerIndex->getStatus() & DbHeader::BLOCK_VALID)) {
                $blockTips[] = $headerIndex;
            }
            $headerTips[] = $headerIndex;
        }

        $sort = function (DbHeader $a, DbHeader $b): int {
            return gmp_cmp($a->getWork(), $b->getWork());
        };

        // Sort for greatest work candidate
        usort($headerTips, $sort);
        usort($blockTips, $sort);

        $bestHeader = $headerTips[count($headerTips) - 1];
        $bestBlock = $blockTips[count($blockTips) - 1];

        $this->bestHeaderIndex = $bestHeader;
        $this->bestBlockIndex = $bestBlock;

        // build up our view of the best chain
        while ($bestHeader !== null && $bestHeader->getHeight() >= 0) {
            $this->heightMapToHash[$bestHeader->getHeight()] = $bestHeader->getHash()->getBinary();
            $bestHeader = $db->getHeader($bestHeader->getHeader()->getPrevBlock());
        }
    }

    public function setStartBlock(BlockRef $blockRef)
    {
        $this->startBlockRef = $blockRef;
        $this->bestBlockHeight = $blockRef->getHeight();
    }

    public function getBestBlock(): DbHeader
    {
        return $this->bestBlockIndex;
    }

    public function getBestBlockHeight(): int
    {
        return $this->getBestBlock()->getHeight();
    }

    public function getBestHeader(): DbHeader
    {
        return $this->bestHeaderIndex;
    }

    /**
     * Returns the header chain block hash at the $headerHeight
     * @param int $headerHeight
     * @return BufferInterface
     * @throws \Exception
     */
    public function getBlockHash(int $headerHeight): BufferInterface
    {
        if (!array_key_exists($headerHeight, $this->heightMapToHash)) {
            throw new \RuntimeException("No chain header with height {$headerHeight}");
        }
        return new Buffer($this->heightMapToHash[$headerHeight]);
    }

    public function acceptHeader(DBInterface $db, BufferInterface $hash, BlockHeaderInterface $header, DbHeader &$headerIndex = null): bool
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

        $work = gmp_add($prevIndex->getWork(), $this->proofOfWork->getWork($header->getBits()));
        $prevTip = $this->getBestHeader();

        $headerIndex = $this->acceptHeaderToIndex($db, $height, $work, $hash, $header);

        if (gmp_cmp($work, $prevTip->getWork()) > 0) {
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
                // need for prevBlock, and arguably status too
                $lastCommonHash = $p->getHeader()->getPrevBlock();
                $lastCommonHeight--;
            }

            // Delete [lastCommonHeight+1, currentTipHeight] from the header chain
            for ($i = $lastCommonHeight + 1; $i <= $this->getBestHeader()->getHeight(); $i++) {
                unset($this->heightMapToHash[$i]);
            }

            // Insert [lastCommonHeight+1, candidateTipHeight] to the header chain
            for ($i = $lastCommonHeight + 1; $i <= $headerIndex->getHeight(); $i++) {
                $this->heightMapToHash[$i] = $candidateHashes[$i];
            }

            // Updates bestHeaderIndex
            $this->bestHeaderIndex = $headerIndex;

            // todo: this will need to be checked, maybe chain init unexpected new candidate code
            if ($this->bestBlockHeight > $lastCommonHeight) {
                $this->bestBlockHeight = $lastCommonHeight;
            }
        }

        return true;
    }

    public function acceptBlock(DBInterface $db, BufferInterface $hash, BlockInterface $block, DbHeader &$headerIndex = null): bool
    {
        $header = $block->getHeader();

        if (!$this->acceptHeader($db, $hash, $header, $headerIndex)) {
            // who knows what that was
            return false;
        }

        $prevIdx = $db->getHeader($header->getPrevBlock());
        if ($prevIdx) {
            if (($prevIdx->getStatus() & DbHeader::BLOCK_VALID) == 0) {
                echo "prev known. block not valid. return false\n";
                return false;
            }
        }

        /** @var DbHeader $headerIdx */
        if ($headerIndex->getStatus() == DbHeader::BLOCK_VALID) {
            return true;
        }

        $db->setBlockReceived($hash);

        if (gmp_cmp($headerIndex->getWork(), $this->bestBlockIndex->getWork()) > 0) {
            $this->bestBlockIndex = $headerIndex;
        }

        return true;
    }

    private function acceptHeaderToIndex(DBInterface $db, int $height, \GMP $work, BufferInterface $hash, BlockHeaderInterface $header): DbHeader
    {
        $db->addHeader($height, $work, $hash, $header, DbHeader::HEADER_VALID);
        $this->hashMapToHeight[$hash->getBinary()] = $height;

        /** @var DbHeader $headerIndex */
        $headerIndex = $db->getHeader($hash);

        return $headerIndex;
    }
}
