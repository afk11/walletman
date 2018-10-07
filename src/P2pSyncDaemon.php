<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Chain\BlockLocator;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Networking\Factory;
use BitWasp\Bitcoin\Networking\Ip\Ipv4;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\Headers;
use BitWasp\Bitcoin\Networking\Messages\Ping;
use BitWasp\Bitcoin\Networking\Peer\ConnectionParams;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Buffertools\Buffer;
use React\EventLoop\LoopInterface;

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
     * @var BlockDownloader
     */
    private $downloader;

    public function __construct(string $host, int $port, string $database)
    {
        $this->host = $host;
        $this->port = $port;
        $this->chain = new Chain(new \BitWasp\Bitcoin\Chain\Params(new Math()));
        // would normally come from wallet birthday
        $this->chain->setStartBlock(new BlockRef(544000, Buffer::hex("0000000000000000000b4842f41ab2f65826a45102def71e43b1d8233a28d9f6")));
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
