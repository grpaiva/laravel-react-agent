Answer the following question using the tools provided.

Tools:
@foreach($tools as $tool)
    - {{ $tool['name'] }}: {{ $tool['description'] }}
@endforeach

Use this format:
Question: {{ $question }}
Thought: your reasoning
Action: choose one of [@foreach($toolNames as $name){{ $name }}@if(!$loop->last), @endif @endforeach]
Action Input: input for the action
Observation: result of the action
Thought: I now know the final answer
Final Answer: your final answer

Begin!

Question: {{ $question }}
{{ $scratchpad }}
