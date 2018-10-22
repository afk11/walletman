<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39WordListInterface;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\EnglishWordList;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\JapaneseWordList;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
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
            ->addOption('bip44-account', null, InputOption::VALUE_REQUIRED, "BIP44 'account' value", 0)
            ->addOption('bip44-cointype', null, InputOption::VALUE_REQUIRED, "BIP44 'cointype' value", 0)

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
        return $bip39->entropyToMnemonic($entropy);
    }

    private function parseHardenedBip32Index(InputInterface $input, string $optionName): int
    {
        $index = $input->getOption($optionName);
        if (!is_int($index) || $index < 0 || ($index & (1 << 31)) != 0) {
            throw new \RuntimeException("invalid value for hardened index: $optionName");
        }
        return $index;
    }

    private function parseBirthday(InputInterface $input): ?BlockRef
    {
        if (!is_string($input->getOption('birthday'))) {
            return null;
        }

        if (substr_count($input->getOption('birthday'), ",") !== 1) {
            throw new \RuntimeException("Invalid birthday, should be [height],[hash]");
        }
        list ($height, $hash) = explode(",", $input->getOption('birthday'));
        if ((string)$height !== (string)(int)$height) {
            throw new \RuntimeException("Invalid height");
        }
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new \RuntimeException("Invalid hash for birthday");
        }
        return new BlockRef((int) $height, Buffer::hex($hash, 32));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $this->getStringArgument($input, 'database');
        $identifier = $this->getStringArgument($input, 'identifier');
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');
        $fBip39Pass = $input->getOption('bip39-passphrase');
        $wordlist = $this->getBip39Wordlist($input);
        $birthday = $this->parseBirthday($input);

        if ($fIsRegtest) {
            $net = NetworkFactory::bitcoinRegtest();
        } else if ($fIsTestnet) {
            $net = NetworkFactory::bitcoinTestnet();
        } else {
            $net = NetworkFactory::bitcoin();
        }

        $ecAdapter = Bitcoin::getEcAdapter();
        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);
        $walletFactory = new Factory($db, $net, $ecAdapter);

        $mnemonic = $this->getBip39Mnemonic($input, $output, $wordlist);

        $seedGenerator = new Bip39SeedGenerator();
        $output->write("<info>Your mnemonic is: $mnemonic</info>\n");
        $output->write("<comment>It is vital that you record your seed now so your wallet can\n" .
                    "be recovered in case of hardware failure.</comment>\n");

        $passphrase = '';
        if ($fBip39Pass) {
            $passphrase = $this->promptForPassphrase($input, $output);
        }

        $seed = $seedGenerator->getSeed($mnemonic, $passphrase);
        $rootKey = HierarchicalKeyFactory::fromEntropy($seed);

        if ($input->getOption('bip44')) {
            $coinType = $this->parseHardenedBip32Index($input, "bip44-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip44-account");
            $wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $rootKey, "M/44'/{$coinType}'/{$account}'", $birthday);
        } else {
            throw new \RuntimeException("A wallet type is required");
        }

        $addrGen = $wallet->getScriptGenerator();
        $dbScript = $addrGen->generate();
        $addrString = $dbScript->getAddress(new AddressCreator())->getAddress($net);
        echo "$addrString\n";
    }
}
