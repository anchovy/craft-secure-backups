<?php

declare(strict_types=1);

namespace anchovy\securebackups\tests\unit;

use anchovy\securebackups\models\Settings;
use anchovy\securebackups\services\Encrypter;
use anchovy\securebackups\tests\support\TestableEncrypter;
use PHPUnit\Framework\TestCase;
use yii\base\Exception;

/**
 * Covers the on-disk format: compress, encrypt, and get the exact bytes back.
 *
 * These run against the real `openssl` and `gzip` binaries rather than doubles,
 * because the thing worth proving is that a backup written on this machine can be
 * read back on any machine. Mocking the subprocesses would prove nothing about that.
 */
final class EncrypterTest extends TestCase
{
    private const KEY_VAR = 'SB_TEST_KEY';
    private const KEY = 'a long random passphrase for testing';

    private string $dir;
    private TestableEncrypter $encrypter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sb-tests-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        putenv(self::KEY_VAR . '=' . self::KEY);

        $settings = new Settings();
        $settings->keyEnvVar = self::KEY_VAR;

        $this->encrypter = new TestableEncrypter();
        $this->encrypter->testSettings = $settings;
    }

    protected function tearDown(): void
    {
        putenv(self::KEY_VAR);

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    // Recoverability
    // =========================================================================

    public function testEncryptedBackupIsRecoverableWithNothingButOpensslAndGzip(): void
    {
        $original = $this->writeDump('dump.sql');
        $expected = file_get_contents($original);

        $this->encrypter->encryptFile($original);

        self::assertSame(0, $this->recoverUsingDocumentedCommand($original, $this->dir . '/out.sql'));
        self::assertSame($expected, file_get_contents($this->dir . '/out.sql'));
    }

    public function testRecoveryDoesNotDependOnTheCompressSetting(): void
    {
        // One command has to recover a compressed backup, an uncompressed one, and
        // anything taken before compression existed. That is what makes the setting
        // safe to change at any time, and what stops an old backup becoming
        // unreadable because a setting moved underneath it.
        $this->encrypter->testSettings->compress = true;
        $compressed = $this->writeDump('compressed.sql');
        $expectedCompressed = file_get_contents($compressed);
        $this->encrypter->encryptFile($compressed);

        $this->encrypter->testSettings->compress = false;
        $plain = $this->writeDump('plain.sql');
        $expectedPlain = file_get_contents($plain);
        $this->encrypter->encryptFile($plain);

        self::assertSame(0, $this->recoverUsingDocumentedCommand($compressed, $this->dir . '/a.sql'));
        self::assertSame(0, $this->recoverUsingDocumentedCommand($plain, $this->dir . '/b.sql'));

        self::assertSame($expectedCompressed, file_get_contents($this->dir . '/a.sql'));
        self::assertSame($expectedPlain, file_get_contents($this->dir . '/b.sql'));
    }

    // Format
    // =========================================================================

    public function testEncryptedBackupKeepsItsOriginalPath(): void
    {
        // Craft's UtilitiesController captures the backup path before the dump runs
        // and asserts the file is still there afterwards. Renaming breaks the CP.
        $original = $this->writeDump('dump.sql');

        $this->encrypter->encryptFile($original);

        self::assertFileExists($original);
        self::assertStringEndsWith('.sql', $original);
    }

    public function testEncryptedBackupCarriesTheSaltedHeader(): void
    {
        $original = $this->writeDump('dump.sql');

        $this->encrypter->encryptFile($original);

        self::assertSame(Encrypter::MAGIC, file_get_contents($original, false, null, 0, 8));
        self::assertTrue($this->encrypter->isEncrypted($original));
    }

    public function testCompressionSubstantiallyShrinksTheBackup(): void
    {
        $compressed = $this->writeDump('a.sql');
        $plain = $this->writeDump('b.sql');

        $this->encrypter->encryptFile($compressed);

        $this->encrypter->testSettings->compress = false;
        $this->encrypter->encryptFile($plain);

        // Real dumps compress by roughly an order of magnitude. Asserting only "less
        // than half" keeps this from turning into a brittle ratio test.
        self::assertLessThan(filesize($plain) / 2, filesize($compressed));
    }

    public function testCompressionHappensInsideTheEncryptionNotOutside(): void
    {
        // The order is the entire point: ciphertext does not compress, so gzipping
        // afterwards would save nothing. Decrypting on its own must therefore yield
        // gzip rather than SQL. Asserting this from outside the plugin is the only
        // way to catch the layers being swapped.
        $original = $this->writeDump('dump.sql');
        $this->encrypter->encryptFile($original);

        $inner = $this->dir . '/inner';
        $command = sprintf(
            'openssl enc -d -%s -pbkdf2 -pass env:%s -in %s -out %s',
            Encrypter::DEFAULT_CIPHER,
            self::KEY_VAR,
            escapeshellarg($original),
            escapeshellarg($inner)
        );
        exec($command . ' 2>/dev/null', $output, $exitCode);

        self::assertSame(0, $exitCode, 'openssl should decrypt the backup.');
        self::assertTrue(
            $this->encrypter->isCompressed($inner),
            'Decrypting alone should reveal gzip, not SQL.'
        );
    }

    public function testRecognisesCiphertextAndPlainSql(): void
    {
        $plain = $this->writeDump('dump.sql');
        self::assertFalse($this->encrypter->isEncrypted($plain));

        $this->encrypter->encryptFile($plain);
        self::assertTrue($this->encrypter->isEncrypted($plain));
    }

    public function testIsEncryptedIsFalseForAMissingFile(): void
    {
        self::assertFalse($this->encrypter->isEncrypted($this->dir . '/nope.sql'));
    }

    public function testRecognisesGzipByItsMagicBytes(): void
    {
        $plain = $this->writeDump('dump.sql');
        self::assertFalse($this->encrypter->isCompressed($plain));

        file_put_contents($this->dir . '/z.gz', gzencode('hello'));
        self::assertTrue($this->encrypter->isCompressed($this->dir . '/z.gz'));
    }

    // Safety
    // =========================================================================

    public function testEncryptingTwiceDoesNotDoubleEncrypt(): void
    {
        $original = $this->writeDump('dump.sql');
        $expected = file_get_contents($original);

        $this->encrypter->encryptFile($original);
        $afterFirst = file_get_contents($original);
        $this->encrypter->encryptFile($original);

        self::assertSame($afterFirst, file_get_contents($original));

        self::assertSame(0, $this->recoverUsingDocumentedCommand($original, $this->dir . '/out.sql'));
        self::assertSame($expected, file_get_contents($this->dir . '/out.sql'));
    }

    public function testTheWrongKeyCannotDecrypt(): void
    {
        $original = $this->writeDump('dump.sql');
        $this->encrypter->encryptFile($original);

        putenv(self::KEY_VAR . '=the wrong passphrase entirely');

        self::assertNotSame(
            0,
            $this->recoverUsingDocumentedCommand($original, $this->dir . '/out.sql'),
            'The wrong passphrase must fail loudly rather than emit garbage.'
        );
    }

    public function testEncryptingWithoutAKeyThrows(): void
    {
        putenv(self::KEY_VAR);
        $original = $this->writeDump('dump.sql');

        $this->expectException(Exception::class);

        try {
            $this->encrypter->encryptFile($original);
        } finally {
            // The dump must be left untouched; deleting it is the plugin's job, and
            // only when it is configured to fail hard.
            self::assertFileExists($original);
        }
    }

    public function testNoTemporaryFilesSurviveASuccessfulBackup(): void
    {
        $original = $this->writeDump('dump.sql');

        $this->encrypter->encryptFile($original);

        self::assertFileDoesNotExist($original . '.sb-tmp');
        self::assertFileDoesNotExist($original . '.sb-gz');
    }

    public function testNoTemporaryFilesSurviveAFailedBackup(): void
    {
        $original = $this->writeDump('dump.sql');
        $this->encrypter->testSettings->cipher = 'not-a-real-cipher';

        try {
            $this->encrypter->encryptFile($original);
        } catch (Exception) {
            // Expected: openssl rejects the cipher.
        }

        // A leftover .sb-gz would be a complete unencrypted copy of the database.
        self::assertFileDoesNotExist($original . '.sb-tmp');
        self::assertFileDoesNotExist($original . '.sb-gz');
    }

    public function testKeyIsReadFromTheConfiguredEnvironmentVariable(): void
    {
        self::assertTrue($this->encrypter->isConfigured());
        self::assertSame(self::KEY, $this->encrypter->getKey());

        putenv(self::KEY_VAR);

        self::assertFalse($this->encrypter->isConfigured());
        self::assertNull($this->encrypter->getKey());
    }

    // Binary detection
    // =========================================================================

    public function testDetectsGzipRegardlessOfWhichStreamItsVersionUses(): void
    {
        // Regression test. GNU gzip prints --version to stdout, Apple's prints it to
        // stderr. Reading only stdout made gzip look absent on macOS, which dropped
        // the decompression stage from the restore pipe and would have fed gzip bytes
        // straight to the database client.
        self::assertTrue($this->encrypter->gzipIsAvailable());
        self::assertNotEmpty($this->encrypter->getGzipVersion());
    }

    public function testDetectsOpenssl(): void
    {
        self::assertTrue($this->encrypter->opensslIsAvailable());
        self::assertNotEmpty($this->encrypter->getOpensslVersion());
    }

    // Helpers
    // =========================================================================

    /**
     * Recovers a backup using only the shell pipeline the README documents, with no
     * plugin code involved.
     *
     * This is the guarantee that actually matters: a backup must be readable on a
     * machine with no Craft, no PHP and no copy of this plugin. Decrypting through the
     * Encrypter instead would only prove the plugin agrees with itself, and a wrong or
     * missing openssl flag would cancel out on both sides and still pass.
     *
     * Two things differ from the documented command, neither affecting what is being
     * tested: the passphrase arrives through the environment rather than a prompt, and
     * `pipefail` is set so a failure in openssl reaches the caller instead of being
     * masked by gzip's exit status.
     *
     * @return int The pipeline's exit code
     */
    private function recoverUsingDocumentedCommand(string $path, string $destination): int
    {
        $pipeline = sprintf(
            'set -o pipefail; openssl enc -d -%s -pbkdf2 -pass env:%s -in %s | gzip -dcf > %s',
            Encrypter::DEFAULT_CIPHER,
            self::KEY_VAR,
            escapeshellarg($path),
            escapeshellarg($destination)
        );

        exec('bash -c ' . escapeshellarg($pipeline) . ' 2>/dev/null', $output, $exitCode);

        return $exitCode;
    }

    /**
     * Writes a file that behaves like a real dump: highly repetitive, so it compresses.
     */
    private function writeDump(string $name): string
    {
        $path = $this->dir . '/' . $name;
        $sql = "-- Secure Backups test dump\n";

        for ($i = 1; $i <= 2000; $i++) {
            $sql .= "INSERT INTO `entries` VALUES ($i,'title-$i','2026-08-16 10:00:00',NULL,1,'live');\n";
        }

        file_put_contents($path, $sql);

        return $path;
    }
}
