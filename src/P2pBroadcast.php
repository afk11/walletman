<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Networking\Factory;
use BitWasp\Bitcoin\Networking\Ip\Ipv4;
use BitWasp\Bitcoin\Networking\Message;
use BitWasp\Bitcoin\Networking\Messages\GetData;
use BitWasp\Bitcoin\Networking\Peer\ConnectionParams;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Services;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use React\EventLoop\LoopInterface;

class P2pBroadcast
{
    const PING_TIMEOUT = 1200;
    const HEADERS_FULL = 2000;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var NetworkInterface
     */
    private $network;

    // Cli related state

    /**
     * @var bool
     */
    private $segwit = true;

    /**
     * @var resource
     */
    private $blockStatsFileHandle;

    private $userAgent = "/na".":0.0."."1/";

    public function __destruct()
    {
        if ($this->blockStatsFileHandle !== null) {
            fclose($this->blockStatsFileHandle);
        }
    }

    public function __construct(NetworkInterface $network, string $ip, int $port)
    {
        $this->network = $network;
        $this->host = $ip;
        $this->port = $port;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $ua)
    {
        $this->userAgent = $ua;
    }

    public function broadcast(LoopInterface $loop, TransactionInterface $tx)
    {
        $netFactory = new Factory($loop, $this->network);

        $connParams = new ConnectionParams();
        $connParams->setRequiredServices($this->segwit ? Services::WITNESS : 0);
        $connParams->setLocalServices($this->segwit ? Services::WITNESS : 0);
        $connParams->setProtocolVersion(70013); // above this causes problems, todo
        $connParams->setUserAgent($this->getUserAgent());

        return $netFactory
            ->getConnector($connParams)
            ->connect($netFactory->getAddress(new Ipv4($this->host), $this->port))
            ->then(function (Peer $peer) use ($loop, $tx) {
                if ($tx->hasWitness()) {
                    $txid = $tx->getWitnessTxId();
                    $inv = Inventory::witnessTx($txid);
                } else {
                    $txid = $tx->getTxId();
                    $inv = Inventory::tx($txid);
                }

                echo "connected, send inv\n";
                $peer->inv([$inv]);
                $peer->on(Message::GETDATA, function (Peer $peer, GetData $getdata) use ($txid, $tx) {
                    echo "received getdata\n";
                    foreach ($getdata->getItems() as $item) {
                        echo "getdata ({$item->getType()} hash: ".$item->getHash()->getHex().PHP_EOL;
                        if (($item->isWitnessTx() || $item->isTx()) && ($item->getHash()->equals($txid))) {
                            $peer->tx($tx->getBuffer());
                            usleep(50);
                            $peer->close();
                        }
                    }
                });
            });
    }
}
