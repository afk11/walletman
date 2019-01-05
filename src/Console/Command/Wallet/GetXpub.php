<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetXpub extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:getxpubs')
            // the short description shown while running "php bin/console list"
            ->setDescription('Returns xpubs to belonging to the wallet')

            // An identifier is required for this wallet
            ->addArgument("identifier", InputArgument::REQUIRED, "Identifier for wallet")

            ->addOption('external', 'r', InputOption::VALUE_NONE, "Request the 'external' chain xpub")
            ->addOption('change', 't', InputOption::VALUE_NONE, "Request the 'change' chain xpub")
            ->addOption('address', 'a', InputOption::VALUE_REQUIRED, "Request an addresses xpub (external or change is required)")

            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Returns xpub(s) belonging to the BIP44/49/84 account, unless extra parameters are provided. The external and change xpub(s) can be requested by chosing one of these optional flags. address xpub(s) can be requested but an index is required, and must be used with change or external. If multiple xpubs are provided, they will be in cosigner order - not sorted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $identifier = $this->getStringArgument($input, "identifier");
        $fIsExternal = $input->getOption('external');
        $fIsChange = $input->getOption('change');
        $fAddress = $input->getOption('address');
        $dataDir = $this->loadDataDir($input);

        if ($fIsExternal && $fIsChange) {
            throw new \RuntimeException("Cannot set both external and change flags");
        } else if ($fAddress !== null && !($fIsExternal || $fIsChange)) {
            throw new \RuntimeException("Must use external or change flag with address option");
        }

        $ecAdapter = Bitcoin::getEcAdapter();
        $netInfo = new NetworkInfo();
        $dbMgr = new DbManager();

        $config = Config::fromDataDir($dataDir);
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));
        $net = $netInfo->getNetwork($config->getNetwork());
        $registry = $netInfo->getSlip132Registry($config->getNetwork());

        // init with all prefixes we support
        $slip132 = new Slip132();
        $prefixConfig = new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ]);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $prefixConfig));
        $walletFactory = new Factory($db, $net, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->loadWallet($identifier);

        if (!($wallet instanceof Bip44Wallet)) {
            throw new \RuntimeException("Wallet must be HD to get xpubs");
        }

        if ($fIsExternal) {
            $path = $wallet->getExternalScriptPath();
        } else if ($fIsChange) {
            $path = $wallet->getChangeScriptPath();
        } else {
            $path = $wallet->getAccountPath();
        }

        foreach ($wallet->getKeysByPath($path) as $key) {
            echo "{$key->getBase58Key()}\n";
        }
    }
}
