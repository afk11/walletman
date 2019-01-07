<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Validation;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\NetworkName;
use BitWasp\Wallet\Validation\Base58ExtendedKeyValidator;

class Base58ExtendedKeyValidatorTest extends TestCase
{
    public function getValidatorFixtures(): array
    {
        $slip132 = new Slip132();
        $netInfo = new NetworkInfo();
        $btc = $netInfo->getNetwork(NetworkName::BITCOIN);
        $btcRegistry = $netInfo->getSlip132Registry(NetworkName::BITCOIN);
        return [
            [
                "xpub6DSAAAAh7KmAAAAbFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt",
                false,
                $btc,
                null,
            ],
            [
                "tprv6DSWue4h7KmDp42bFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt",
                false,
                $btc,
                null,
            ],
            [
                "xpub6DSWue4h7KmDp42bFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt",
                true,
                $btc,
                null,
            ],
            [
                "ypub6WbvEDCFCLbUssJYTYBC9DxaKYEZZJCak5AdGJgCKeJBH5Nho9yiKBLkR6dkPpu1vBaJs5XBqFtFCuQssQUD9QV4LtqJKPePhfN9iEe1x2u",
                true,
                $btc,
                new GlobalPrefixConfig([
                    new NetworkConfig($btc, [
                        $slip132->p2shP2wpkh($btcRegistry),
                    ])
                ]),
            ],
            [
                "zpub6rq3VG1WeoKThizrqQF58wByKWeEex6vqvwEsQ76GaMDYwHax5vMskYxBgvzgkYQ9vAEmhnFNC5YQBeRrxokRFEL7ePwsJ5ewTVEEoZ2uYP",
                true,
                $btc,
                new GlobalPrefixConfig([
                    new NetworkConfig($btc, [
                        $slip132->p2wpkh($btcRegistry),
                    ])
                ]),
            ],
            [
                "xprv9s21ZrQH143K2DtLHdEKy8Z3ciqZcnNPLc55G8qAgAUn565JjeAuHd7chPrUzRtBkeTHJvmL5vYYHyiZJhqHo41wzjqnpoHVjEqbfWAfB7Y",
                true,
                $btc,
                null,
            ],
            [
                // same as above, but with an initialized global prefix
                "xprv9s21ZrQH143K2DtLHdEKy8Z3ciqZcnNPLc55G8qAgAUn565JjeAuHd7chPrUzRtBkeTHJvmL5vYYHyiZJhqHo41wzjqnpoHVjEqbfWAfB7Y",
                true,
                $btc,
                new GlobalPrefixConfig([
                    new NetworkConfig($btc, [
                        $slip132->p2pkh($btcRegistry),
                    ])
                ]),
            ],
        ];
    }

    /**
     * @dataProvider getValidatorFixtures
     * @param string $input
     * @param bool $result
     * @param NetworkInterface $network
     * @param GlobalPrefixConfig|null $config
     */
    public function testValidator(string $input, bool $result, NetworkInterface $network, ?GlobalPrefixConfig $config)
    {
        $ec = Bitcoin::getEcAdapter();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ec, $config));
        $validator = new Base58ExtendedKeyValidator($hdSerializer, $network);
        if ($result) {
            $this->assertTrue($validator->validate($input));
        } else {
            $this->assertFalse($validator->validate($input));
        }
    }
}
