<?php

namespace Grpaiva\LaravelReactAgent\Services;

use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\Prism;
use EchoLabs\Prism\Enums\ToolChoice;
use EchoLabs\Prism\Schema\ObjectSchema;
use EchoLabs\Prism\Schema\ArraySchema;
use EchoLabs\Prism\Schema\StringSchema;
use EchoLabs\Prism\Exceptions\PrismException;
use Grpaiva\LaravelReactAgent\Models\AgentSession;

class ReActAgent
{
    protected Prism $prism;
    protected mixed $provider;
    protected ?string $model;
    protected array $localTools = [];
    protected array $globalTools = [];
    protected bool $persist; // 👈 New flag for persistence
    protected array $inMemorySession = []; // In-memory session storage

    public function __construct(
        ?Prism  $prism = null,
        mixed   $provider = null,
        ?string $model = null,
        array   $tools = [],
        bool    $persist = true // 👈 Default to true (database persistence)
    ) {
        $this->prism = $prism ?? new Prism();
//        $this->globalTools = config('react-agent.global_tools', []);
        $this->globalTools = [];
        $this->localTools = $tools;
        $this->provider = $provider ?? config('react-agent.default_provider');
        $this->model = $model ?? config('react-agent.default_model');
        $this->persist = $persist;
    }

    /**
     * Handles the ReAct process with structured output.
     */
    public function handle(string $objective, ?AgentSession $session = null): AgentSession|array
    {
        $tools = array_merge($this->globalTools, $this->localTools);

        if ($this->persist) {
            if (!$session) {
                $session = AgentSession::create(['objective' => $objective]);
                $session->steps()->create([
                    'type' => 'user',
                    'content' => $objective,
                ]);
            }
        } else {
            // Initialize in-memory session
            $this->inMemorySession = [
                'objective' => $objective,
                'steps' => [['type' => 'user', 'content' => $objective]],
                'final_answer' => null,
            ];
        }

        $done = false;

        while (!$done) {
            $scratchpad = $this->persist
                ? $this->buildScratchpad($session)
                : $this->buildInMemoryScratchpad();

            $systemPrompt = $this->buildReActSystemPrompt($tools, $objective, $scratchpad);

            $response = $this->prism
                ->structured()
                ->using($this->provider, $this->model)
                ->withSchema($this->getReActSchema())
                ->withSystemPrompt($systemPrompt)
                ->withPrompt('')
                ->withTools($tools)
                ->toolChoice(ToolChoice::Auto)
                ->generate();

            $structuredResponse = $response->structured;

            foreach ($structuredResponse['thoughts'] as $thought) {
                $this->addStep('assistant', $thought, $session);
            }

            foreach ($structuredResponse['actions'] as $action) {
                $this->addStep('action', "Calling tool {$action['tool']}", $session, [
                    'tool' => $action['tool'],
                    'input' => $action['input'],
                ]);
                $this->addStep('observation', $action['observation'], $session);
            }

            $finalAnswer = $structuredResponse['final_answer'];

            if ($this->persist) {
                $session->update(['final_answer' => $finalAnswer]);
                $this->addStep('final', $finalAnswer, $session);
            } else {
                $this->inMemorySession['final_answer'] = $finalAnswer;
                $this->addStep('final', $finalAnswer);
            }

            $done = true;
        }

        return $this->persist ? $session : $this->inMemorySession;
    }

    /**
     * Adds a step to either the DB session or in-memory session.
     */
    protected function addStep(string $type, string $content, ?AgentSession $session = null, array $payload = []): void
    {
        if ($this->persist && $session) {
            $session->steps()->create([
                'type' => $type,
                'content' => $content,
                'payload' => $payload,
            ]);
        } else {
            $this->inMemorySession['steps'][] = [
                'type' => $type,
                'content' => $content,
                'payload' => $payload,
            ];
        }
    }

    /**
     * Scratchpad for persisted sessions.
     */
    protected function buildScratchpad(AgentSession $session): string
    {
        $scratchpad = '';
        foreach ($session->steps as $step) {
            $scratchpad .= $this->formatStep($step['type'], $step['content'], $step['payload'] ?? []);
        }
        return $scratchpad;
    }

    /**
     * Scratchpad for in-memory sessions.
     */
    protected function buildInMemoryScratchpad(): string
    {
        $scratchpad = '';
        foreach ($this->inMemorySession['steps'] as $step) {
            $scratchpad .= $this->formatStep($step['type'], $step['content'], $step['payload'] ?? []);
        }
        return $scratchpad;
    }

    protected function formatStep(string $type, string $content, array $payload = []): string
    {
        return match ($type) {
            'assistant' => "\nThought: $content",
            'action' => "\nAction: {$payload['tool']}\nAction Input: {$payload['input']}",
            'observation' => "\nObservation: $content",
            default => '',
        };
    }

    /**
     * Defines the ReAct schema for structured output.
     */
    protected function getReActSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'react_response',
            description: 'Structured response for ReAct reasoning',
            properties: [
                new ArraySchema('thoughts', 'Reasoning steps', new StringSchema('thought', 'Step')),
                new ArraySchema('actions', 'Actions taken', new ObjectSchema(
                    'action', 'Action details', [
                    new StringSchema('tool', 'Tool used'),
                    new StringSchema('input', 'Input given'),
                    new StringSchema('observation', 'Result'),
                ], ['tool', 'input', 'observation']
                )),
                new StringSchema('final_answer', 'Final answer')
            ],
            requiredFields: ['thoughts', 'actions', 'final_answer']
        );
    }

    protected function buildReActSystemPrompt(array $tools, string $question, string $scratchpad): string
    {
        $toolList = array_map(fn($tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
        ], $tools);

        // ✅ Extract tool names for the Action format
        $toolNames = array_map(fn($tool) => $tool['name'], $toolList);

        // ✅ Pass $toolNames to the view
        return view('react-agent::prompts.react', [
            'tools' => $toolList,
            'toolNames' => $toolNames,
            'question' => $question,
            'scratchpad' => $scratchpad,
        ])->render();
    }

}
