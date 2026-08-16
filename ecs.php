<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __FILE__,
    ]);

    // CRAFT_CMS_4 is the newest set craftcms/ecs ships. It is the right choice for a
    // Craft 5 plugin too, and is not a sign that this targets Craft 4.
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
