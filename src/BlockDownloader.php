<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;
use Evenement\EventEmitter;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class BlockDownloader extends EventEmitter
{
    /**
     * @var Chain
     */
    private $chain;

    private $downloading = false;

    /**
     * @var Deferred[]
     */
    private $deferred = [];

    private $toDownload = [];
    /**
     * @var int
     */
    private $batchSize;

    private $blockStatsWindow = 16;
    private $blockStatsCount;
    private $blockStatsBegin;

    /**
     * @var DB
     */
    private $db;

    public function __construct(int $batchSize, DB $db, Chain $chain)
    {
        $this->batchSize = $batchSize;
        $this->db = $db;
        $this->chain = $chain;
    }
}
