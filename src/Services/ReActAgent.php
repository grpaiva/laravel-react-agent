<?php

namespace Grpaiva\LaravelReactAgent\Services;

use EchoLabs\Prism\Enums\ToolChoice;
use EchoLabs\Prism\Prism;
use EchoLabs\Prism\Exceptions\PrismException;
use Grpaiva\LaravelReactAgent\Exceptions\ReActAgentException;
use Grpaiva\LaravelReactAgent\Models\AgentSession;
use Grpaiva\LaravelReactAgent\Models\AgentStep;
use Grpaiva\LaravelReactAgent\ReActTool;
use Illuminate\Support\Facades\Log;

class ReActAgent
{
    protected string $objective;
    protected Prism $prism;
    protected array $tools = [];
    protected mixed $provider;
    protected ?string $model;
    protected ?AgentSession $session;
    protected bool $debug = false;


    public function __construct(
        string $objective,
        array   $tools,
        ?Prism  $prism = null,
        mixed   $provider = null,
        ?string $model = null,
        ?AgentSession $session = null,
        bool $debug = false
    ) {
        $this->objective = $objective;
        $this->prism = $prism ?? new Prism();
        $this->provider = $provider ?? config('react-agent.default_provider');
        $this->model = $model ?? config('react-agent.default_model');
        $this->session = $session ?? AgentSession::create([
            'objective' => $objective,
        ]);
        $this->debug = $debug;

        $allTools = array_merge($tools, config('react-agent.global_tools', []));

        foreach ($allTools as $tool) {
            $this->log("Adding tool: {$tool->name()}");
            $this->log("Tool is instance of: " . get_class($tool));
            $this->tools[] = $tool->withSession($this->session);
        }

        if (empty($this->tools)) {
            throw ReActAgentException::toolsEmpty();
        }
    }

    public function invoke(): string
    {
        $done = false;

        while (!$done) {
            $systemPrompt = $this->buildReActSystemPrompt();
            $this->log("System Prompt:\n\n $systemPrompt");

            try {
                $response = $this->prism->text()
                    ->using($this->provider, $this->model)
                    ->withSystemPrompt($systemPrompt)
                    ->withTools($this->tools)
                    ->withToolChoice(ToolChoice::Auto)
                    ->withPrompt('')
                    ->generate();
            } catch (PrismException $e) {
                $this->session->steps()->create(['type' => 'error', 'content' => $e->getMessage()]);
                break;
            }

            $responseText = $response->text;
            $this->log("Response Text:\n\n$responseText");

            $this->parseResponse($responseText);

            if ($this->isFinalAnswer($responseText)) {
                $done = true;
            }
        }

        return $this->session;
    }

    public function addTool(ReActTool $tool): void
    {
        $this->localTools[] = $tool;
    }

    public function addTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (!$tool instanceof ReActTool) {
                throw ReActAgentException::toolMustBeInstanceOfReActTool($tool->name());
            }
            $this->addTool($tool);
        }
    }

    protected function buildScratchpad(): string
    {
        $scratchpad = '';
        foreach ($this->session->steps as $step) {
            $scratchpad .= $this->formatStep($step);
        }
        return $scratchpad;
    }

    protected function formatStep(AgentStep $step): string
    {
        $content = $step->content;
        $type = $step->type;

        if ($type === 'assistant') {
            return "Thought: $content\n";
        }

        if ($type === 'action') {
            $tool = $step->payload['tool'];
            $input = $step->payload['input'];
            return "Action: $tool\nAction Input: $input\n";
        }

        if ($type === 'observation') {
            return "Observation: $content\n";
        }

        if ($type === 'final') {
            return "Final Answer: $content\n";
        }

        return '';
    }

    protected function parseResponse(string $text): void
    {
        preg_match_all('/Thought:(.*?)\n/', $text, $thoughts);
        preg_match('/Final Answer:(.*?)$/', $text, $finalAnswer);

        foreach ($thoughts[1] as $thought) {
            $this->session->steps()->create(['type' => 'assistant', 'content' => trim($thought)]);
        }

        if (!empty($finalAnswer[1])) {
            $final = trim($finalAnswer[1]);
            $this->session->update(['final_answer' => $final]);
            $this->session->steps()->create(['type' => 'final', 'content' => $final]);
        }
    }

    protected function buildReActSystemPrompt(): string
    {
        return view('react-agent::prompts.react', [
            'question' => $this->objective,
            'scratchpad' => $this->buildScratchpad(),
        ])->render();
    }

    protected function log($message, mixed $context = null): void
    {
        if ($this->debug) {
            if ($context) {
                Log::debug($message, $context);
                return;
            }

            Log::debug($message);
        }
    }

    protected function isFinalAnswer(string $text): bool
    {
        return str_contains($text, 'Final Answer:');
    }
}
