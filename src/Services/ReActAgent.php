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
    public function handle(string $objective, ?AgentSession $session = null): AgentSession
    {
        $tools = array_merge($this->globalTools, $this->localTools);

        // Create a new session if it doesn't exist
        if (!$session) {
            $session = AgentSession::create(['objective' => $objective]);
            $session->steps()->create([
                'type' => 'user',
                'content' => $objective,
            ]);
        }

        $done = false;

        while (!$done) {
            $scratchpad = $this->buildScratchpad($session);
            $systemPrompt = $this->buildReActSystemPrompt($tools, $session->objective, $scratchpad);

            // Get the structured response
            $response = $this->prism
                ->structured()
                ->using($this->provider, $this->model)
                ->withSchema($this->getReActSchema())
                ->withSystemPrompt($systemPrompt)
                ->withPrompt('')
                ->withTools($tools)
//                ->toolChoice(ToolChoice::Auto)
                ->generate();

            $structuredResponse = $response->structured ?? [];
            $finishReason = $response->finishReason->name;

            // ✅ Save all THOUGHTS
            if (!empty($structuredResponse['thoughts'])) {
                foreach ($structuredResponse['thoughts'] as $thought) {
                    $session->steps()->create([
                        'type'    => 'assistant',
                        'content' => $thought,
                    ]);
                }
            }

            // ✅ Save all ACTIONS and OBSERVATIONS
            if (!empty($structuredResponse['actions'])) {
                foreach ($structuredResponse['actions'] as $action) {
                    // Save action
                    $session->steps()->create([
                        'type'    => 'action',
                        'content' => "Calling tool: {$action['tool']}",
                        'payload' => [
                            'tool'  => $action['tool'],
                            'input' => $action['input'],
                        ],
                    ]);

                    // Save observation
                    $session->steps()->create([
                        'type'    => 'observation',
                        'content' => $action['observation'],
                    ]);
                }
            }

            // ✅ Save FINAL ANSWER if available
            if (!empty($structuredResponse['final_answer'])) {
                $session->update(['final_answer' => $structuredResponse['final_answer']]);
                $session->steps()->create([
                    'type'    => 'final',
                    'content' => $structuredResponse['final_answer'],
                ]);
                $done = true;  // End the loop
            }

            // ✅ Stop if the finish reason indicates completion
            if (
                $finishReason === FinishReason::Stop ||
                $finishReason === FinishReason::Length ||
                $this->isFinalAnswer($response->text)
            ) {
                $done = true;
            }
        }

        return $session;
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

    protected function isFinalAnswer(string $text): bool
    {
        return str_contains($text, 'Finish:');
    }
}
