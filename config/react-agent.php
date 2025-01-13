<?php

use EchoLabs\Prism\Enums\Provider;

return [

/*
|--------------------------------------------------------------------------
| Default LLM Provider
|--------------------------------------------------------------------------
| This is the provider name to be used with Prism (e.g. Provider::Anthropic, Provider::OpenAI).
| You can override this at runtime by passing a provider to the handleObjective() method.
*/
'default_provider' => \EchoLabs\Prism\Enums\Provider::OpenAI,

/*
|--------------------------------------------------------------------------
| Default Model
|--------------------------------------------------------------------------
| The default LLM model name to be used if none is specified at runtime.
| Examples: 'claude-2', 'claude-3-sonnet', 'gpt-4', etc.
*/
'default_model' => 'gpt-4o-mini',

'global_tools' => [
//    \EchoLabs\Prism\Facades\Tool::as('weather')
//        ->for('Get current weather info')
//        ->withStringParameter('city', 'The city to fetch weather for')
//        ->using(function ($city) {
//            return "Weather in {$city}: Sunny, 75F";
//        }),
//    // ... other global tools ...
    ],
];
