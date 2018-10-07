<?php

namespace BitWasp\Wallet;


use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Buffertools\BufferInterface;
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

    public function __construct(int $batchSize, Chain $chain)
    {
        $this->batchSize = $batchSize;
        $this->chain = $chain;
    }

    /**
     * receiveBlock processes a block we requested. it will
     * resolve the promise returned by the corresponding
     * requestBlock call.
     *
     * @todo: error if unrequested
     *
     * @param Peer $peer
     * @param Block $blockMsg
     */
    public function receiveBlock(Peer $peer, \BitWasp\Bitcoin\Networking\Messages\Block $blockMsg)
    {
        $block = $blockMsg->getBlock();
        $hash = $block->getHeader()->getHash();
        //echo "receiveBlock {$hash->getHex()}\n";

        if (!array_key_exists($hash->getBinary(), $this->deferred)) {
            throw new \RuntimeException("missing block request {$hash->getHex()}");
        }
        $deferred = $this->deferred[$hash->getBinary()];
        unset($this->deferred[$hash->getBinary()]);
        $deferred->resolve($block);
    }

    /**
     * Requests block using $hash from the peer, returning a
     * promise, which will resolve with the Block Message
     *
     * This function queues hashes, to be sent as getdata messages
     * later
     *
     * @param Peer $peer
     * @param BufferInterface $hash
     * @return PromiseInterface
     */
    public function requestBlock(Peer $peer, BufferInterface $hash): PromiseInterface
    {
        //echo "requestBlock {$hash->getHex()}\n";
        $this->toDownload[] = Inventory::block($hash);

        $deferred = new Deferred();
        $this->deferred[$hash->getBinary()] = $deferred;
        return $deferred->promise();
    }

    public function requestBlocks(Peer $peer)
    {
        if (null === $this->blockStatsCount) {
            $this->blockStatsCount = 0;
            $this->blockStatsBegin = \microtime(true);
        }

        $startBlock = $this->chain->getBestBlockHeight() + 1;
        while(count($this->deferred) < $this->batchSize && $startBlock + count($this->deferred) <= $this->chain->getBestHeaderHeight()) {
            $height = $startBlock + count($this->deferred);
            $hash = $this->chain->getBlockHash($height);
            $this->requestBlock($peer, $hash)
                ->then(function(Block $block) use ($peer, $height, $hash) {
                    //echo "processBlock $height, {$hash->getHex()}\n";
                    $this->chain->addNextBlock($height, $hash, $block);

                    $this->blockStatsCount++;
                    if ($this->blockStatsCount === $this->blockStatsWindow) {
                        $took = \microtime(true) - $this->blockStatsBegin;
                        echo "Processed {$height} - {$this->blockStatsWindow} took {$took} seconds\n";
                        $this->blockStatsCount = 0;
                        $this->blockStatsBegin = microtime(true);
                    }

                    $this->requestBlocks($peer);
                }, function (\Exception $e) {
                    echo "requestBlockError: {$e->getMessage()}\n";
                })
                ->then(null, function (\Exception $e) {
                    echo "finalizeBlockError: {$e->getMessage()}\n";
                });
        }

        if (count($this->toDownload) > 0) {
            // if nearTip don't bother sending a batch request, submit immediately
            // otherwise, send when we have batch/2 or batch items
            $nearTip = count($this->deferred) < $this->batchSize;
            if ($nearTip || count($this->toDownload) % ($this->batchSize/2) === 0) {
                $peer->getdata($this->toDownload);
                $this->toDownload = [];
            }
        }
    }


    public function download(Peer $peer)
    {
        if ($this->downloading) {
            echo "ignore duplicate download request\n";
            return;
        }

        $this->downloading = true;
        $peer->on(Message::BLOCK, [$this, 'receiveBlock']);
        $this->requestBlocks($peer);
    }
}
