<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;

// 🛠️ Initialize the Laravel container
$container = new Container();
Container::setInstance($container);

// 📦 Set up filesystem and event dispatcher
$filesystem = new Filesystem();
$events = new Dispatcher($container);

// 🔍 Configure the view finder to locate Blade templates
$viewPaths = [__DIR__ . '/resources/views'];  // Make sure this path exists
$viewFinder = new FileViewFinder($filesystem, $viewPaths);

// ⚙️ Set up the Blade engine
$engineResolver = new EngineResolver();
$engineResolver->register('php', function () {
    return new PhpEngine();
});

// 🏭 Initialize the view factory
$viewFactory = new Factory($engineResolver, $viewFinder, $events);

// 🔗 Bind the view factory globally for `view()` helper to work
$container->instance('view', $viewFactory);

// ✅ Now your ReActAgent can use `view()`

use Dotenv\Dotenv;
use EchoLabs\Prism\Enums\Provider;
use EchoLabs\Prism\Prism;
use Grpaiva\LaravelReactAgent\Services\ReActAgent;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Ensure required environment variables are set
if (!isset($_ENV['OPENAI_API_KEY'])) {
    echo "Error: OPENAI_API_KEY is not set in the .env file.\n";
    exit(1);
}

try {
    // Initialize Prism with OpenAI
    $prism = new Prism();

    // Configure OpenAI provider
    $provider = Provider::OpenAI;
    $model = 'gpt-4o-mini';

    // Initialize the ReActAgent with OpenAI
    $agent = new ReActAgent(
        prism: $prism,
        provider: $provider,
        model: $model,
        persist: false // Disable database persistence
    );

    // Define the objective for the agent to solve
    $objective = "Explain how photosynthesis works in simple terms.";

    echo "Running ReActAgent with objective: \"$objective\"\n";

    // Run the agent
    $session = $agent->handle($objective);

    // Output the final answer
    echo "\n🔎 Final Answer:\n";
    echo $session->final_answer . "\n";

    // Optional: Output step-by-step reasoning
    echo "\n📝 Reasoning Steps:\n";
    foreach ($session->steps as $step) {
        echo strtoupper($step->type) . ": " . $step->content . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
