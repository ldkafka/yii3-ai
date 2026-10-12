# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). In 0.x a minor release may break the API; such a release
has a "Backwards compatibility" section. Patch releases never do.

## [0.1.0] - 2026-10-12

### Added
- One provider-neutral format: `Request`, `Response`, `Message` with typed parts (`Text`, `Image`,
  `Document`, `ToolCall`, `ToolResult`, `Thinking`, `ProviderPart`), `StopReason`, `Usage`, `Cost`,
  `CacheHint`, `ResponseFormat`.
- `Client`: model lookup in a `ModelCatalog`, capability checks before any HTTP call, middleware,
  cost and latency on every response, one PSR-3 log line per call.
- Anthropic Messages API adapter (`AnthropicProvider`, `AnthropicMapper`): `Authorization: Bearer`
  auth, beta and workspace headers, prompt-cache hints (5 minute and 1 hour, order checked), JSON
  Schema output, thinking and
  unknown blocks replayed verbatim, the error model mapped to `ProviderException` with an `ErrorKind`.
- `RetryMiddleware` and `RetryPolicy`: up to 2 retries with backoff and jitter, `retry-after`
  honoured up to 60 seconds; no retry on a 429 without `retry-after` (the spend cap), a read timeout
  or a connection dropped after the request was sent.
- `CurlTransport` (one reused handle, TLS verification on, no redirects) and `Psr18Transport`.
- `Runner`: the bounded tool loop with step and cost limits; a forced tool choice applies to the
  first step only; only `ToolException` text reaches the model.
- Keys and tokens are `#[\SensitiveParameter]` (the transport request too) and redacted in debug dumps.
- `ToolInterface`, `ToolSpec`, `ToolChoice`, `ToolRegistry`.
- Conversations: `Chat`, `InMemoryConversationStore`, `DbConversationStore` (yiisoft/db) and the
  migration `M261012000000CreateAiMessageTable`; context policies `FullReplay` and
  `TokenBudgetWindow` with `HeuristicTokenEstimator`.
- Remote MCP tools: `McpHttpClient` (Streamable HTTP, `initialize` handshake, sessions, JSON and
  event-stream answers, cursor pages), `RemoteMcpTool` and `RemoteMcpToolSource`.
- Yii3 wiring: `config/params.php` and `config/di.php`. Default prices for claude-opus-5-5,
  claude-sonnet-5-5, claude-haiku-5-5 and claude-fable-5-1, checked on 12 Oct 2026.
- Tests: unit tests, 20 Anthropic conformance scenarios and MCP client exchanges on fixtures. The
  fixtures are hand-written from the documented shapes and are re-recorded from the live API before
  release (`AI_LIVE`, `AI_RECORD`); `"synthetic": true` marks those not yet recorded. GitHub Actions
  on PHP 8.3, 8.4 and 8.5, `composer validate --strict` and PHPStan level 9.
