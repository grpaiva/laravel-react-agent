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

    public function __construct(
        ?Prism  $prism = null,
        mixed   $provider = null,
        ?string $model = null,
        array   $tools = []
    )
    {
        $this->prism = $prism ?? new Prism();
        $this->globalTools = config('react-agent.global_tools', []);
        $this->localTools = $tools;
        $this->provider = $provider ?? config('react-agent.default_provider');
        $this->model = $model ?? config('react-agent.default_model');
    }

    /**
     * Handles the ReAct process with structured output.
     */
    public function handle(string $objective, ?AgentSession $session = null): AgentSession
    {
        $tools = array_merge($this->globalTools, $this->localTools);

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

            // TODO: Handle different providers' support to structured output
            // Gemini JSON mode?

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
            $finishReason = $response->finishReason->name;

            // Save thoughts
            foreach ($structuredResponse['thoughts'] as $thought) {
                $session->steps()->create([
                    'type' => 'assistant',
                    'content' => $thought,
                ]);
            }

            // Save actions and observations
            foreach ($structuredResponse['actions'] as $action) {
                $session->steps()->create([
                    'type' => 'action',
                    'content' => "Calling tool {$action['tool']}",
                    'payload' => [
                        'tool' => $action['tool'],
                        'input' => $action['input'],
                    ],
                ]);

                $session->steps()->create([
                    'type' => 'observation',
                    'content' => $action['observation'],
                ]);
            }

            // Save the final answer
            $finalAnswer = $structuredResponse['final_answer'];
            $session->update(['final_answer' => $finalAnswer]);

            $session->steps()->create([
                'type' => 'final',
                'content' => $finalAnswer,
            ]);

            $done = true;
        }

        return $session;
    }

    /**
     * Defines the ReAct schema for structured output.
     */
    protected function getReActSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'react_response',
            description: 'Structured response following the ReAct reasoning framework',
            properties: [
                new ArraySchema(
                    name: 'thoughts',
                    description: 'Chain of reasoning leading to the answer',
                    items: new StringSchema('thought', 'A reasoning step')
                ),
                new ArraySchema(
                    name: 'actions',
                    description: 'List of actions taken by the agent',
                    items: new ObjectSchema(
                        name: 'action',
                        description: 'An action with its input and observed result',
                        properties: [
                            new StringSchema('tool', 'The tool used'),
                            new StringSchema('input', 'Input provided to the tool'),
                            new StringSchema('observation', 'Result from the tool')
                        ],
                        requiredFields: ['tool', 'input', 'observation']
                    )
                ),
                new StringSchema('final_answer', 'The final answer to the question')
            ],
            requiredFields: ['thoughts', 'actions', 'final_answer']
        );
    }

    /**
     * Builds the ReAct prompt using a Blade view.
     */
    protected function buildReActSystemPrompt(array $tools, string $question, string $scratchpad = ''): string
    {
        $toolList = [];
        foreach ($tools as $tool) {
            $toolList[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];
        }

        $toolNames = array_map(fn($t) => $t['name'], $toolList);

        return view('prompts.react', [
            'tools' => $toolList,
            'toolNames' => $toolNames,
            'question' => $question,
            'scratchpad' => $scratchpad,
        ])->render();
    }

    /**
     * Rebuilds the scratchpad with previous thoughts and actions.
     */
    protected function buildScratchpad(AgentSession $session): string
    {
        $scratchpad = '';

        foreach ($session->steps as $step) {
            switch ($step->type) {
                case 'assistant':
                    $scratchpad .= "\nThought: " . $step->content;
                    break;
                case 'action':
                    $scratchpad .= "\nAction: " . $step->payload['tool'];
                    $scratchpad .= "\nAction Input: " . $step->payload['input'];
                    break;
                case 'observation':
                    $scratchpad .= "\nObservation: " . $step->content;
                    break;
            }
        }

        return $scratchpad;
    }

    /**
     * Identifies if the response contains the final answer.
     */
    protected function isFinalAnswer(string $assistantText): bool
    {
        return str_contains(strtolower($assistantText), 'final answer');
    }
}
