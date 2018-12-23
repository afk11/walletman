<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\PrefixRegistry;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39WordListInterface;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\EnglishWordList;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\JapaneseWordList;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Network\Slip132\BitcoinRegistry;
use BitWasp\Bitcoin\Network\Slip132\BitcoinTestnetRegistry;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Buffertools\Buffer;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Create extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:create')
            // the short description shown while running "php bin/console list"
            ->setDescription('Create a new wallet')

            // An identifier is required for this wallet
            ->addArgument("database", InputArgument::REQUIRED, "Database to use for wallet")
            ->addArgument("identifier", InputArgument::REQUIRED, "Identifier for wallet")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Initialize wallet for regtest network")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Initialize wallet for testnet network")

            // wallet setup options
            ->addOption('birthday', null, InputOption::VALUE_REQUIRED, "Initialize wallet with a birthday block hash")

            // key derivation options
            ->addOption('bip44', null, InputOption::VALUE_NONE, "Setup a bip44 wallet account")
            ->addOption('bip44-account', null, InputOption::VALUE_REQUIRED, "BIP44 'account' value", '0')
            ->addOption('bip44-cointype', null, InputOption::VALUE_REQUIRED, "BIP44 'cointype' value", '0')

            ->addOption('bip49', null, InputOption::VALUE_NONE, "Setup a bip49 wallet account")
            ->addOption('bip49-account', null, InputOption::VALUE_REQUIRED, "BIP49 'account' value", '0')
            ->addOption('bip49-cointype', null, InputOption::VALUE_REQUIRED, "BIP49 'cointype' value", '0')

            ->addOption('bip84', null, InputOption::VALUE_NONE, "Setup a BIP84 wallet account")
            ->addOption('bip84-account', null, InputOption::VALUE_REQUIRED, "BIP84 'account' value", '0')
            ->addOption('bip84-cointype', null, InputOption::VALUE_REQUIRED, "BIP84 'cointype' value", '0')

            // settings for bip39 seed generation
            ->addOption('bip39-en', null, InputOption::VALUE_NONE, "Use the english wordlist for BIP39 (default)")
            ->addOption('bip39-jp', null, InputOption::VALUE_NONE, "Use the japanese wordlist for BIP39")
            ->addOption('bip39-custommnemonic', 'm', InputOption::VALUE_NONE, "Prompt for a user-provided BIP39 mnemonic")
            ->addOption('bip39-passphrase', 'p', InputOption::VALUE_NONE, "Prompt for a BIP39 passphrase")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    private function getBip39Wordlist(InputInterface $input): Bip39WordListInterface
    {
        if ($input->getOption('bip39-jp')) {
            return new JapaneseWordList();
        } else {
            return new EnglishWordList();
        }
    }

    private function getBip39Mnemonic(InputInterface $input, OutputInterface $output, Bip39WordListInterface $wordList): string
    {
        $bip39 = MnemonicFactory::bip39($wordList);
        if ($input->getOption('bip39-custommnemonic')) {
            return $this->promptForMnemonic($bip39, $input, $output);
        }

        $random = new Random();
        $entropy = $random->bytes(16);
        $mnemonic = $bip39->entropyToMnemonic($entropy);
        $output->write("<info>Your mnemonic is: $mnemonic</info>\n");
        $output->write("<comment>It is vital that you record your seed now so your wallet can\n" .
            "be recovered in case of hardware failure.</comment>\n");
        return $mnemonic;
    }

    private function parseHardenedBip32Index(InputInterface $input, string $optionName): int
    {
        $indexValue = $input->getOption($optionName);
        if (!is_string($indexValue)) {
            throw new \RuntimeException("Invalid value provided for {$optionName}");
        }
        if ($indexValue != (string)(int)$indexValue) {
            throw new \RuntimeException("invalid value for hardened index: $optionName");
        }
        $index = (int) $indexValue;
        if ($index < 0 || ($index & (1 << 31)) != 0) {
            throw new \RuntimeException("invalid value for hardened index: $optionName");
        }
        return $index;
    }

    private function parseBirthday(InputInterface $input): ?BlockRef
    {
        $birthdayValue = $input->getOption('birthday');
        if (!is_string($birthdayValue)) {
            return null;
        }

        if (substr_count($birthdayValue, ",") !== 1) {
            throw new \RuntimeException("Invalid birthday, should be [height],[hash]");
        }

        list ($height, $hash) = explode(",", $birthdayValue);
        if ($height !== (string)(int)$height) {
            throw new \RuntimeException("Invalid height");
        }

        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new \RuntimeException("Invalid hash for birthday");
        }

        return new BlockRef((int) $height, Buffer::hex($hash, 32));
    }

    protected function getPrefixConfig(NetworkInterface $network, Slip132 $slip132, PrefixRegistry $registry): GlobalPrefixConfig
    {
        return new GlobalPrefixConfig([
            new NetworkConfig($network, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $this->getStringArgument($input, 'database');
        $identifier = $this->getStringArgument($input, 'identifier');
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');
        $fUseBip44 = $input->getOption('bip44');
        $fUseBip49 = $input->getOption('bip49');
        $fUseBip84 = $input->getOption('bip84');

        $fBip39Pass = $input->getOption('bip39-passphrase');
        $wordlist = $this->getBip39Wordlist($input);
        $birthday = $this->parseBirthday($input);

        if ($fIsRegtest) {
            $net = NetworkFactory::bitcoinRegtest();
            $registry = new BitcoinTestnetRegistry();
        } else if ($fIsTestnet) {
            $net = NetworkFactory::bitcoinTestnet();
            $registry = new BitcoinTestnetRegistry();
        } else {
            $net = NetworkFactory::bitcoin();
            $registry = new BitcoinRegistry();
        }

        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);

        $slip132 = new Slip132();
        if ($fUseBip44) {
            $coinType = $this->parseHardenedBip32Index($input, "bip44-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip44-account");
            $path = "M/44'/{$coinType}'/{$account}'";
            $scriptFactory = $slip132->p2pkh($registry)->getScriptDataFactory();
        } else if ($fUseBip49) {
            $coinType = $this->parseHardenedBip32Index($input, "bip49-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip49-account");
            $path = "M/49'/{$coinType}'/{$account}'";
            $scriptFactory = $slip132->p2shP2wpkh($registry)->getScriptDataFactory();
        } else if ($fUseBip84) {
            $coinType = $this->parseHardenedBip32Index($input, "bip84-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip84-account");
            $path = "M/84'/{$coinType}'/{$account}'";
            $scriptFactory = $slip132->p2wpkh($registry)->getScriptDataFactory();
        } else {
            throw new \RuntimeException("A wallet type is required");
        }

        if ($db->checkWalletExists($identifier)) {
            throw new \RuntimeException("Wallet already exists");
        }

        $mnemonic = $this->getBip39Mnemonic($input, $output, $wordlist);
        $passphrase = '';
        if ($fBip39Pass) {
            $passphrase = $this->promptForPassphrase($input, $output);
        }

        $seedGenerator = new Bip39SeedGenerator();
        $seed = $seedGenerator->getSeed($mnemonic, $passphrase);

        $ecAdapter = Bitcoin::getEcAdapter();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $this->getPrefixConfig($net, $slip132, $registry)));
        $hdFactory = new HierarchicalKeyFactory($ecAdapter, $hdSerializer);
        $rootKey = $hdFactory->fromEntropy($seed, $scriptFactory);

        $walletFactory = new Factory($db, $net, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $rootKey, $path, $birthday);
        $dbScript = $wallet->getScriptGenerator()->generate();

        $addrCreator = new AddressCreator();
        $addrString = $dbScript->getAddress($addrCreator)->getAddress($net);
        echo "$addrString\n";
    }
}
