<?php

namespace Grpaiva\LaravelReactAgent\Facades;

use Illuminate\Support\Facades\Facade;

class ReActTool extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'react.tool';
    }
}
