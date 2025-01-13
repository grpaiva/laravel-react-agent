<?php

namespace Grpaiva\LaravelReactAgent\Facades;

use Grpaiva\LaravelReactAgent\ReActTool as BaseTool;

class ReActTool
{
    public static function __callStatic(string $method, array $arguments): BaseTool
    {
        $instance = new BaseTool;

        if (method_exists($instance, $method)) {
            return $instance->$method(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist.");
    }
}
