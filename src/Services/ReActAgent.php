<?php

namespace Grpaiva\LaravelReactAgent\Services;

use EchoLabs\Prism\Prism;
use EchoLabs\Prism\Exceptions\PrismException;
use Grpaiva\LaravelReactAgent\Models\AgentSession;
use Illuminate\Support\Facades\Log;

class ReActAgent
{
    protected Prism $prism;
    protected mixed $provider;
    protected ?string $model;
    protected array $localTools = [];
    protected array $globalTools = [];
    protected bool $persist;
    protected array $inMemorySession = [];

    public function __construct(
        ?Prism  $prism = null,
        mixed   $provider = null,
        ?string $model = null,
        array   $tools = [],
        bool    $persist = true
    ) {
        $this->prism = $prism ?? new Prism();
        $this->globalTools = config('react-agent.global_tools', []);
        $this->localTools = $tools;
        $this->provider = $provider ?? config('react-agent.default_provider');
        $this->model = $model ?? config('react-agent.default_model');
        $this->persist = $persist;
    }

    public function handle(string $objective, ?AgentSession $session = null): AgentSession
    {
        $tools = array_unique(array_merge($this->globalTools, $this->localTools), SORT_REGULAR);

        if (empty($tools)) {
            throw new PrismException('No tools available for reasoning');
        }

        if (!$session) {
            $session = AgentSession::create(['objective' => $objective]);
            $session->steps()->create(['type' => 'user', 'content' => $objective]);
        }

        $done = false;

        while (!$done) {
            $scratchpad = $this->buildScratchpad($session);
            $systemPrompt = $this->buildReActSystemPrompt($tools, $session->objective, $scratchpad);

            Log::debug("System Prompt:\n\n $systemPrompt");

            try {
                $response = $this->prism->text()
                    ->using($this->provider, $this->model)
                    ->withSystemPrompt($systemPrompt)
                    ->withPrompt('')
                    ->generate();
            } catch (PrismException $e) {
                $session->steps()->create(['type' => 'error', 'content' => $e->getMessage()]);
                break;
            }

            $responseText = $response->text;
            Log::debug("Response Text:\n\n$responseText");

            $this->parseResponse($responseText, $session);

            if ($this->isFinalAnswer($responseText)) {
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

    protected function parseResponse(string $text, AgentSession $session): void
    {
        preg_match_all('/Thought:(.*?)\n/', $text, $thoughts);
        preg_match_all('/Action:\s*(.*?)\nAction Input:\s*(.*?)\n/', $text, $actions);
        preg_match_all('/Observation:(.*?)\n/', $text, $observations);
        preg_match('/Final Answer:(.*?)$/', $text, $finalAnswer);

        foreach ($thoughts[1] as $thought) {
            $session->steps()->create(['type' => 'assistant', 'content' => trim($thought)]);
        }

        foreach ($actions[1] as $index => $action) {
            $session->steps()->create([
                'type' => 'action',
                'content' => "Calling tool: {$action}",
                'payload' => ['tool' => $action, 'input' => trim($actions[2][$index])],
            ]);
        }

        foreach ($observations[1] as $observation) {
            $session->steps()->create(['type' => 'observation', 'content' => trim($observation)]);
        }

        if (!empty($finalAnswer[1])) {
            $final = trim($finalAnswer[1]);
            $session->update(['final_answer' => $final]);
            $session->steps()->create(['type' => 'final', 'content' => $final]);
        }
    }

    protected function buildReActSystemPrompt(array $tools, string $question, string $scratchpad): string
    {
        $toolList = array_map(fn($tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
        ], $tools);

        $toolNames = array_map(fn($tool) => $tool['name'], $toolList);

        return view('react-agent::prompts.react', [
            'tools' => $toolList,
            'toolNames' => $toolNames,
            'question' => $question,
            'scratchpad' => $scratchpad,
        ])->render();
    }

    protected function isFinalAnswer(string $text): bool
    {
        return str_contains($text, 'Final Answer:');
    }
}
