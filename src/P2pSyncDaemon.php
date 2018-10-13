<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Chain\BlockLocator;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Networking\Factory;
use BitWasp\Bitcoin\Networking\Ip\Ipv4;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\Headers;
use BitWasp\Bitcoin\Networking\Messages\Ping;
use BitWasp\Bitcoin\Networking\Peer\ConnectionParams;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Server;


class P2pSyncDaemon
{
    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var Chain
     */
    private $chain;

    /**
     * @var NetworkInterface
     */
    private $network;

    /**
     * @var DB
     */
    private $db;

    /**
     * @var BlockDownloader
     */
    private $downloader;

    /**
     * @var bool
     */
    private $downloading = false;

    /**
     * @var Deferred[]
     */
    private $deferred = [];

    private $toDownload = [];
    /**
     * @var int
     */
    private $batchSize =  16;

    private $blockStatsWindow = 16;
    private $blockStatsCount;
    private $blockStatsBegin;

    public function __construct(string $host, int $port, NetworkInterface $network, Params $params, DB $db)
    {
        $this->host = $host;
        $this->port = $port;
        $this->db = $db;
        $this->network = $network;

        $blockCount = $db->getBlockCount();
        if ($blockCount === 0) {
            throw new \RuntimeException("need genesis block");
        }
        $bestHeader = $db->getBestHeader();
        if ($bestHeader->getHeight() > 0) {
            $this->chain = new Chain($this->db->getTailHashes($bestHeader->getHeight()), $bestHeader->getHeader(), 0);
        } else {
            $this->chain = new Chain([], $bestHeader->getHeader(), 0);
        }

        // would normally come from wallet birthday
        $this->chain->setStartBlock(new BlockRef(544600, Buffer::hex("0000000000000000000fded8e152db5d901e698d26768978d42c23ce97a55036")));
        $this->downloader = new BlockDownloader(16, $db, $this->chain);
    }

    public function sync(LoopInterface $loop) {
        $netFactory = new Factory($loop, $this->network);
        $connParams = new ConnectionParams();
        $connParams->setBestBlockHeight($this->chain->getBestHeaderHeight());

        echo "best height {$this->chain->getBestHeaderHeight()}\n";
        echo "best block {$this->chain->getBestHeaderHeight()}\n";
        $connector = $netFactory->getConnector($connParams);
        $connector
            ->connect($netFactory->getAddress(new Ipv4($this->host), $this->port))
            ->then(function(Peer $peer) {
                $peer->on(Message::PING, function (Peer $peer, Ping $ping) {
                    $peer->pong($ping);
                });

                $this->downloadHeaders($peer);
            }, function (\Exception $e) {
                echo "error: {$e->getMessage()}\n";
            });
    }

    public function downloadHeaders(Peer $peer) {
        $peer->on(Message::HEADERS, function (Peer $peer, Headers $headers) {
            if (count($headers->getHeaders()) > 0) {
                // misbehaving..
                $this->db->getPdo()->beginTransaction();
                try {
                    $last = null;
                    $startHeight = $this->chain->getBestHeaderHeight();
                    foreach ($headers->getHeaders() as $i => $header) {
                        $last = $header->getHash();
                        $this->chain->addNextHeader($this->db, $startHeight + $i + 1, $last, $header);
                    }
                    $this->db->getPdo()->commit();
                } catch (\Exception $e) {
                    echo "error: {$e->getMessage()}\n";
                    echo "error: {$e->getTraceAsString()}\n";
                    $this->db->getPdo()->rollBack();
                    throw $e;
                }

                if (count($headers->getHeaders()) === 2000) {
                    $peer->getheaders(new BlockLocator([$last], new Buffer('', 32)));
                }

                echo "new header tip {$this->chain->getBestHeaderHeight()} {$last->getHex()}\n";
            }

            if (count($headers->getHeaders()) < 2000) {
                try {
                    $this->downloadBlocks($peer);
                } catch (\Exception $e) {
                    echo $e->getMessage().PHP_EOL;
                }
            }
        });

        $hash = $this->chain->getBestHeaderHash();
        echo "requestHeaders {$hash->getHex()}\n";
        $peer->getheaders(new BlockLocator([$hash], new Buffer('', 32)));
        $peer->sendheaders();
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
        echo "receive block\n";
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
        echo "requestBlock {$hash->getHex()}\n";
        $this->toDownload[] = Inventory::block($hash);

        $deferred = new Deferred();
        $this->deferred[$hash->getBinary()] = $deferred;
        return $deferred->promise();
    }

    public function requestBlocks(Peer $peer, Deferred $deferredFinished)
    {
        echo "requestBlocks\n";
        if (null === $this->blockStatsCount) {
            $this->blockStatsCount = 0;
            $this->blockStatsBegin = \microtime(true);
        }

        $startBlock = $this->chain->getBestBlockHeight() + 1;
        while(count($this->deferred) < $this->batchSize && $startBlock + count($this->deferred) <= $this->chain->getBestHeaderHeight()) {
            echo "queue\n";
            $height = $startBlock + count($this->deferred);
            echo "height: $height\n";
            $hash = $this->chain->getBlockHash($height);
            $this->requestBlock($peer, $hash)
                ->then(function(Block $block) use ($peer, $height, $hash, $deferredFinished) {
                    echo "processBlock $height, {$hash->getHex()}\n";
                    $this->chain->addNextBlock($height, $hash, $block);


                    $this->blockStatsCount++;
                    if ($this->blockStatsCount === $this->blockStatsWindow) {
                        $took = \microtime(true) - $this->blockStatsBegin;
                        echo "Processed {$height} - {$this->blockStatsWindow} took {$took} seconds\n";
                        $this->blockStatsCount = 0;
                        $this->blockStatsBegin = microtime(true);
                    }

                    $this->requestBlocks($peer, $deferredFinished);
                    echo "request moar\n";
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

        if (count($this->deferred) === 0) {
            $deferredFinished->resolve();
        }
        echo "requestBlocks finished\n";
    }

    public function downloadBlocks(Peer $peer)
    {
        if (!$this->downloading) {
            $this->downloading = true;
            $peer->on(Message::BLOCK, [$this, 'receiveBlock']);

            $deferred = new Deferred();
            echo "request blocks\n";
            $this->requestBlocks($peer, $deferred);

            return $deferred
                ->promise()
                ->then(function () use ($peer) {
                    echo "done syncing\n";
                    $this->downloading = false;
                    $peer->removeListener(Message::BLOCK, [$this, 'receiveBlock']);
                });
        } else {
            throw new \RuntimeException("already downloading");
        }
    }
}
