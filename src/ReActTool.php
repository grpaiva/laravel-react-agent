<?php

namespace Grpaiva\LaravelReactAgent;

use ArgumentCountError;
use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Tool;
use Grpaiva\LaravelReactAgent\Exceptions\ReActAgentException;
use Grpaiva\LaravelReactAgent\Models\AgentSession;
use InvalidArgumentException;
use TypeError;

class ReActTool extends Tool
{
    protected ?AgentSession $session;

    /**
     * Handle static method calls and forward them to a new instance.
     *
     * This allows calls like ReActTool::as('Name') to work.
     */
    public static function __callStatic($method, $arguments)
    {
        $instance = new static();

        if (method_exists($instance, $method)) {
            return $instance->$method(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist in " . static::class);
    }

    public function withSession(AgentSession $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function session(): string
    {
        return $this->session;
    }

    public function handle(...$args): string
    {
        if (!$this->session) {
            throw ReActAgentException::toolRequiresSession($this->name());
        }

        // Log the action step in the session
        $this->session->steps()->create([
            'type' => 'action',
            'content' => "Calling tool: {$this->name()}",
            'payload' => ['tool' => $this->name(), 'input' => json_encode($args)],
        ]);

        try {
            $result = call_user_func($this->fn, ...$args);

            // Log the observation step in the session
            $this->session->steps()->create([
                'type' => 'observation',
                'content' => $result,
                'payload' => ['tool' => $this->name(), 'result' => json_encode($result)],
            ]);

            return $result;
        } catch (ArgumentCountError | InvalidArgumentException | TypeError $e) {
            throw PrismException::invalidParameterInTool($this->name, $e);
        }
    }
}
