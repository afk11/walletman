<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\PrefixRegistry;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Network\Slip132\BitcoinRegistry;
use BitWasp\Bitcoin\Network\Slip132\BitcoinTestnetRegistry;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;

class NetworkInfo
{
    public function getP2pPort(string $networkName): int
    {
        switch ($networkName) {
            case NetworkName::BITCOIN_REGTEST:
                return 18444;
            case NetworkName::BITCOIN_TESTNET3:
                return 18333;
            case NetworkName::BITCOIN:
                return 8333;
            default:
                throw new \InvalidArgumentException("unknown network: $networkName");
        }
    }

    public function getNetwork(string $networkName): NetworkInterface
    {
        switch ($networkName) {
            case NetworkName::BITCOIN:
                return NetworkFactory::bitcoin();
            case NetworkName::BITCOIN_TESTNET3:
                return NetworkFactory::bitcoinTestnet();
            case NetworkName::BITCOIN_REGTEST:
                return NetworkFactory::bitcoinRegtest();
            default:
                throw new \InvalidArgumentException("unknown network: $networkName");
        }
    }
    public function getSlip132Registry(string $networkName): PrefixRegistry
    {
        switch ($networkName) {
            case NetworkName::BITCOIN:
                return new BitcoinRegistry();
            case NetworkName::BITCOIN_TESTNET3:
                return new BitcoinTestnetRegistry();
            case NetworkName::BITCOIN_REGTEST:
                return new BitcoinTestnetRegistry();
            default:
                throw new \InvalidArgumentException("unknown network: $networkName");
        }
    }
    public function getParams(string $networkName, Math $math): ParamsInterface
    {
        switch ($networkName) {
            case NetworkName::BITCOIN:
                return new Params($math);
            case NetworkName::BITCOIN_TESTNET3:
                return new TestnetParams($math);
            case NetworkName::BITCOIN_REGTEST:
                return new RegtestParams($math);
            default:
                throw new \InvalidArgumentException("unknown network: $networkName");
        }
    }
}
