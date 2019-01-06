<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Chain\BlockLocator;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Networking\Factory;
use BitWasp\Bitcoin\Networking\Ip\Ipv4;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\Headers;
use BitWasp\Bitcoin\Networking\Messages\Inv;
use BitWasp\Bitcoin\Networking\Messages\Ping;
use BitWasp\Bitcoin\Networking\Messages\Pong;
use BitWasp\Bitcoin\Networking\Messages\Tx;
use BitWasp\Bitcoin\Networking\Peer\ConnectionParams;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Services;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Bitcoin\Serializer\Block\BlockHeaderSerializer;
use BitWasp\Bitcoin\Serializer\Block\BlockSerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializer;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\WalletInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;
use React\Promise\PromiseInterface;

class P2pSyncDaemon
{
    const PING_TIMEOUT = 1200;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Chain
     */
    private $chain;

    /**
     * @var NetworkInterface
     */
    private $network;

    /**
     * @var DBInterface
     */
    private $db;

    /**
     * @var ParamsInterface
     */
    private $params;
    private $headerSerializer;
    private $blockSerializer;
    private $txSerializer;

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
     * @var Random
     */
    private $random;
    /**
     * @var resource
     */
    private $blockStatsFileHandle;
    /**
     * @var int
     */
    private $batchSize =  16;

    private $initialized = false;
    private $mempool = false;
    private $segwit = true;
    private $blockStatsWindow = 64;
    private $blockStatsCount;
    private $blockStatsBegin;
    private $blockProcessTime;
    private $blockDeserializeTime;
    private $perBlockDebug = false;
    /**
     * @var WalletInterface[]
     */
    private $wallets = [];

    public function __destruct()
    {
        if ($this->blockStatsFileHandle !== null) {
            fclose($this->blockStatsFileHandle);
        }
    }

    public function __construct(LoggerInterface $logger, string $host, int $port, EcAdapterInterface $ecAdapter, NetworkInterface $network, ParamsInterface $params, DBInterface $db, Random $random, Chain $chain)
    {
        $this->logger = $logger;
        $this->host = $host;
        $this->port = $port;
        $this->db = $db;
        $this->ecAdapter = $ecAdapter;
        $this->network = $network;
        $this->params = $params;
        $this->random = $random;
        $this->chain = $chain;
        $this->headerSerializer = new BlockHeaderSerializer();
        $this->txSerializer = new TransactionSerializer();
        $this->blockSerializer = new BlockSerializer(new Math(), $this->headerSerializer, $this->txSerializer);
    }

    private function resetBlockStats()
    {
        $this->blockStatsCount = null;
    }
    public function setPerBlockDebug(bool $setting)
    {
        $this->perBlockDebug = $setting;
    }
    public function syncMempool(bool $setting)
    {
        $this->mempool = $setting;
    }

    public function produceBlockStatsCsv(string $file)
    {
        if ($this->blockStatsFileHandle !== null) {
            throw new \RuntimeException("Already setup block stats logging");
        }
        $fh = fopen($file, "a");
        if (!$fh) {
            throw new \RuntimeException("failed to open stats csv file");
        }
        $this->blockStatsFileHandle = $fh;
    }

    public function init(Base58ExtendedKeySerializer $hdSerializer)
    {
        $dbWallets = $this->db->loadAllWallets();
        $startBlock = null;
        foreach ($dbWallets as $dbWallet) {
            if ($dbWallet->getType() !== 1) {
                throw new \RuntimeException("invalid wallet type");
            }
            $this->wallets[] = new Bip44Wallet($this->db, $hdSerializer, $dbWallet, $this->db->loadBip44WalletKey($dbWallet->getId()), $this->network, $this->ecAdapter);
            if ($birthday = $dbWallet->getBirthday()) {
                if (!($startBlock instanceof BlockRef)) {
                    $startBlock = $dbWallet->getBirthday();
                } else if ($birthday->getHeight() < $startBlock->getHeight()) {
                    $startBlock = $dbWallet->getBirthday();
                }
            }
        }

        $this->chain->init($this->db, $this->params);

        // would normally come from wallet birthday
        $this->initialized = true;
        echo "DONE!\n";
    }

    public function sync(LoopInterface $loop)
    {
        if (!$this->initialized) {
            throw new \LogicException("Cannot sync, not initialized");
        }
        $netFactory = new Factory($loop, $this->network);

        $requiredServices = 0;
        $myServices = 0;
        if ($this->segwit) {
            $requiredServices = $requiredServices | Services::WITNESS;
            $myServices = $myServices | Services::WITNESS;
        }

        $connParams = new ConnectionParams();
        $connParams->setBestBlockHeight($this->chain->getBestHeader()->getHeight());
        $connParams->setRequiredServices($requiredServices);
        $connParams->setLocalServices($myServices);
        $connParams->setProtocolVersion(70013); // above this causes problems, todo

        if ($this->mempool) {
            $connParams->requestTxRelay(true);
        }

        echo "best height {$this->chain->getBestHeader()->getHeight()}\n";
        echo "best block {$this->chain->getBestBlockHeight()}\n";

        return $netFactory
            ->getConnector($connParams)
            ->connect($netFactory->getAddress(new Ipv4($this->host), $this->port))
            ->then(function (Peer $peer) use ($loop) {
                $timeLastPing = null;
                $pingLastNonce = null;

                $peer->on('close', function (Peer $peer) {
                    throw new \RuntimeException("peer closed connection\n");
                });
                $loop->addPeriodicTimer(60, function () use ($peer, &$timeLastPing, &$pingLastNonce) {
                    if ($timeLastPing === null) {
                        $ping = Ping::generate($this->random);
                        $timeLastPing = time();
                        $pingLastNonce = $ping->getNonce();
                        $peer->send($ping);
                    } else {
                        $timeSinceLast = time() - $timeLastPing;
                        if ($timeSinceLast > self::PING_TIMEOUT) {
                            throw new \RuntimeException("ping timeout");
                        }
                    }
                });
                $peer->on(Message::PONG, function (Peer $peer, Pong $pong) use (&$timeLastPing, &$pingLastNonce) {
                    if (!$pingLastNonce) {
                        // unexpected pong..
                        return;
                    }

                    if (!$pong->getNonce()->equals($pingLastNonce)) {
                        // returned unexpected pong
                        return;
                    }
                    $timeLastPing = null;
                    $pingLastNonce = null;
                });
                $peer->on(Message::PING, function (Peer $peer, Ping $ping) {
                    $peer->pong($ping);
                });
                $peer->on(Message::TX, function (Peer $peer, Tx $txMsg) {
                    $tx = $this->txSerializer->parse($txMsg->getTransaction());
                    echo "p2p tx: {$tx->getTxId()->getHex()}\n";
                });
                $peer->on(Message::INV, function (Peer $peer, Inv $inv) {
                    // routine for invs, ignore until we sync blocks
                    $txs = [];
                    foreach ($inv->getItems() as $inventory) {
                        if ($inventory->isTx()) {
                            $txs[] = Inventory::witnessTx($inventory->getHash());
                        }
                    }
                    $peer->getdata($txs);
                });
                $this->downloadHeaders($peer);
            }, function (\Exception $e) {
                echo "error: {$e->getMessage()}\n";
            });
    }

    public function downloadHeaders(Peer $peer)
    {
        $peer->on(Message::HEADERS, function (Peer $peer, Headers $headers) {
            if (count($headers->getHeaders()) > 0) {
                $this->db->getPdo()->beginTransaction();
                try {
                    /** @var DbHeader null|$lastHeader */
                    $lastHeader = null;
                    foreach ($headers->getHeaders() as $i => $headerData) {
                        $header = $this->headerSerializer->parse($headerData);
                        if ($lastHeader instanceof DbHeader && !$lastHeader->getHash()->equals($header->getPrevBlock())) {
                            throw new \RuntimeException("non continuous headers message");
                        }

                        $hash = $header->getHash();
                        if (!$this->chain->acceptHeader($this->db, $hash, $header, $lastHeader)) {
                            throw new \RuntimeException("failed to accept header");
                        }
                    }
                    $this->db->getPdo()->commit();
                } catch (\Exception $e) {
                    echo "error: {$e->getMessage()}\n";
                    echo "error: {$e->getTraceAsString()}\n";
                    $this->db->getPdo()->rollBack();
                    throw $e;
                }

                if (count($headers->getHeaders()) === 2000) {
                    echo "requestHeaders starting at {$lastHeader->getHeight()} {$lastHeader->getHash()->getHex()}\n";
                    $peer->getheaders(new BlockLocator([$lastHeader->getHash()], new Buffer('', 32)));
                }

                echo "processHeaders. accepted {$lastHeader->getHeight()} {$lastHeader->getHash()->getHex()}\n";
            }

            if (count($headers->getHeaders()) < 2000) {
                $this->downloadBlocks($peer);
            }
        });

        $hash = $this->chain->getBestHeader()->getHash();
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
     * @param \BitWasp\Bitcoin\Networking\Messages\Block $blockMsg
     */
    public function receiveBlock(Peer $peer, \BitWasp\Bitcoin\Networking\Messages\Block $blockMsg)
    {
        $beforeDeserialize = microtime(true);
        $block = $this->blockSerializer->parse($blockMsg->getBlock());
        $taken = microtime(true)-$beforeDeserialize;
        $this->blockDeserializeTime += $taken;
        if ($this->perBlockDebug) {
            echo "block.deserialize (size={$blockMsg->getBlock()->getSize()}) (time=$taken)\n";
        }
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
        //echo "requestBlock: {$hash->getHex()}\n";
        if ($this->segwit) {
            $this->toDownload[] = Inventory::witnessBlock($hash);
        } else {
            $this->toDownload[] = Inventory::block($hash);
        }

        $deferred = new Deferred();
        $this->deferred[$hash->getBinary()] = $deferred;
        return $deferred->promise();
    }

    public function requestBlocks(Peer $peer, Deferred $deferredFinished)
    {
        if (null === $this->blockStatsCount) {
            $this->blockProcessTime = 0;
            $this->blockDeserializeTime = 0;
            $this->blockStatsCount = 0;
            $this->blockStatsBegin = \microtime(true);
        }

        $downloadStartHeight = $this->chain->getBestBlockHeight() + 1;
        $heightBestHeader = $this->chain->getBestHeader()->getHeight();

        while (count($this->deferred) < $this->batchSize && $downloadStartHeight + count($this->deferred) <= $heightBestHeader) {
            $height = $downloadStartHeight + count($this->deferred);
            $hash = $this->chain->getBlockHash($height);
            $this->requestBlock($peer, $hash)
                ->then(function (Block $block) use ($peer, $height, $hash, $deferredFinished) {
                    if ($this->perBlockDebug) {
                        echo "processBlock $height {$hash->getHex()}\n";
                    }

                    $processStart = microtime(true);
                    $this->db->getPdo()->beginTransaction();
                    try {
                        $this->chain->acceptBlock($this->db, $hash, $block);
                        $processor = new BlockProcessor($this->db, ...$this->wallets);
                        $processor->process($height, $block);
                        $this->db->getPdo()->commit();
                    } catch (\Exception $e) {
                        echo $e->getMessage().PHP_EOL;
                        $this->db->getPdo()->rollBack();
                        throw $e;
                    }

                    $blockProcessTime = microtime(true) - $processStart;
                    $this->blockProcessTime += $blockProcessTime;
                    $this->blockStatsCount++;
                    if ($this->perBlockDebug) {
                        echo "block.process (time=$blockProcessTime)\n";
                    }

                    if ($this->blockStatsCount === $this->blockStatsWindow) {
                        $totalTime = \microtime(true) - $this->blockStatsBegin;
                        $windowTime = number_format($totalTime, 2);

                        $downloadTime = number_format($totalTime - $this->blockProcessTime, 2);
                        $downloadPct = number_format($downloadTime / $totalTime*100, 2);

                        $deserTime = number_format($this->blockDeserializeTime, 2);
                        $deserPct = number_format($deserTime / $totalTime*100, 2);

                        $processTime = number_format($this->blockProcessTime, 2);
                        $processPct = number_format($processTime / $totalTime*100, 2);
                        $this->logger->info("block process info ({$this->blockStatsWindow} blocks): height {$height} hash {$hash->getHex()} | deserialize {$deserTime} {$deserPct} | downloadtime {$downloadTime} {$downloadPct}, processtime {$processTime} {$processPct}, total {$windowTime}");
                        if (null !== $this->blockStatsFileHandle) {
                            fwrite($this->blockStatsFileHandle, implode(", ", [
                                    $height,
                                    $deserTime,
                                    $deserPct,
                                    $downloadTime,
                                    $downloadPct,
                                    $processTime,
                                    $processPct,
                                    $windowTime,
                                    count($block->getTransactions()),
                                ]) . "\n");
                        }

                        $this->blockProcessTime = 0;
                        $this->blockDeserializeTime = 0;
                        $this->blockStatsCount = 0;
                        $this->blockStatsBegin = microtime(true);
                    }

                    $this->requestBlocks($peer, $deferredFinished);
                }, function (\Exception $e) use ($deferredFinished) {
                    $deferredFinished->reject(new \Exception("requestBlockError", 0, $e));
                })
                ->then(null, function (\Exception $e) use ($deferredFinished) {
                    $deferredFinished->reject(new \Exception("processBlockError", 0, $e));
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
    }

    public function downloadBlocks(Peer $peer)
    {
        if ($this->downloading) {
            return new FulfilledPromise();
        }

        $isFirstSetup = false;
        if ($this->chain->getBestBlockHeight() === 0) {
            $isFirstSetup = true;
            $startBlock = null;
            if (count($this->wallets) === 0) {
                $bestIndex = $this->chain->getBestHeader();
                $startBlock = new BlockRef($bestIndex->getHeight(), $bestIndex->getHash());
            } else {
                foreach ($this->wallets as $wallet) {
                    $dbWallet = $wallet->getDbWallet();
                    if ($birthday = $dbWallet->getBirthday()) {
                        if (!($startBlock instanceof BlockRef)) {
                            $startBlock = $dbWallet->getBirthday();
                        } else if ($birthday->getHeight() < $startBlock->getHeight()) {
                            $startBlock = $dbWallet->getBirthday();
                        }
                    }
                }
            }
            if ($startBlock) {
                $this->chain->setStartBlock($startBlock);
            }
        }
        $this->downloading = true;
        $peer->on(Message::BLOCK, [$this, 'receiveBlock']);

        $deferred = new Deferred();
        echo "requesting blocks\n";
        $this->requestBlocks($peer, $deferred);

        return $deferred
            ->promise()
            ->then(function () use ($peer, $isFirstSetup) {
                // finish shortcut for new wallets - mark history before we came online
                // as valid
                if ($isFirstSetup) {
                    $bestHeaderHeight = $this->chain->getBestHeader()->getHeight();
                    echo "mark birthday history as valid {$bestHeaderHeight}\n";
                    $this->db->markBirthdayHistoryValid($bestHeaderHeight);
                }
                echo "done syncing\n";
                $this->downloading = false;
                $this->resetBlockStats();
                $peer->removeListener(Message::BLOCK, [$this, 'receiveBlock']);
            });
    }
}
