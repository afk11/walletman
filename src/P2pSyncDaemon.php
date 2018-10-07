<?php

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Chain\BlockLocator;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Networking\Factory;
use BitWasp\Bitcoin\Networking\Ip\Ipv4;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\Block;
use BitWasp\Bitcoin\Networking\Messages\Headers;
use BitWasp\Bitcoin\Networking\Messages\Ping;
use BitWasp\Bitcoin\Networking\NetworkMessage;
use BitWasp\Bitcoin\Networking\Peer\ConnectionParams;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Buffertools\Buffer;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

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
     * @var \BitWasp\Bitcoin\Chain\Params
     */
    private $chainParams;

    public function __construct(string $host, int $port, string $database)
    {
        $this->host = $host;
        $this->port = $port;
        $this->chainParams = new \BitWasp\Bitcoin\Chain\Params(new Math());
        $this->chain = new Chain($this->chainParams);
        // would normally come from wallet birthday
        $this->chain->setStartBlock(new BlockRef(544699, Buffer::hex("0000000000000000001e663c0cd9cd7524ca0c1f3d2af4ccea3909653315d7b0")));
        $this->downloader = new BlockDownloader(16, $this->chain);
    }

    public function sync(LoopInterface $loop) {
        $netFactory = new Factory($loop);
        $connParams = new ConnectionParams();
        $connParams->setBestBlockHeight($this->chain->getBestHeaderHeight());

        $connector = $netFactory->getConnector($connParams);
        $connector
            ->connect($netFactory->getAddress(new Ipv4($this->host), $this->port))
            ->then(function(Peer $peer) {
                echo "connected!\n";
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
            $last = null;
            $startHeight = $this->chain->getBestHeaderHeight();
            foreach ($headers->getHeaders() as $i => $header) {
                $last = $header->getHash();
                $this->chain->addNextHeader($startHeight + $i + 1, $last, $header);
            }
            if (count($headers->getHeaders()) != 2000) {
                $this->downloadBlocks($peer);
            } else {
                $peer->getheaders(new BlockLocator([$last], new Buffer('', 32)));
            }
        });

        $hash = $this->chain->getBestHeaderHash();
        $peer->getheaders(new BlockLocator([$hash], new Buffer('', 32)));
    }

    public function downloadBlocks(Peer $peer)
    {
        $this->downloader->download($peer);
    }
}
