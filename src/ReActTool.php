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

        $this->session->steps()->create([
            'type' => 'action',
            'content' => "Calling tool: {$this->name()}",
            'payload' => ['tool' => $this->name(), 'input' => json_encode($args)],
        ]);

        $result = call_user_func($this->fn, ...$args);

        $this->session->steps()->create([
            'type' => 'observation',
            'content' => $result,
            'payload' => ['tool' => $this->name(), json_encode($result)],
        ]);

        try {
            return $result;
        } catch (ArgumentCountError|InvalidArgumentException|TypeError $e) {
            throw PrismException::invalidParameterInTool($this->name, $e);
        }
    }


}