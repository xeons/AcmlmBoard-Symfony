<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SecretCipher;
use PHPUnit\Framework\TestCase;

/**
 * Encryption for TOTP seeds.
 *
 * A seed cannot be hashed - the board has to recover it to check a code - so the
 * protection against a leaked database is that the ciphertext is useless without
 * APP_SECRET.
 */
final class SecretCipherTest extends TestCase
{
    private SecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new SecretCipher('a-test-application-secret');
    }

    public function testARoundTripReturnsTheOriginal(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';

        self::assertSame($secret, $this->cipher->decrypt($this->cipher->encrypt($secret)));
    }

    public function testTheCiphertextDoesNotContainThePlaintext(): void
    {
        $encrypted = $this->cipher->encrypt('JBSWY3DPEHPK3PXP');

        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $encrypted);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', base64_decode($encrypted, true));
    }

    /** A repeated nonce would leak that two members share a seed. */
    public function testEncryptingTheSameValueTwiceGivesDifferentCiphertext(): void
    {
        self::assertNotSame(
            $this->cipher->encrypt('JBSWY3DPEHPK3PXP'),
            $this->cipher->encrypt('JBSWY3DPEHPK3PXP'),
        );
    }

    /** GCM authenticates: an edited row must not decrypt to something plausible. */
    public function testTamperedCiphertextIsRejected(): void
    {
        $encrypted = $this->cipher->encrypt('JBSWY3DPEHPK3PXP');
        $raw = base64_decode($encrypted, true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 0x01);

        self::assertNull($this->cipher->decrypt(base64_encode($raw)));
    }

    public function testAnotherApplicationSecretCannotDecryptIt(): void
    {
        $encrypted = $this->cipher->encrypt('JBSWY3DPEHPK3PXP');

        self::assertNull((new SecretCipher('a-different-secret'))->decrypt($encrypted));
    }

    public function testRubbishDecryptsToNullRatherThanThrowing(): void
    {
        foreach (['', 'not base64 at all!!', base64_encode('short')] as $rubbish) {
            self::assertNull($this->cipher->decrypt($rubbish));
        }
    }

    public function testAnEmptyApplicationSecretIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        new SecretCipher('');
    }
}
