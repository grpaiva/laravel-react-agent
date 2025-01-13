<?php

namespace Grpaiva\LaravelReactAgent\Exceptions;

use EchoLabs\Prism\Exceptions\PrismException;

class ReActAgentException extends PrismException
{
    public static function toolRequiresSession(string $toolName): self
    {
        return new self(
            sprintf('ReAct tool (%s) requires an agent session', $toolName)
        );
    }

    public static function toolMustBeInstanceOfReActTool(string $toolName): self
    {
        return new self(
            sprintf('Tool (%s) must be an instance of ReActTool', $toolName)
        );
    }

    public static function toolsEmpty(): self
    {
        return new self('No tools available for reasoning');
    }
}
