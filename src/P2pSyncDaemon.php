<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

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

        $genesis = $db->getBestHeader();
        $this->chain = new Chain([], $genesis->getHeader(), 0);

        // would normally come from wallet birthday
        $this->chain->setStartBlock(new BlockRef(544500, Buffer::hex("0000000000000000000d8cc90c4a596a7137bc900ffc9ddeb97400f3bf5a89b9")));
        $this->downloader = new BlockDownloader(16, $this->chain);
    }

    public function sync(LoopInterface $loop) {
        $netFactory = new Factory($loop, $this->network);
        $connParams = new ConnectionParams();
        $connParams->setBestBlockHeight($this->chain->getBestHeaderHeight());

//        $server = new Server('unix:///tmp/server.sock', $loop);
//        $server->on('connection', function (ConnectionInterface $connection) {
//            echo 'Plaintext connection from ' . $connection->getRemoteAddress() . PHP_EOL;
//            $req = new Deferred();
//            $connection->on('data', function ($data) use ($req) {
//                $req->resolve($data);
//            });
//            $req->promise()->then(function () use ($connection) {
//                $connection->write('hello there!' . PHP_EOL);
//            });
//        });

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
            if (count($headers->getHeaders()) === 0) {
                // misbehaving..
                return;
            }

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

            echo "new header tip {$this->chain->getBestHeaderHeight()} {$last->getHex()}\n";
            if (count($headers->getHeaders()) != 2000) {
                $this->downloadBlocks($peer);
            } else {
                $peer->getheaders(new BlockLocator([$last], new Buffer('', 32)));
            }
        });

        $hash = $this->chain->getBestHeaderHash();
        $peer->getheaders(new BlockLocator([$hash], new Buffer('', 32)));
        $peer->sendheaders();
    }

    public function downloadBlocks(Peer $peer)
    {
        if (!$this->downloading) {
            echo "downloadBlocks\n";
            $this->downloader->download($peer);
            $this->downloading = true;
        }
    }
}
