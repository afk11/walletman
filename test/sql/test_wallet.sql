insert into wallet (id, type, identifier, birthday_hash, birthday_height, gapLimit) values (10001, 1, "bip44", "000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943", 0, 5);

insert into `key` (walletId, path, childSequence, depth, `key`, keyIndex, status, isLeaf) values (10001, "M/44'/0'/0'", 0, 3, "tpubDDp9RstNpbig5rDShTKvhsMekYfSSFHM8P9fgHbt4SYDPz5whcWZx4bbZV6K4NPtaKTF3YFbbRwxth2PnAPMJEwHb9gHhjjnhn3eGUXzAqe", 0, 0, 0);
insert into key (walletId, path, childSequence, depth, `key`, keyIndex, status, isLeaf) values (10001, "M/44'/0'/0'/0", 0, 4, "tpubDE2DiZurfndZLTPUUqBoqK56dPczB6ENfEvtoWyA8zNyZxLLWDDdP5Eyq9q3mfgotUGY21a5zg7UTHwTqD84Doz5vpdE6WUCKyig3FKtT6R", 0, 0, 0);
insert into `key` (walletId, path, childSequence, depth, `key`, keyIndex, status, isLeaf) values (10001, "M/44'/0'/0'/1", 0, 4, "tpubDE2DiZurfndZPfCFv1kGsyGsYzmrZRddZqC9G1Brive4BH9CdyJicFTjHFPfCDDCyTWifDrdkGUNNKAzxccT5HBBU1Eq2mAc7hF9sUHjmwt", 0, 0, 0);

insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/0/0", "76a9145947fbf644461dd030a795469721042a96a572aa88ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/0/1", "76a91433496192592d0bcba7b30ac208eeb88f667e8d4388ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/0/2", "76a9141db7257338ed987da106fa25d81f1df33d09976888ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/0/3", "76a914fabc58cd14db2dfa94955e0b402f774d4809c23c88ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/0/4", "76a91424ff9df75bf09e346f8623744a73192faf01739888ac", NULL, NULL);

insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/1/0", "76a9143803b3f6910d1155a0907d072c2420e872c60c0688ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/1/1", "76a9140195c36e8d06d56bde6ac2f5415b4fb20cb5e5b488ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/1/2", "76a914f0ebbf8e29069cc3dbc3220a973ecb9bac2e140088ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/1/3", "76a914522553d2baf04b57e3a0b2dcb68fec7e4a799fad88ac", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10001, "M/44'/0'/0'/1/4", "76a914994e189feadb5af026d7bb7610f91639c79552db88ac", NULL, NULL);

insert into wallet (id, type, identifier, birthday_hash, birthday_height, gapLimit) values (10002, 1, "bip84", "000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943", 0, 5);

insert into `key` (walletId, path, childSequence, depth, `key`, keyIndex, status, isLeaf) values (10002, "M/84'/0'/0'", 0, 3, "vpub5ZVzGbKr459YJYEPVy6aJaoxde4StU8wBUrMjpXYkYqhLY2fwTG7PVvQ6s6eh7viXMh1moQ1XYfLs3CAzB9hEJVveHcFXeohrZEegaFpHzd", 0, 0, 0);
insert into key (walletId, path, childSequence, depth, `key`, keyIndex, status, isLeaf) values (10002, "M/84'/0'/0'/0", 0, 4, "vpub5anxAE9pSNv488ci56wsM6e4zgajGM9DZWeVtMPxJmhLmaswJ25ivzHENAZ8sTrxuN7Pt449kFJ3Px8haCtuxHqr6BCZdYm6NNVTuAkV7rN", 0, 0, 0);
insert into `key` (walletId, path, childSequence, depth, `key`, keyIndex, status, isLeaf) values (10002, "M/84'/0'/0'/1", 0, 4, "vpub5anxAE9pSNv4BcFiCQEqeZnewnB4q5fNR77ojhoSTDxeUB9wcZugkZrAjok1RiP1SYNWYdyQyafJA4ascRZMbWqgfkdzSHVKgxXPR6Vpegb", 0, 0, 0);

insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/0/0", "0014efc22b20c7d51c1549da81b4e86baaf585a47afd", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/0/1", "0014f5eed4c937f5f4039dc740b83f2c3e8bf535eaae", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/0/2", "00146cbf57c01f6eb32de87c6fc9204c6c03746509d5", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/0/3", "00149f5fd808d3ffa35a32d7efcb0db92ba9d066afe1", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/0/4", "0014b40db83f032703502defded75fa2c41a91bab4d6", NULL, NULL);

insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/1/0", "0014274093bcfd40cb31b72ad2c8813812a93bc0f6b3", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/1/1", "0014327f005edf7fc4841b829887c3c6ed7a03c44cd4", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/1/2", "00141374d1ebdef3abc72faabaaeab9a6832bee91695", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/1/3", "00142278fcc87f6e127cc03b113034cc8d26228571e6", NULL, NULL);
insert into script (walletId, keyIdentifier, scriptPubKey, redeemScript, witnessScript) values (10002, "M/84'/0'/0'/1/4", "0014072b7d4d39299ee11ad5729f4a64e46e6b90b58a", NULL, NULL);

