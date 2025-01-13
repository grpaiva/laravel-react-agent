<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_session_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['thought', 'action', 'observation', 'assistant', 'user', 'final', 'error']);
            // 'assistant' = the user sees the response
            // 'user' = the user’s input
            // 'thought' = internal chain-of-thought
            // 'action' = calling a tool
            // 'observation' = result from a tool
            // 'final' = final completion from the assistant
            // 'error' = an error message
            $table->text('content')->nullable();          // The text of the step
            $table->json('payload')->nullable(); // Additional metadata (tool name, arguments, LLM usage, etc.)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_steps');
    }
};
