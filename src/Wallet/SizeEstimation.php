<?php

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptType;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use \BitWasp\Bitcoin\Script\ScriptInfo\Multisig;
use \BitWasp\Bitcoin\Script\ScriptInfo\PayToPubKey;
use \BitWasp\Bitcoin\Script\Classifier\OutputClassifier;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;

class SizeEstimation
{
    const SIZE_DER_SIGNATURE = 72;
    const SIZE_V0_P2WSH = 36;

    /**
     * @param bool $compressed
     * @return int
     */
    public static function getPublicKeySize($compressed = true)
    {
        return $compressed ? PublicKeyInterface::LENGTH_COMPRESSED : PublicKeyInterface::LENGTH_UNCOMPRESSED;
    }

    /**
     * @param int $length
     * @return int
     */
    public static function getLengthOfScriptLengthElement($length)
    {
        if ($length < 75) {
            return 1;
        } else if ($length <= 0xff) {
            return 2;
        } else if ($length <= 0xffff) {
            return 3;
        } else if ($length <= 0xffffffff) {
            return 5;
        } else {
            throw new \RuntimeException('Size of pushdata too large');
        }
    }

    /**
     * @param int $length
     * @return int
     */
    public static function getLengthOfVarInt($length)
    {
        if ($length < 253) {
            return 1;
        } else if ($length < 65535) {
            $num_bytes = 2;
        } else if ($length < 4294967295) {
            $num_bytes = 4;
        } else if ($length < 18446744073709551615) {
            $num_bytes = 8;
        } else {
            throw new \InvalidArgumentException("Invalid decimal");
        }
        return 1 + $num_bytes;
    }

    /**
     * @param array $vectorSizes
     * @return int|mixed
     */
    public static function getLengthOfVector(array $vectorSizes)
    {
        $vectorSize = self::getLengthOfVarInt(count($vectorSizes));
        foreach ($vectorSizes as $size) {
            $vectorSize += self::getLengthOfVarInt($size) + $size;
        }
        return $vectorSize;
    }

    /**
     * @param Multisig $multisig
     * @return array - first is array of stack sizes, second is script len
     */
    public static function estimateMultisigStackSize(Multisig $multisig)
    {
        $stackSizes = [0];
        for ($i = 0; $i < $multisig->getRequiredSigCount(); $i++) {
            $stackSizes[] = self::SIZE_DER_SIGNATURE;
        }

        $scriptSize = 1; // OP_$m
        $keys = $multisig->getKeyBuffers();
        for ($i = 0, $n = $multisig->getKeyCount(); $i < $n; $i++) {
            $scriptSize += 1 + $keys[$i]->getSize();
        }

        $scriptSize += 1 + 1; // OP_$n OP_CHECKMULTISIG
        return [$stackSizes, $scriptSize];
    }

    /**
     * @param PayToPubKey $info
     * @return array - first is array of stack sizes, second is script len
     */
    public static function estimateP2PKStackSize(PayToPubKey $info)
    {
        $stackSizes = [self::SIZE_DER_SIGNATURE];

        $scriptSize = 1 + $info->getKeyBuffer()->getSize(); // PUSHDATA[<75] PUBLICKEY
        $scriptSize += 1; // OP_CHECKSIG
        return [$stackSizes, $scriptSize];
    }

    /**
     * @param bool $isCompressed
     * @return array - first is array of stack sizes, second is script len
     */
    public static function estimateP2PKHStackSize($isCompressed = true)
    {
        $pubKeySize = self::getPublicKeySize($isCompressed);
        $stackSizes = [self::SIZE_DER_SIGNATURE, $pubKeySize];

        $scriptSize = 1 + 1 + 1 + 20 + 1 + 1;
        return [$stackSizes, $scriptSize];
    }

    /**
     * @param array $stackSizes - array of integer size of a value (for scriptSig or witness)
     * @param bool $isWitness
     * @param ScriptInterface $redeemScript
     * @param ScriptInterface $witnessScript
     * @return array
     */
    public static function estimateSizeForStack(array $stackSizes, $isWitness, ScriptInterface $redeemScript = null, ScriptInterface $witnessScript = null)
    {
        assert(($witnessScript === null) || $isWitness);

        if ($isWitness) {
            $scriptSigSizes = [];
            $witnessSizes = $stackSizes;
            if ($witnessScript instanceof ScriptInterface) {
                $witnessSizes[] = $witnessScript->getBuffer()->getSize();
            }
        } else {
            $scriptSigSizes = $stackSizes;
            $witnessSizes = [];
        }

        if ($redeemScript instanceof ScriptInterface) {
            $scriptSigSizes[] = $redeemScript->getBuffer()->getSize();
        }

        $scriptSigSize = 0;
        foreach ($scriptSigSizes as $size) {
            $len = self::getLengthOfScriptLengthElement($size);
            $scriptSigSize += $len;
            $scriptSigSize += $size;
        }

        // Make sure to count the CScriptBase length prefix..
        $scriptSigSize += self::getLengthOfVarInt($scriptSigSize);

        // make sure this is set to 0 when not used.
        // Summing WitnessSize is used in addition to $withWitness
        // because a tx without witnesses _cannot_ use witness serialization
        // total witnessSize = 0 means don't use that format
        $witnessSize = 0;
        if (count($witnessSizes) > 0) {
            // witness has a prefix indicating `n` elements in vector
            $witnessSize = self::getLengthOfVector($witnessSizes);
        }
        return [$scriptSigSize, $witnessSize];
    }

    /**
     * @param ScriptInterface $script - not the scriptPubKey, might be SPK,RS,WS
     * @param ScriptInterface|null $redeemScript
     * @param ScriptInterface $witnessScript
     * @param bool $isWitness
     * @return array
     */
    public static function estimateInputFromScripts(ScriptInterface $script, ScriptInterface $redeemScript = null, ScriptInterface $witnessScript = null, $isWitness)
    {
        assert($witnessScript === null || $isWitness);
        $classifier = new OutputClassifier();
        if ($classifier->isMultisig($script)) {
            list ($stackSizes, ) = SizeEstimation::estimateMultisigStackSize(new Multisig($script));
        } else if ($classifier->isPayToPublicKey($script)) {
            list ($stackSizes, ) = SizeEstimation::estimateP2PKStackSize(new PayToPubKey($script));
        } else if ($classifier->isPayToPublicKeyHash($script)) {
            // defaults to compressed, tough luck
            list ($stackSizes, ) = SizeEstimation::estimateP2PKHStackSize();
        } else {
            throw new \InvalidArgumentException("Unsupported script type");
        }

        return self::estimateSizeForStack($stackSizes, $isWitness, $redeemScript, $witnessScript);
    }

    /**
     * @param ScriptInterface $scriptPubKey
     * @param ScriptInterface $redeemScript
     * @param ScriptInterface $witnessScript
     * @return array
     */
    public static function estimateUtxoFromScripts(
        ScriptInterface $scriptPubKey,
        ScriptInterface $redeemScript = null,
        ScriptInterface $witnessScript = null
    ) {
        $classifier = new OutputClassifier();
        $decodePK = $classifier->decode($scriptPubKey);
        $witness = false;
        if ($decodePK->getType() === ScriptType::P2SH) {
            if (null === $redeemScript) {
                throw new \RuntimeException("Can't estimate, missing redeem script");
            }
            $decodePK = $classifier->decode($redeemScript);
        }

        if ($decodePK->getType() === ScriptType::P2WKH) {
            $scriptSitu = ScriptFactory::scriptPubKey()->p2pkh($decodePK->getSolution());
            $decodePK = $classifier->decode($scriptSitu);
            $witness = true;
        } else if ($decodePK->getType() === ScriptType::P2WSH) {
            if (null === $witnessScript) {
                throw new \RuntimeException("Can't estimate, missing witness script");
            }
            $decodePK = $classifier->decode($witnessScript);
            $witness = true;
        }

        if (!in_array($decodePK->getType(), [ScriptType::MULTISIG, ScriptType::P2PKH, ScriptType::P2PK])) {
            throw new \RuntimeException("Unsupported script type");
        }

        $script = $decodePK->getScript();
        return SizeEstimation::estimateInputFromScripts($script, $redeemScript, $witnessScript, $witness);
    }

    /**
     * @param ScriptAndSignData[] $inputScripts
     * @param bool $withWitness
     * @return integer
     */
    public static function estimateInputsSize(array $inputScripts, $withWitness)
    {
        $inputSize = 0;
        $witnessSize = 0;
        foreach ($inputScripts as $inputScript) {
            $signData = $inputScript->getSignData();
            list ($estimateScriptSig, $estimateWitness) = SizeEstimation::estimateUtxoFromScripts(
                $inputScript->getScriptPubKey(),
                $signData->hasRedeemScript() ? $signData->getRedeemScript() : null,
                $signData->hasWitnessScript() ? $signData->getWitnessScript() : null
            );

            $inputSize += 32 + 4 + 4 + $estimateScriptSig;
            if ($withWitness) {
                $witnessSize += $estimateWitness;
            }
        }

        if ($withWitness && $witnessSize != 0) {
            $inputSize += $witnessSize;
            $inputSize += 2; // flag bytes
        }

        return $inputSize;
    }

    /**
     * @param TransactionOutputInterface[] $outputs
     * @return int
     */
    public static function estimateOutputsSize(array $outputs): int
    {
        $outputSize = 0;
        foreach ($outputs as $output) {
            $scriptSize = $output->getScript()->getBuffer()->getSize();
            $outputSize += 8 + SizeEstimation::getLengthOfVarInt($scriptSize) + $scriptSize;
        }
        return $outputSize;
    }

    /**
     * @param ScriptAndSignData[] $inputScripts
     * @param TransactionOutputInterface[] $outputs
     * @return int
     */
    public static function estimateVsize(array $inputScripts, array $outputs): int
    {
        return (int)ceil(self::estimateWeight($inputScripts, $outputs) / 4);
    }

    /**
     * @param ScriptAndSignData[] $inputScripts
     * @param TransactionOutputInterface[] $outputs
     * @return int
     */
    public static function estimateWeight(array $inputScripts, array $outputs): int
    {
        $outputsSize = SizeEstimation::estimateOutputsSize($outputs);
        $outputsSize += SizeEstimation::getLengthOfVarInt(count($outputs));

        $baseSize = 4 +
            SizeEstimation::getLengthOfVarInt(count($inputScripts)) + SizeEstimation::estimateInputsSize($inputScripts, false) +
            $outputsSize + 4;

        $witnessSize = 4 +
            SizeEstimation::getLengthOfVarInt(count($inputScripts)) + SizeEstimation::estimateInputsSize($inputScripts, true) +
            $outputsSize + 4;

        return ($baseSize * 3) + $witnessSize;
    }
}
