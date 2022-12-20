<?php

namespace Endropie\LumenAccurateClient;

use Endropie\LumenAccurateClient\Tools\Manager;
use Illuminate\Support\Facades\Facade as BaseFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class Facade extends BaseFacade {

    protected static function getFacadeAccesor() {
        return 'accurate';
    }

    static function manager ()
    {
        return new Manager();
    }
}
