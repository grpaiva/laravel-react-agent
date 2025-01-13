<?php

use EchoLabs\Prism\Prism;
use EchoLabs\Prism\Enums\FinishReason;
use Grpaiva\LaravelReactAgent\Models\AgentSession;
use Grpaiva\LaravelReactAgent\Models\AgentStep;
use Grpaiva\LaravelReactAgent\Services\ReActAgent;

/*
|--------------------------------------------------------------------------
| Pest uses the "uses()" function to specify the base test class.
|--------------------------------------------------------------------------
*/
pest()->extend(Tests\TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| 1) Creates a brand-new session if none is passed in
|--------------------------------------------------------------------------
*/
it('creates a new agent session if none is passed', function () {
    // We start with zero sessions in DB
    expect(AgentSession::count())->toBe(0);

});

/*
|--------------------------------------------------------------------------
| 2) Appends new steps if an existing session is passed
|--------------------------------------------------------------------------
*/
it('uses the existing session if provided', function () {

});

/*
|--------------------------------------------------------------------------
| 3) Handles tool calls, creating action & observation steps
|--------------------------------------------------------------------------
*/
it('stores tool calls as action and observation steps', function () {

});

it('stores a final answer immediately when the LLM returns Stop', function () {
    // We start with 0 sessions
    expect(AgentSession::count())->toBe(0);

    // 1) Create a mock of Prism
    $mockPrism = Mockery::mock(Prism::class);

    // 2) Mock the fluent chain
    //    .text()
    //    ->using(...)
    //    ->withMessages(...)
    //    ->withTools(...)
    //    ->toolChoice(...)
    //    ->generate()
    $mockPrism
        ->shouldReceive('text->using->withMessages->withTools->toolChoice->generate')
        ->once()
        ->andReturn((object) [
            'text'         => 'Finish: This is a final answer.',
            'finishReason' => FinishReason::Stop,
            'steps'        => [], // no sub-steps or tool calls
        ]);

    // 3) Instantiate ReActAgent with our mock Prism
    $agent = new ReActAgent(prism: $mockPrism);

    // 4) Act: pass an objective, we expect only one iteration
    $session = $agent->handle('Explain quantum mechanics.');

    // 5) Assert
    //    a) One session total
    //    b) Two steps: user + final
    expect(AgentSession::count())->toBe(1);
//    expect($session->steps)->toHaveCount(3);

    dd($session->steps);

    // Step 0 => user
    expect($session->steps[0]->type)->toBe('user')
        ->and($session->steps[0]->content)->toBe('Explain quantum mechanics.');

    // Step 1 => assistant
    expect($session->steps[1]->type)->toBe('assistant')
        ->and($session->steps[1]->content)->toContain('This is a final answer.');

    // Step 2 => final
    expect($session->steps[2]->type)->toBe('final')
        ->and($session->steps[2]->content)->toContain('This is a final answer.');

    // Also check final_answer on the session
    expect($session->final_answer)->toContain('This is a final answer.');
});
