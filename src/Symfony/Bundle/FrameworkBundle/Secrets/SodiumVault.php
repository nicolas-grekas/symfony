<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Secrets;

/**
 * @author Tobias Schultze <http://tobion.de>
 * @author Jérémy Derussé <jeremy@derusse.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class SodiumVault
{
    private $encryptionKey;
    private $decryptionKey;
    private $secretsDir;

    public function __construct(string $secretsDir)
    {
        if (!\function_exists('sodium_crypto_box_seal')) {
            throw new \LogicException('The "sodium" PHP extension is required to deal with secrets. Alternatively, try running "composer require paragonie/sodium_compat" if you cannot enable the extension."');
        }

        if (!is_dir($secretsDir) && !@mkdir($secretsDir, 0777, true) && !is_dir($secretsDir)) {
            throw new \RuntimeException(sprintf('Unable to create the secrets directory (%s)', $secretsDir));
        }

        $this->secretsDir = rtrim($secretsDir, '/'.\DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.basename($secretsDir).'.';
    }

    public function generateKeys(bool $override = false): bool
    {
        if (file_exists($this->secretsDir.'sodium.decrypt.key')) {
            $this->loadKeys();

            if (!file_exists($this->secretsDir.'sodium.encrypt.key')) {
                $this->export('sodium.encrypt.key', $this->encryptionKey);
            }
        }

        if (!$override && null !== $this->encryptionKey) {
            return false;
        }

        $this->decryptionKey = sodium_crypto_box_keypair();
        $this->encryptionKey = sodium_crypto_box_publickey($this->decryptionKey);

        $this->export('sodium.encrypt.key', $this->encryptionKey);
        $this->export('sodium.decrypt.key', $this->decryptionKey);

        return true;
    }

    public function seal(string $name, string $secret): void
    {
        $this->loadKeys($name);
        $this->export($name.'.sodium', sodium_crypto_box_seal($secret, $this->encryptionKey));
    }

    public function reveal(string $name): ?string
    {
        $this->loadKeys($name);

        if (null === $this->decryptionKey || !file_exists($file = $this->secretsDir.$name.'.sodium')) {
            return null;
        }

        return sodium_crypto_box_seal_open(include $file, $this->decryptionKey);
    }

    public function remove(string $name): bool
    {
        $this->loadKeys($name);

        if (!file_exists($file = $this->secretsDir.$name.'.sodium')) {
            return false;
        }

        return @unlink($file) || !file_exists($file);
    }

    public function list(bool $reveal = false): array
    {
        $this->loadKeys();
        $secrets = [];
        $prefix = basename($this->secretsDir);
        $prefixLen = \strlen($prefix);

        foreach (scandir(\dirname($this->secretsDir)) as $name) {
            if (0 === substr_compare($name, $prefix, 0, $prefixLen) && 0 === substr_compare($name, '.sodium', -\strlen('.sodium'))) {
                $name = substr($name, $prefixLen, -\strlen('.sodium'));
                $secrets[$name] = null !== $this->decryptionKey && $reveal ? $this->reveal($name) : null;
            }
        }

        return $secrets;
    }

    private function loadKeys(string $name = null): void
    {
        if (null !== $name && preg_match('/[^-._A-Za-z0-9\x80-\xFF]/', $name)) {
            throw new \LogicException(sprintf('Secret name is invalid: "%s".', $name));
        }

        if (null !== $this->encryptionKey) {
            return;
        }

        if (file_exists($this->secretsDir.'sodium.decrypt.key')) {
            $this->decryptionKey = include $this->secretsDir.'sodium.decrypt.key';
        }

        if (file_exists($this->secretsDir.'sodium.encrypt.key')) {
            $this->encryptionKey = include $this->secretsDir.'sodium.encrypt.key';
        } elseif (null !== $this->decryptionKey) {
            $this->encryptionKey = sodium_crypto_box_publickey($this->decryptionKey);
        } else {
            throw new \LogicException(sprintf('Encryption key not found in "%s".', $this->secretsDir));
        }
    }

    private function export(string $file, string $data): void
    {
        $data = str_replace('%', '\x', rawurlencode($data));
        $data = "<?php return \"{$data}\";\n";

        if (false === file_put_contents($this->secretsDir.$file, $data, LOCK_EX)) {
            $e = error_get_last();
            throw new \ErrorException($e['message'] ?? 'Failed to write secrets data.', 0, $e['type'] ?? E_USER_WARNING);
        }
    }
}
