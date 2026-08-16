<?php

namespace anchovy\securebackups\models;

use anchovy\securebackups\services\Encrypter;
use craft\base\Model;

/**
 * Secure Backups settings.
 *
 * Note that the encryption key itself is deliberately not a setting. Only the *name*
 * of the environment variable holding it is stored, so the key never reaches project
 * config, never lands in version control, and can differ per environment.
 *
 * @author Anchovy <ben@anchovy.nz>
 * @since 1.0.0
 */
class Settings extends Model
{
    // Constants
    // =========================================================================

    /**
     * Refuse the restore and explain why.
     */
    public const UNENCRYPTED_DENY = 'deny';

    /**
     * Ask before continuing, and refuse when there is nobody to ask.
     */
    public const UNENCRYPTED_PROMPT = 'prompt';

    /**
     * Restore unencrypted dumps without comment.
     */
    public const UNENCRYPTED_ALLOW = 'allow';

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether backups should be encrypted.
     *
     * Turning this off stops new backups being encrypted but does not stop existing
     * encrypted backups being decrypted on restore, so it is safe to toggle.
     */
    public bool $enabled = true;

    /**
     * @var string The name of the environment variable holding the encryption key.
     */
    public string $keyEnvVar = 'SECURE_BACKUPS_KEY';

    /**
     * @var bool Whether backups should be gzipped before they are encrypted.
     *
     * Order matters, and only this order helps. Ciphertext is indistinguishable from
     * random and does not compress, so compressing afterwards saves nothing: that is
     * why Craft's own zip on the Control Panel path buys so little. Compressing first
     * typically makes a SQL dump several times smaller before the cipher ever sees it.
     *
     * Safe to toggle in either direction. Restores pipe through `gzip -dcf` whatever
     * this is set to, and that passes unrecognised input straight through, so existing
     * backups keep restoring either way.
     */
    public bool $compress = true;

    /**
     * @var string The openssl cipher to use.
     */
    public string $cipher = Encrypter::DEFAULT_CIPHER;

    /**
     * @var int How long to wait for openssl, in seconds, before killing it.
     */
    public int $timeout = Encrypter::DEFAULT_TIMEOUT;

    /**
     * @var string What to do when restoring a backup that is not encrypted.
     *
     * One of the UNENCRYPTED_* constants. `prompt` asks on an interactive console and
     * refuses everywhere else, which keeps unattended runs (cron, CI, deploy scripts)
     * from silently loading a dump nobody vouched for.
     */
    public string $unencryptedRestore = self::UNENCRYPTED_PROMPT;

    /**
     * @var bool Whether a backup should fail when encryption is impossible.
     *
     * On by default, and deliberately so: the alternative is writing a plaintext dump
     * to disk while the operator believes backups are encrypted. When this refuses, it
     * also deletes the plaintext dump Craft has already written, so a failed backup
     * never leaves an unencrypted copy behind.
     */
    public bool $failIfUnableToEncrypt = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['keyEnvVar', 'cipher', 'unencryptedRestore'], 'required'];
        $rules[] = [['enabled', 'compress', 'failIfUnableToEncrypt'], 'boolean'];
        $rules[] = [['timeout'], 'integer', 'min' => 1];
        $rules[] = [
            ['unencryptedRestore'],
            'in',
            'range' => [
                self::UNENCRYPTED_DENY,
                self::UNENCRYPTED_PROMPT,
                self::UNENCRYPTED_ALLOW,
            ],
        ];
        $rules[] = [['keyEnvVar'], 'match', 'pattern' => '/^[A-Z_][A-Z0-9_]*$/i'];
        $rules[] = [['cipher'], 'match', 'pattern' => '/^[a-z0-9-]+$/i'];

        return $rules;
    }
}
