walletman
===========

`walletman` is a an experimental wallet project which operates under the trusted node model.

It works by first downloading headers, then downloading full blocks from the peer. As of now
the implementation is still missing some final work on reorg handling.

It uses sqlite for storage, which is opened while the p2p sync daemon is running. For now,
to get new addresses from the CLI, the node should be stopped.

## Supported wallet types

Currently the HD wallet implementation provides support for BIP44, BIP49, and BIP84 wallets.

## How wallet birthday works

When generating a brand new wallet, the wallet will report it's birthday block height & hash (if available).
This aids future recovery by allowing the node to skip processing blocks before it's birthday, as
they don't contain transactions for our wallet.

When recovering an old wallet, if the wallet's birthday is unknown, block downloading will
start from the genesis block so all outputs are discovered.

When the node starts up and is configured with wallets, the earliest birthday is taken and the chain hash
@ `birthday.height` must equal `birthday.hash`. Block history up to and including the birthday hash
is marked as 'valid' and trusted, and block downloading starts from the `birthday.height + 1`

If the node starts without any wallets configured, it will download headers but not blocks.

## Project data directory

Project configuration and data storage is contained in a data directory. The data directory should
be initialized by the `wallet db:init` command.

The default wallet directory is assumed to be `$HOME/.walletman`, though this can be overridden
via an optional flag in all commands.

The data directory contains the project configuration, and the sqlite database.

#### Help for a command?

To see documentation for a command, pass the command name to the `help` command, eg:

 * `bin/wallet help wallet:create`

#### Initialize data directory

 * `bin/wallet db:init`

This command initializes the data directory with it's config file and to bootstrap
the database. The chain database is initialized with the genesis block, and the default
network is bitcoin, though regtest or testnet3 can be used.

#### Create & recover wallets

See `bin/wallet help wallet:create` to see the full list of options.

The project allows importing old wallets, or generating new wallets. By default, a
fresh seed is generated. To restore an old wallet, the `--bip39-recovery` flag should be passed.
To initialize from the account public key, the `--public-only` flag should be passed.

#### Generate new addresses

 * `bin/wallet wallet:getnewaddress <walletIdentifier>`

This command generates a new change address. The `--change` flag can be passed to
request an address from the change address chain.

#### Syncing the wallet

 * `bin/wallet wallet:sync`

This command will launch the p2p node and have it synchronize the chain. The command
has various debugging options relating to block download stats.

As of right now, the `--mempool` option exists but doesn't have any effect on the wallet.
