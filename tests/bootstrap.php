<?php

/**
 * Test bootstrap.
 *
 * Deliberately does not stand up a Craft application. Everything under test here is
 * either pure string building or file and subprocess work, and keeping Craft out means
 * the suite needs no database, no config and no fixtures, so it runs in milliseconds on
 * every cell of the CI matrix.
 *
 * Yii is required directly because Craft's own bootstrap pulls in far more than these
 * tests need, but `craft\base\Model` still expects the `Yii` class to exist.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
