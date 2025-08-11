Streaming (RAG Responses API)

Overview
- Goal: Stream OpenAI Responses API output into the UI as tokens arrive (SSE).
- Endpoints:
  - POST `/rag/response/chat/send-stream` → Server-Sent Events (SSE)
  - GET `/rag/response/ping` → Ping in ms (cached 5s)
- Log file: `var/log/streaming.log` (Monolog channel: `streaming`)

Key Files
- Controller: `src/Controller/rag/response/ChatController.php`
  - `sendStream()`: Bridges OpenAI SSE → browser SSE.
  - Emits SSE events: `meta`, `delta`, `error`, `done`.
  - Adds anti-buffering headers, logs lifecycle, and usage stats.
- Service: `src/Service/rag/response/OpenAIResponseService.php`
  - `createResponseStream(...)`: Calls OpenAI `/responses` with `stream: true`.
  - `drainSseBuffer(...)`: Robust SSE parsing (CRLF→LF, multiple data: lines, skips empty/null).
  - `buildPayload(...)`: Common payload builder for streaming and non-stream.
  - `ping(...)`: Lightweight RTT to `/models`.
- Frontend: `templates/rag/response/chat/index.html.twig`
  - Uses `fetch` ReadableStream to parse SSE, display deltas with a blinking cursor, smart autoscroll, and stats.

Monolog (streaming channel)
- Config: `config/packages/monolog.yaml`
  - Adds channel `streaming` writing to `%kernel.logs_dir%/streaming.log` for dev/test/prod.
- Services wiring: `config/services.yaml`
  - Injects `@monolog.logger.streaming` into:
    - `App\Service\rag\response\OpenAIResponseService`
    - `App\Controller\rag\response\ChatController`

SSE Event Mapping
- OpenAI → App → Browser
  - `response.output_text.delta` → `delta` with `{ text }`
  - `response.output_text.done` → ignored (final stats on `response.completed`)
  - `response.refusal.delta` → `delta` with `{ text }` (refusal rationale)
  - `response.tool_call.delta` → `tool_delta` (optional UI)
  - `response.tool_call.done` → `tool_done` (optional UI)
  - `response.error` → `error` with `{ message, insufficient_quota }`
  - `response.completed` → `done` with `{ stats }`
  - App also sends an initial `meta` with `{ ping_ms }`

Headers and Buffering
- Request to OpenAI:
  - `Accept: text/event-stream`, `stream: true`, `buffer: false`, `timeout: 0`
  - Client stream wait: `HttpClientInterface::stream(..., 300.0)`
- Response to browser:
  - `Content-Type: text/event-stream`
  - `Cache-Control: no-cache, no-transform`
  - `Connection: keep-alive`
  - `X-Accel-Buffering: no`, `X-Proxy-Buffering: off`, `X-Fastcgi-Buffering: off`
- Web server/proxy (outside app):
  - Disable proxy buffering: `proxy_buffering off; fastcgi_buffering off;`
  - Increase `proxy_read_timeout` (e.g., 300s or higher)

Model Selection
- Default model: `RAG_RESPONSES_MODEL` env.
- Fallback sequence: Request `model` → `RAG_RESPONSES_MODEL` → `'gpt-5-mini'`.

Stats and Ping
- `done.stats` contains: `tokens_query`, `tokens_answer`, `tokens_session`, `duration_ms`.
- `meta` + `/rag/response/ping` return `ping_ms` measured server-side, cached for 5 seconds.

Logging (examples)
- Service logs:
  - `stream.request` (payload redacted for images)
  - `stream.connected`, `stream.chunk`, `stream.event`, `stream.last_chunk`, `stream.closed`
  - `stream.exception`
- Controller logs:
  - `send_stream.start` (input meta)
  - `send_stream.delta` (length + preview)
  - `send_stream.output_done`, `send_stream.tool_delta`, `send_stream.tool_done`
  - `send_stream.error` (message + type + code + quota flag)
  - `send_stream.completed` (response_id, tokens, duration)
  - `send_stream.event_ignored`

Frontend Behavior
- Streaming response:
  - Shows spinner while awaiting chunks.
  - Appends `delta` tokens into a bot bubble with a blinking cursor ▌.
  - Removes cursor on `done`; falls back to a friendly message if no text arrived.
  - Smart autoscroll: only scrolls when user is at the bottom.
- Stats panel (collapsed by default): shows tokens, duration, ping.
- Ping refresh: polls `/rag/response/ping` every 5s.

Troubleshooting
- If nothing appears:
  - Check `var/log/streaming.log` for `stream.connected` and `stream.event`.
  - Confirm server/proxy buffering is disabled and timeouts are generous.
  - Verify OpenAI API key and `RAG_RESPONSES_MODEL`.
  - Inspect network tab: `/send-stream` should remain open and receive SSE lines.
- Idle timeouts:
  - Ensure `timeout: 0` in request and long `stream(..., 300.0)` wait.
  - Increase reverse proxy read timeouts.

Functions Added/Used for Streaming
- Controller: `sendStream()`, `ping()`, `getCachedPing()`
- Service: `createResponseStream()`, `drainSseBuffer()`, `buildPayload()`, `ping()`

