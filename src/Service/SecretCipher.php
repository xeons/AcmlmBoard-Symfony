<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric encryption for secrets that have to be readable again.
 *
 * A TOTP seed is not like a password: the board has to recover the original value to
 * check a code, so it cannot be hashed. Storing it in plain text means a single
 * database leak hands over every member's second factor, which would make the second
 * factor worth nothing. Encrypting it moves that from "read the table" to "read the
 * table and the application secret".
 *
 * AES-256-GCM, with the key derived from APP_SECRET. Rotating APP_SECRET therefore
 * invalidates stored secrets, which is the same relationship the session and CSRF
 * signing already have to it.
 */
final class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private readonly string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $appSecret,
    ) {
        if ('' === $appSecret) {
            throw new \LogicException('APP_SECRET must be set before secrets can be encrypted.');
        }

        // A fixed context string so this key cannot be confused with any other use
        // of APP_SECRET elsewhere in the application.
        $this->key = hash_hkdf('sha256', $appSecret, 32, 'acmlmboard.totp.v1');
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes((int) openssl_cipher_iv_length(self::CIPHER));
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, \OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if (false === $ciphertext) {
            throw new \RuntimeException('Could not encrypt the secret.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    /** Returns null for anything that does not decrypt, rather than throwing. */
    public function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if (false === $raw) {
            return null;
        }

        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
        if (\strlen($raw) <= $ivLength + self::TAG_LENGTH) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($raw, $ivLength + self::TAG_LENGTH),
            self::CIPHER,
            $this->key,
            \OPENSSL_RAW_DATA,
            substr($raw, 0, $ivLength),
            substr($raw, $ivLength, self::TAG_LENGTH),
        );

        return false === $plaintext ? null : $plaintext;
    }
}
