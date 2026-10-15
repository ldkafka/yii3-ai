# Roadmap

The order and timing below follow real use of the package. They are plans, not promises.

## 0.1.x

- Anthropic conformance fixtures recorded from the live API, replacing the hand-written ones.
- Fixes and small additions from real use.

## 0.2

- Adapters: OpenAI Responses; OpenAI-compatible Chat Completions (Mistral, Groq, OpenRouter, DeepSeek, Ollama,
  vLLM); Gemini.
- Streaming.
- Structured output: JSON Schema answers hydrated to typed objects, with one repair retry.
- Embeddings.

## 0.3

- Provider-native context strategies: Anthropic cache breakpoints placed automatically, OpenAI
  `previous_response_id`, Gemini `cachedContents`.
- Budgets across calls.
- A fallback chain of models.
- Rate limiting.
- PSR-14 events.
- A context policy that summarises older turns.
- A long-term memory store.
- Content logging with redaction.
- Optimistic concurrency on conversation appends.
- Newer MCP protocol revisions.

## 1.0

- An API freeze, after real use.
- The MCP bridge both ways: `ToolInterface` and `McpToolInterface` from ldkafka/yii3-mcp-server.

## Non-goals

- An agent framework.
- A vector database.
- A prompt-template language.
