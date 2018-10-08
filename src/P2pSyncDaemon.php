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
use BitWasp\Wallet\DB\DB;
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
        $bestHeader = $db->getBestHeader();
        if ($bestHeader->getHeight() > 0) {
            $this->chain = new Chain($this->db->getTailHashes($bestHeader->getHeight()), $bestHeader->getHeader(), 0);
        } else {
            $this->chain = new Chain([], $bestHeader->getHeader(), 0);
        }

        // would normally come from wallet birthday
        $this->chain->setStartBlock(new BlockRef(544600, Buffer::hex("0000000000000000000fded8e152db5d901e698d26768978d42c23ce97a55036")));
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
        $peer->getheaders(new BlockLocator([$hash], new Buffer('', 32)));
        $peer->sendheaders();
    }

    public function downloadBlocks(Peer $peer)
    {
        if (!$this->downloading) {
            echo "downloadBlocks\n";
            $this->downloading = true;
            $this->downloader->download($peer);
        }
    }
}
