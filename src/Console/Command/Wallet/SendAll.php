<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\KeyToScriptHelper;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Wallet\HdWallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SendAll extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:sendall')

            // the short description shown while running "php bin/console list"
            ->setDescription('Sends entire balance to the provided address')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")
            ->addArgument('identifier', InputArgument::REQUIRED, "Wallet identifier")
            ->addArgument('address', InputArgument::REQUIRED, "Destination for funds")

            ->addOption('feerate-custom', null, InputOption::VALUE_REQUIRED, "Provide fee rate in satoshis per kilobyte")
            ->addOption('bip39-passphrase', 'p', InputOption::VALUE_NONE, "Prompt for a BIP39 passphrase")

            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            ->setHelp('This command will create, sign, and broadcast a transaction emptying the wallet, sending all funds to the provided address');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fBip39Pass = $input->getOption('bip39-passphrase');
        $dataDir = $this->loadDataDir($input);
        $identifier = $this->getStringArgument($input, 'identifier');
        $destAddress = $this->getStringArgument($input, 'address');

        $ecAdapter = Bitcoin::getEcAdapter();
        $config = Config::fromDataDir($dataDir);
        $addrCreator = new AddressCreator();
        $dbMgr = new DbManager();
        $netInfo = new NetworkInfo();
        $net = $netInfo->getNetwork($config->getNetwork());
        $registry = $netInfo->getSlip132Registry($config->getNetwork());

        if (is_string($input->getOption('feerate-custom'))) {
            $customRate = $input->getOption('feerate-custom');
            if ($customRate !== (string)(int)$customRate) {
                throw new \RuntimeException("invalid fee rate provided");
            }
            $feeRate = (int)$customRate;
        } else {
            throw new \RuntimeException("must select a feerate option");
        }

        $scriptPubKey = $addrCreator->fromString($destAddress, $net)->getScriptPubKey();

        $slip132 = new Slip132(new KeyToScriptHelper($ecAdapter));
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ])));

        $db = $dbMgr->loadDb($config->getDbPath($dataDir));
        $walletFactory = new Factory($db, $net, $hdSerializer, $ecAdapter);

        /** @var Bip44Wallet $bip44Wallet */
        $bip44Wallet = $walletFactory->loadWallet($identifier);

        $bip39 = MnemonicFactory::bip39();
        $mnemonic = $this->promptForMnemonic($bip39, $input, $output);

        $passphrase = '';
        if ($fBip39Pass) {
            $passphrase = $this->promptForPassphrase($input, $output);
        }

        $seed = (new Bip39SeedGenerator())->getSeed($mnemonic, $passphrase);
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootNode = $hdFactory->fromEntropy($seed);
        $accountNode = $rootNode->derivePath("44'/0'/0'");

        $bip44Wallet->unlockWithAccountKey($accountNode);

        $preparedTx = $bip44Wallet->sendAllCoins($scriptPubKey, $feeRate);
        $signedTx = $bip44Wallet->signTx($preparedTx);
        echo $signedTx->getHex().PHP_EOL;
    }
}
