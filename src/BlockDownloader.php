<?php

namespace BitWasp\Wallet;


use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\Block;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Buffertools\BufferInterface;
use Evenement\EventEmitter;
use React\Promise\Deferred;

class BlockDownloader extends EventEmitter
{
    /**
     * @var Chain
     */
    private $chain;

    /**
     * @var Deferred[]
     */
    private $deferred = [];

    /**
     * @var int
     */
    private $batchSize;

    private $blockStatsWindow = 1000;
    private $blockStatsCount;
    private $blockStatsBegin;

    public function __construct(int $batchSize, Chain $chain)
    {
        $this->batchSize = $batchSize;
        $this->chain = $chain;
    }

    public function receiveBlock(Peer $peer, Block $blockMsg) {
        $block = $blockMsg->getBlock();
        $hash = $block->getHeader()->getHash();

        $promise = $this->deferred[$hash->getBinary()];
        unset($this->deferred[$hash->getBinary()]);
        $promise->resolve([$hash, $block]);
    }

    public function requestBlock(Peer $peer, int $height, BufferInterface $hash) {
        $peer->getdata([Inventory::block($hash)]);

        $d = new Deferred();
        $this->deferred[$hash->getBinary()] = $d;
        return $d->promise();
    }

    public function requestBlocks(Peer $peer) {
        if (null === $this->blockStatsCount) {
            $this->blockStatsCount = 0;
            $this->blockStatsBegin = \microtime(true);
        }
        $queued = 0;
        while(count($this->deferred) < $this->batchSize) {
            $height = $this->chain->getBestBlockHeight() + count($this->deferred) + 1;
            $hash = $this->chain->getBlockHash($height);
            $queued++;
            $this->requestBlock($peer, $height, $hash)
                ->then(function(array $blockRow) use ($peer, $height) {
                    list ($hash, $block) = $blockRow;
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
    }
    public function download(Peer $peer) {
        $peer->on(Message::BLOCK, [$this, 'receiveBlock']);
        $this->requestBlocks($peer);
    }
}
