<?php

namespace Mage\FPC;

use Composer\Script\Event;

class Composer
{
    public static function postDump(Event $event)
    {
        $installedPackage = $event->getOperation()->getPackage();
        var_dump($installedPackage);
        // do stuff
    }
}
