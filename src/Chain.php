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
        $genesisHash = $db->getBlockHash(0);
        if ($genesisHash instanceof BufferInterface) {
            if (!$genesisHeader->getHash()->equals($genesisHash)) {
                throw new \RuntimeException("Database has different genesis hash!");
            }
        } else {
            $genesisHash = $genesisHeader->getHash();
            $work = $this->proofOfWork->getWork($genesisHeader->getBits());
            $db->addHeader(0, $work, $genesisHash, $genesisHeader, DbHeader::HEADER_VALID | DbHeader::BLOCK_VALID);
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
                echo "found genesis, add to set\n";
                $candidates[$hashKey] = $row;
            } else if (($row->getStatus() & DbHeader::HEADER_VALID) != 0) {
                $prevKey = $header->getPrevBlock()->getBinary();
                if (array_key_exists($prevKey, $candidates)) {
                    echo "found block with prev as tip\n";
                    /** @var DbHeader $prevTip */
                    $prevTip = $candidates[$prevKey];
                    if (($prevTip->getStatus() & DbHeader::BLOCK_VALID) == 0) {
                        // prevTip doesn't have block, so there's no reason to
                        // keep it now since $row replaces it
                        unset($candidates[$prevKey]);
                    } else if (($row->getStatus() & DbHeader::BLOCK_VALID) != 0) {
                        // prevTip does have a block, row has a block. delete.
                        unset($candidates[$prevKey]);
                    } else {
                        echo "leave it there\n";
                    }
                    // only leaves prevTip in candidates if prev is BLOCK_VALID
                    // but $row is not.
                } else {
                    // prev is not already a tip.
                    $prev = $db->getHeader($header->getPrevBlock());
                    if (!$prev) {
                        throw new \RuntimeException("FATAL: could not find prev block");
                    }
                    // reduce bestBlockHeight until that index is BLOCK_VALID
                    $bestBlockHeight = $row->getHeight();
                    for ($blkHash = $row->getHash()->getBinary();
                         ($tmpPrev[$blkHash][1] & DbHeader::BLOCK_VALID) === 0;
                         $blkHash = $tmpPrev[$blkHash][0]) {
                        $bestBlockHeight--;
                    }

                    // don't already have an entry for the best block, add it
                    if (!array_key_exists($blkHash, $candidates)) {
                        $candidates[$blkHash] = $db->getHeader(new Buffer($blkHash));
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
        $bestKey = $bestHeader->getHash()->getBinary();
        $height = $bestHeader->getHeight();

        // build up our view of the best chain
        while (array_key_exists($bestKey, $tmpPrev)) {
            $this->heightMapToHash[$height] = $bestKey;
            $bestKey = $tmpPrev[$bestKey][0];
            $height--;
        }

        $this->bestHeaderIndex = $bestHeader;
        $this->bestBlockIndex = $bestBlock;
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

    public function getBlockHash(int $headerHeight)
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
                echo "header known. not valid. return false\n";
                print_r($headerIndex);
                return false;
            }
            echo "header known. valid. return true\n";
            return true;
        }

        $prevIndex = $db->getHeader($header->getPrevBlock());
        if ($prevIndex) {
            if (($prevIndex->getStatus() & DbHeader::HEADER_VALID) == 0) {
                echo "prev known. not valid. return false\n";
                return false;
            }
        } else {
            echo "prev not known. return false\n";
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
        $prevTip = $this->getBestHeader();

        $headerIndex = $this->acceptHeaderToIndex($db, $height, $work, $hash, $header);

        if (gmp_cmp($work, $prevTip->getWork()) > 0) {
            if (!$header->getPrevBlock()->equals($prevTip->getHash())) {
                echo "activating NEW HEADER TIP\n";
                echo "new work: {$work}, prev tip work: {$prevTip->getWork()}\n";
            }
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
                echo "removing header from header chain @ $i " . bin2hex($this->heightMapToHash[$i]) . PHP_EOL;
                unset($this->heightMapToHash[$i]);
            }

            // Insert [lastCommonHeight+1, candidateTipHeight] to the header chain
            for ($i = $lastCommonHeight + 1; $i <= $headerIndex->getHeight(); $i++) {
                echo "add header to header chain @ $i " . bin2hex($candidateHashes[$i]) . PHP_EOL;
                $this->heightMapToHash[$i] = $candidateHashes[$i];
            }

            // Updates bestHeaderIndex
            $this->bestHeaderIndex = $headerIndex;
            echo "new best header " . $headerIndex->getHash()->getHex() . PHP_EOL;

            // todo: this will need to be checked, maybe chain init unexpected new candidate code
            if ($this->bestBlockHeight > $lastCommonHeight) {
                $this->bestBlockHeight = $lastCommonHeight;
            }
        }

        return true;
    }

    public function acceptBlock(DBInterface $db, BufferInterface $hash, BlockInterface $block): bool
    {
        $header = $block->getHeader();

        if (!$this->acceptHeader($db, $hash, $header, $headerIdx)) {
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
        if ($headerIdx->getStatus() == DbHeader::BLOCK_VALID) {
            return true;
        }

        $db->setBlockReceived($hash);

        if (gmp_cmp($headerIdx->getWork(), $this->bestBlockIndex->getWork()) > 0) {
            if (!$header->getPrevBlock()->equals($this->bestBlockIndex->getHash())) {
                echo "activating NEW BLOCK CHAIN\n";
                echo "new work: {$headerIdx->getWork()}, prev tip work: {$this->bestBlockIndex->getWork()}\n";
            }

            // Updates bestHeaderIndex
            $this->bestBlockIndex = $headerIdx;
            echo "new best block " . $headerIdx->getHash()->getHex() . PHP_EOL;
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
