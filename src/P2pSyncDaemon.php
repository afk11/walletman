<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Chain\BlockLocator;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Networking\Factory;
use BitWasp\Bitcoin\Networking\Ip\Ipv4;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\Block;
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
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\WalletInterface;
use BitWasp\Wallet\Wallet\WalletType;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

class P2pSyncDaemon
{
    const PING_TIMEOUT = 1200;
    const HEADERS_FULL = 2000;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var DBInterface
     */
    private $db;

    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var NetworkInterface
     */
    private $network;

    /**
     * @var ParamsInterface
     */
    private $params;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var Chain
     */
    private $chain;

    /**
     * @var BlockHeaderSerializer
     */
    private $headerSerializer;

    /**
     * @var BlockSerializer
     */
    private $blockSerializer;

    /**
     * @var TransactionSerializer
     */
    private $txSerializer;

    // Cli related state

    /**
     * @var bool
     */
    private $perBlockDebug = false;

    /**
     * @var bool
     */
    private $mempool = false;

    /**
     * @var bool
     */
    private $segwit = true;

    /**
     * @var int
     */
    private $blockStatsWindow = 64;

    // The nodes state

    /**
     * @var bool
     */
    private $downloading = false;

    /**
     * map [blockHash: 1]
     * @var int[]
     */
    private $requested = [];

    /**
     * @var Inventory[]
     */
    private $toDownload = [];

    /**
     * @var resource
     */
    private $blockStatsFileHandle;

    /**
     * Max number of blocks to download at once
     * @var int
     */
    private $batchSize =  16;

    /**
     * @var bool
     */
    private $initialized = false;
    private $keepRunning = true;

    /**
     * This is set to null by resetBlockStats so the next
     * requestBlocks call initializes everything to zero
     * @var null|int
     */
    private $blockStatsCount;

    /**
     * @var float
     */
    private $blockStatsBegin;

    /**
     * @var float
     */
    private $blockProcessTime;

    /**
     * @var float
     */
    private $blockDeserializeTime;

    /**
     * @var int
     */
    private $blockDeserializeBytes;

    /**
     * @var int
     */
    private $blockDeserializeNTx;

    /**
     * @var null|Peer
     */
    private $peer;

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

    public function setBlockStatsWindow(int $numBlocks)
    {
        $this->blockStatsWindow = $numBlocks;
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
        $this->logger->debug("Loading wallets...");
        $dbWallets = $this->db->loadAllWallets();

        $this->logger->debug("Initializing chain...");
        $this->chain->init($this->db, $this->params);

        $startBlockRef = null;

        foreach ($dbWallets as $dbWallet) {
            if ($dbWallet->getType() !== WalletType::BIP44_WALLET) {
                throw new \RuntimeException("invalid wallet type");
            }
            $this->wallets[] = new Bip44Wallet($this->db, $hdSerializer, $dbWallet, $this->db->loadBip44WalletKey($dbWallet->getId()), $this->network, $this->ecAdapter);
            $birthday = $dbWallet->getBirthday();
            if ($birthday !== null) {
                if (!($startBlockRef instanceof BlockRef)) {
                    $startBlockRef = $birthday;
                } else if ($birthday->getHeight() < $birthday->getHeight()) {
                    $startBlockRef = $birthday;
                }
            }
        }

        if ($startBlockRef) {
            $this->chain->setBirthdayBlock($startBlockRef, $this->db);
        }

        // would normally come from wallet birthday
        $this->initialized = true;
        $this->logger->info("Initialized. Best block: {$this->chain->getBestBlock()->getHeight()}. Best header: {$this->chain->getBestHeader()->getHeight()}");
    }

    public function close(LoopInterface $loop)
    {
        $this->keepRunning = false;
        echo "requested: " . count($this->requested) . PHP_EOL;
        echo "toDownload: " . count($this->toDownload) . PHP_EOL;

        $loop->addTimer(5, function () use ($loop) {
            echo "TIMER\n";
            echo "requested: " . count($this->requested) . PHP_EOL;
            print_r(array_map('bin2hex', array_keys($this->requested)));
            echo "toDownload: " . count($this->toDownload) . PHP_EOL;
            print_r($this->toDownload);
            $loop->stop();
            echo "Killed by stop timer\n";
        });
    }
    public function sync(LoopInterface $loop)
    {
        $this->loop = $loop;
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

        return $netFactory
            ->getConnector($connParams)
            ->connect($netFactory->getAddress(new Ipv4($this->host), $this->port))
            ->then(function (Peer $peer) use ($loop) {
                $this->peer = $peer;
                $timeLastPing = null;
                $pingLastNonce = null;

                $pingTimer = $loop->addPeriodicTimer(60, function () use ($peer, &$timeLastPing, &$pingLastNonce) {
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
                $weRequestedShutdown = false;
                $peer->on('intentionaldisconnect', function () use (&$weRequestedShutdown) {
                    echo "we requested shutdown\n";
                    $weRequestedShutdown = true;
                });

                $peer->on('close', function () use (&$weRequestedShutdown, $loop, $pingTimer) {
                    $loop->cancelTimer($pingTimer);
                    if ($weRequestedShutdown) {
                        echo "done in close\n";
                        return;
                    }
                    throw new \RuntimeException("peer closed connection");
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
                $peer->on(Message::HEADERS, function (Peer $peer, Headers $headers) {
                    if (count($headers->getHeaders()) > 0) {
                        /** @var DbHeader null|$lastHeader */
                        $lastHeader = null;

                        $this->db->getPdo()->beginTransaction();
                        try {
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
                            $this->db->getPdo()->rollBack();
                            throw $e;
                        }

                        $newTip = $lastHeader->getHash()->equals($this->chain->getBestHeader()->getHash());
                        $this->logger->info(sprintf(
                            "processed %d headers up to " . ($newTip ? "new tip" : "") . " %d %s",
                            count($headers->getHeaders()),
                            $lastHeader->getHeight(),
                            $lastHeader->getHash()->getHex()
                        ));

                        if (count($headers->getHeaders()) === self::HEADERS_FULL) {
                            $this->logger->debug(sprintf(
                                "requestHeaders from %d %s",
                                $lastHeader->getHeight(),
                                $lastHeader->getHash()->getHex()
                            ));
                            $peer->getheaders(new BlockLocator([$lastHeader->getHash()], new Buffer('', 32)));
                        }
                    }

                    // Block download if count < max
                    if (count($headers->getHeaders()) < self::HEADERS_FULL) {
                        $bestHeader = $this->chain->getBestHeader();
                        // when we sync the tip for the first time and don't have wallets,
                        // set the tip as startBlock - this bypasse
                        if (count($this->wallets) === 0) {
                            $this->logger->info("synced header chain, but no wallets. Not downloading blocks.");
                        } else if (!$this->chain->getBestBlock()->getHash()->equals($bestHeader->getHash())) {
                            $this->downloadBlocks($peer);
                        }
                    }
                });

                $peer->on(Message::BLOCK, function (Peer $peer, Block $blockMsg) {
                    $beforeDeserialize = microtime(true);
                    $block = $this->blockSerializer->parse($blockMsg->getBlock());
                    $taken = microtime(true)-$beforeDeserialize;

                    $this->blockDeserializeTime += $taken;
                    $this->blockDeserializeBytes += $blockMsg->getBlock()->getSize();
                    $this->blockDeserializeNTx += count($block->getTransactions());

                    $hash = $block->getHeader()->getHash();
                    if (!array_key_exists($hash->getBinary(), $this->requested)) {
                        throw new \RuntimeException("missing block request {$hash->getHex()}");
                    }
                    unset($this->requested[$hash->getBinary()]);

                    $processStart = microtime(true);
                    $this->db->getPdo()->beginTransaction();
                    try {
                        $headerIndex = null;
                        if (!$this->chain->acceptBlock($this->db, $hash, $block, $headerIndex)) {
                            throw new \RuntimeException("Failed to process block");
                        }
                        /** @var DbHeader $headerIndex */
                        $processor = new BlockProcessor($this->db, ...$this->wallets);
                        $processor->process($headerIndex->getHeight(), $hash, $block);
                        $this->db->getPdo()->commit();
                    } catch (\Exception $e) {
                        $this->db->getPdo()->rollBack();
                        throw $e;
                    }

                    $blockProcessTime = microtime(true) - $processStart;
                    $this->blockProcessTime += $blockProcessTime;
                    $this->blockStatsCount++;

                    if ($this->perBlockDebug || $this->blockStatsCount === $this->blockStatsWindow) {
                        $totalTime = \microtime(true) - $this->blockStatsBegin;
                        $windowTime = number_format($totalTime, 2);

                        $deserTime = number_format($this->blockDeserializeTime, 2);
                        $deserPct = number_format($deserTime / $totalTime*100, 2);

                        $deserBytes = number_format($this->blockDeserializeBytes / 1e6, 3);

                        $downloadTime = number_format($totalTime - $this->blockProcessTime - $this->blockDeserializeTime, 2);
                        $downloadPct = number_format($downloadTime / $totalTime*100, 2);

                        $processTime = number_format($this->blockProcessTime, 2);
                        $processPct = number_format($processTime / $totalTime*100, 2);

                        $windowNumBlocks = $this->perBlockDebug ? 1 : $this->blockStatsWindow;
                        $avgPerBlock = number_format($totalTime / $windowNumBlocks, 2);

                        $this->logger->info("processed $windowNumBlocks blocks, ntx: {$this->blockDeserializeNTx}, $deserBytes MB): height {$headerIndex->getHeight()} hash {$hash->getHex()} | deserialize {$deserTime}s {$deserPct}% | downloadtime {$downloadTime}s {$downloadPct}% | processtime {$processTime}s {$processPct}% | total {$windowTime}s, avg {$avgPerBlock}s");
                        if (null !== $this->blockStatsFileHandle) {
                            fwrite($this->blockStatsFileHandle, implode(", ", [
                                    $headerIndex->getHeight(),
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
                        $this->blockDeserializeBytes = 0;
                        $this->blockDeserializeNTx = 0;
                        $this->blockStatsCount = 0;
                        $this->blockStatsBegin = microtime(true);
                    }

                    if ($headerIndex->getHash()->equals($this->chain->getBestHeader()->getHash())) {
                        $bestBlock = $this->chain->getBestBlock();
                        $this->logger->info("done syncing blocks to tip: {$bestBlock->getHeight()} {$bestBlock->getHash()->getHex()}");
                        $this->downloading = false;
                        $this->resetBlockStats();
                    } else {
                        $this->requestBlocks($peer);
                    }
                });

                $bestHeader = $this->chain->getBestHeader();
                $this->logger->debug(sprintf(
                    "requestHeaders starting at %d %s",
                    $bestHeader->getHeight(),
                    $bestHeader->getHash()->getHex()
                ));

                $peer->getheaders(new BlockLocator([$bestHeader->getHash()], new Buffer('', 32)));
                $peer->sendheaders();

                return $peer;
            }, function (\Exception $e) {
                throw $e;
            });
    }

    /**
     * Request blocks from a Peer, by adding hashes to
     * $this->toDownload until we have enough of a batch.
     * It traces the existing 'best header' chain
     * @param Peer $peer
     */
    public function requestBlocks(Peer $peer)
    {
        if (!$this->keepRunning) {
            return;
        }

        if (null === $this->blockStatsCount) {
            $this->blockProcessTime = 0;
            $this->blockDeserializeTime = 0;
            $this->blockDeserializeBytes = 0;
            $this->blockDeserializeNTx = 0;
            $this->blockStatsCount = 0;
            $this->blockStatsBegin = \microtime(true);
        }

        $downloadStartHeight = $this->chain->getBestBlock()->getHeight() + 1;
        // this is our best header, which should match remote peer.
        // if we later use multiple peers, ensure we don't download blocks > than peer.bestKnownHeight
        $heightBestHeader = $this->chain->getBestHeader()->getHeight();
        $toDownload = [];
        echo "begin downloading\n";
        echo " - num requested: " . count($this->requested) . PHP_EOL;
        echo " - download start height: $downloadStartHeight\n";
        echo " - height of best header: $heightBestHeader\n";

        while (count($this->requested) < $this->batchSize && $downloadStartHeight + count($this->requested) <= $heightBestHeader) {
            $height = $downloadStartHeight + count($this->requested);
            echo "request $height\n";
            $hash = $this->chain->getBlockHash($height);
            if ($this->segwit) {
                $toDownload[] = Inventory::witnessBlock($hash);
            } else {
                $toDownload[] = Inventory::block($hash);
            }

            $this->requested[$hash->getBinary()] = 1;
        }
        echo "completed request\n";
        echo " - num requested " . count($this->requested) . "\n";

        $this->toDownload = array_merge($this->toDownload, $toDownload);

        if (count($this->toDownload) > 0) {
            // if nearTip don't bother sending a batch request, submit immediately
            // otherwise, send when we have batch/2 or batch items
            $nearTip = count($this->requested) < $this->batchSize;
            if ($nearTip || count($this->toDownload) % ($this->batchSize/2) === 0) {
                $peer->getdata($this->toDownload);
                $this->toDownload = [];
                die();
            }
        }
    }

    /**
     * This function triggers block downloading in response to a new header.
     * It outputs the log and triggers downloading only if we are not currently
     * downloading, as blocks being received continue the process.
     * @param Peer $peer
     */
    public function downloadBlocks(Peer $peer)
    {
        if ($this->downloading) {
            return;
        }

        $this->downloading = true;
        $bestBlock = $this->chain->getBestBlock();
        $this->logger->info("requesting blocks from {$bestBlock->getHeight()} {$bestBlock->getHash()->getHex()}");

        $this->requestBlocks($peer);
    }
}
