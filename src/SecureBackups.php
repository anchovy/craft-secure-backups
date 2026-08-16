<?php

namespace anchovy\securebackups;

use anchovy\securebackups\helpers\RestoreCommand;
use anchovy\securebackups\models\Settings;
use anchovy\securebackups\services\Encrypter;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Controller as ConsoleController;
use craft\db\Connection;
use craft\events\BackupEvent;
use craft\events\RestoreEvent;
use craft\helpers\FileHelper;
use yii\base\Event;
use yii\base\Exception;

/**
 * Secure Backups plugin.
 *
 * Encrypts every database backup Craft produces, and decrypts transparently when one
 * is restored. It hooks Craft's own backup pipeline rather than adding a parallel one,
 * so it covers the Control Panel utility, `craft db/backup`, and the automatic backups
 * Craft takes before running migrations. Everything funnels through
 * `craft\db\Connection::backupTo()` and `::restore()`.
 *
 * The two halves use deliberately different mechanisms, because only one works on each
 * side:
 *
 * - **Backup** hooks `EVENT_AFTER_CREATE_BACKUP` and encrypts the dump in place. It
 *   keeps the original filename, which is not cosmetic: `UtilitiesController` captures
 *   the backup path *before* the dump runs and then asserts the file still exists at
 *   exactly that path, so renaming it breaks the Control Panel utility.
 *
 * - **Restore** cannot use `EVENT_BEFORE_RESTORE_BACKUP` to swap the file, because
 *   `Connection::restore()` never reads `$event->file` back: it uses its own local
 *   variable. Instead the handler sets `restoreCommand` while that event is running.
 *   Craft reads that setting a few lines later, so the decryption is spliced into the
 *   command as a pipe and the plaintext is never written to disk.
 *
 * Backups are gzipped before they are encrypted, which is the only order that saves
 * anything: ciphertext does not compress, so Craft's own zip on the Control Panel path
 * barely helps.
 *
 * @method Settings getSettings()
 * @author Anchovy <ben@anchovy.nz>
 * @since 1.0.0
 */
class SecureBackups extends Plugin
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    // Private Properties
    // =========================================================================

    /**
     * @var string|null The transient environment variable holding the key mid-restore.
     */
    private ?string $_keyEnvVar = null;

    /**
     * @var string|null The `restoreCommand` value to put back once a restore finishes.
     */
    private ?string $_previousRestoreCommand = null;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'encrypter' => ['class' => Encrypter::class],
            ],
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function() {
            $this->_registerEventHandlers();
        });
    }

    /**
     * Returns the encrypter service.
     *
     * @return Encrypter
     * @throws \yii\base\InvalidConfigException if the component is misconfigured
     */
    public function getEncrypter(): Encrypter
    {
        /** @var Encrypter $encrypter */
        $encrypter = $this->get('encrypter');

        return $encrypter;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     * @throws \yii\base\InvalidConfigException if the encrypter component is misconfigured
     * @throws \Twig\Error\LoaderError if the template cannot be found
     */
    protected function settingsHtml(): ?string
    {
        $encrypter = $this->getEncrypter();

        return Craft::$app->getView()->renderTemplate('secure-backups/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'keyIsSet' => $encrypter->isConfigured(),
            'opensslVersion' => $encrypter->getOpensslVersion(),
            'gzipVersion' => $encrypter->getGzipVersion(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Attaches the backup and restore handlers.
     */
    private function _registerEventHandlers(): void
    {
        Event::on(
            Connection::class,
            Connection::EVENT_AFTER_CREATE_BACKUP,
            function(BackupEvent $event) {
                $this->_encryptBackup($event->file);
            }
        );

        Event::on(
            Connection::class,
            Connection::EVENT_BEFORE_RESTORE_BACKUP,
            function(RestoreEvent $event) {
                $this->_prepareRestore($event->file);
            }
        );

        Event::on(
            Connection::class,
            Connection::EVENT_AFTER_RESTORE_BACKUP,
            function() {
                $this->_cleanUpAfterRestore();
            }
        );
    }

    /**
     * Encrypts a freshly written backup in place.
     *
     * When encryption is impossible this deletes the plaintext dump Craft has already
     * written before throwing. Leaving it would be the worst of both worlds: the
     * operator is told the backup failed, while an unencrypted copy of the entire
     * database quietly sits on disk.
     *
     * @param string $path
     * @throws Exception if encryption is required but cannot be performed
     */
    private function _encryptBackup(string $path): void
    {
        $settings = $this->getSettings();

        if (!$settings->enabled) {
            return;
        }

        // PostgreSQL's `directory` backup format writes a directory rather than a
        // file, so there is nothing to encrypt in place.
        if (is_dir($path)) {
            $this->_refuse(
                $path,
                'Secure Backups cannot encrypt a directory-format PostgreSQL backup. ' .
                'Set the `backupCommandFormat` config setting to `plain`, or disable this plugin.',
                isDirectory: true
            );

            return;
        }

        $encrypter = $this->getEncrypter();

        if (!$encrypter->isConfigured()) {
            $this->_refuse(
                $path,
                sprintf(
                    'Secure Backups has no encryption key: the `%s` environment variable is not set.',
                    $settings->keyEnvVar
                )
            );

            return;
        }

        try {
            $encrypter->encryptFile($path);
        } catch (Exception $e) {
            $this->_refuse($path, 'Secure Backups could not encrypt the backup: ' . $e->getMessage());

            return;
        }

        Craft::info("Encrypted database backup at $path", __METHOD__);
    }

    /**
     * Deletes the unencrypted dump and either throws or logs, per the settings.
     *
     * @param string $path
     * @param string $message
     * @param bool $isDirectory
     * @throws Exception if the plugin is configured to fail when it cannot encrypt
     */
    private function _refuse(string $path, string $message, bool $isDirectory = false): void
    {
        if (!$this->getSettings()->failIfUnableToEncrypt) {
            Craft::warning($message . ' The backup has been left unencrypted.', __METHOD__);

            return;
        }

        if ($isDirectory) {
            FileHelper::removeDirectory($path);
        } else {
            FileHelper::unlink($path);
        }

        throw new Exception($message . ' The unencrypted backup has been deleted.');
    }

    /**
     * Decides how to handle the backup about to be restored.
     *
     * Runs inside `EVENT_BEFORE_RESTORE_BACKUP`, which fires immediately before
     * `Connection::restore()` reads the `restoreCommand` setting. That ordering is what
     * lets this splice decryption into the command, and it also means the cost of
     * building the command is only paid during an actual restore.
     *
     * @param string $path
     * @throws Exception if the backup cannot or should not be restored
     * @throws \yii\base\InvalidConfigException if the encrypter component is misconfigured
     */
    private function _prepareRestore(string $path): void
    {
        $encrypter = $this->getEncrypter();

        if (!$encrypter->isEncrypted($path)) {
            $this->_handleUnencryptedRestore($path);

            return;
        }

        if (!$encrypter->isConfigured()) {
            throw new Exception(sprintf(
                'This backup is encrypted, but Secure Backups has no key to decrypt it: ' .
                'the `%s` environment variable is not set.',
                $this->getSettings()->keyEnvVar
            ));
        }

        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $baseCommand = $this->_baseRestoreCommand();

        if (!RestoreCommand::isPipeable($baseCommand)) {
            throw new Exception(
                'Secure Backups cannot decrypt into this database\'s restore command, because it ' .
                'reads the backup as a file argument rather than from standard input. This affects ' .
                'PostgreSQL\'s custom, directory and tar formats. Decrypt the backup manually with ' .
                '`openssl enc -d` and restore the result.'
            );
        }

        $this->_keyEnvVar = $encrypter->exportKeyToEnv();
        $this->_previousRestoreCommand = is_string($generalConfig->restoreCommand)
            ? $generalConfig->restoreCommand
            : null;

        $generalConfig->restoreCommand = $this->_decryptingRestoreCommand($baseCommand, $this->_keyEnvVar);

        // A failed restore throws before EVENT_AFTER_RESTORE_BACKUP fires, which would
        // otherwise leave the key sitting in the environment for the rest of the
        // process. Cheap insurance, and idempotent with the after-restore handler.
        register_shutdown_function(function() {
            $this->_cleanUpAfterRestore();
        });
    }

    /**
     * Returns the restore command to wrap: the site's own if it has one, else Craft's default.
     *
     * @return string
     */
    private function _baseRestoreCommand(): string
    {
        $existing = Craft::$app->getConfig()->getGeneral()->restoreCommand;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return Craft::$app->getDb()->getSchema()->getDefaultRestoreCommand();
    }

    /**
     * Rewrites a restore command so the backup is decrypted on the way in.
     *
     * Turns `mysql … < "{file}"` into
     * `openssl enc -d … -in "{file}" | gzip -dcf | mysql …`, so the plaintext SQL
     * exists only in a pipe and is never written to disk.
     *
     * @param string $baseCommand
     * @param string $keyEnvVar
     * @return string
     */
    private function _decryptingRestoreCommand(string $baseCommand, string $keyEnvVar): string
    {
        $gzipIsAvailable = $this->getEncrypter()->gzipIsAvailable();

        if (!$gzipIsAvailable) {
            Craft::warning(
                'gzip is not available on this server, so Secure Backups cannot decompress the backup ' .
                'on the way in. An uncompressed backup will restore normally, but a compressed one will ' .
                'fail. Install gzip, or decrypt and decompress the backup by hand.',
                __METHOD__
            );
        }

        return RestoreCommand::build(
            $baseCommand,
            $this->getSettings()->cipher,
            $keyEnvVar,
            $gzipIsAvailable
        );
    }

    /**
     * Applies the configured policy for restoring a backup that is not encrypted.
     *
     * @param string $path
     * @throws Exception if the restore should not proceed
     */
    private function _handleUnencryptedRestore(string $path): void
    {
        $settings = $this->getSettings();

        if ($settings->unencryptedRestore === Settings::UNENCRYPTED_ALLOW) {
            return;
        }

        $name = basename($path);

        if ($settings->unencryptedRestore === Settings::UNENCRYPTED_DENY) {
            throw new Exception(
                "$name is not encrypted, and Secure Backups is configured to refuse unencrypted restores."
            );
        }

        $controller = Craft::$app->controller;

        // Nobody to ask: an unattended run (cron, CI, a deploy script) must not decide
        // on the operator's behalf to load a dump of unknown provenance.
        if (!$controller instanceof ConsoleController || !$controller->interactive) {
            throw new Exception(
                "$name is not encrypted. Secure Backups asks before restoring an unencrypted " .
                'backup, and this is not an interactive session. Re-run it interactively, or set ' .
                'the plugin\'s `unencryptedRestore` setting to `allow`.'
            );
        }

        $confirmed = $controller->confirm(
            "$name is not encrypted. Restore it anyway?",
            false
        );

        if (!$confirmed) {
            throw new Exception('Restore aborted.');
        }
    }

    /**
     * Clears the transient key and restores the previous `restoreCommand`.
     *
     * @throws \yii\base\InvalidConfigException if the encrypter component is misconfigured
     */
    private function _cleanUpAfterRestore(): void
    {
        if ($this->_keyEnvVar !== null) {
            $this->getEncrypter()->clearKeyFromEnv($this->_keyEnvVar);
            $this->_keyEnvVar = null;

            Craft::$app->getConfig()->getGeneral()->restoreCommand = $this->_previousRestoreCommand;
            $this->_previousRestoreCommand = null;
        }
    }
}
