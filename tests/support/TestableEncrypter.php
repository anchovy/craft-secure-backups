<?php

declare(strict_types=1);

namespace anchovy\securebackups\tests\support;

use anchovy\securebackups\models\Settings;
use anchovy\securebackups\services\Encrypter;

/**
 * An Encrypter whose settings can be set directly.
 *
 * The only thing in Encrypter that needs a Craft application is the settings lookup,
 * so overriding that one seam is enough to exercise the whole class against the real
 * `openssl` and `gzip` binaries with no application, database or fixtures in play.
 */
final class TestableEncrypter extends Encrypter
{
    public Settings $testSettings;

    /**
     * @inheritdoc
     */
    protected function getSettings(): Settings
    {
        return $this->testSettings;
    }
}
