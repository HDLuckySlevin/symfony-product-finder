# Controller & REST-Endpoints

Alle aktiven Routen aus `docs/_generated/router.json` mit Methode, Pfad, Name, zuständiger Action, API-Key-Pflicht sowie Request-/Response-Modelle.

Hinweis zur Authentifizierung
- API-Key-Check über `ApiKeySubscriber` (Header `X-API-Key`, Fallback Cookie `api_key`) für alle Pfade außer: `/`, `/search`, `/search/image`, `/search/audio`.
- Beleg: `src/EventSubscriber/ApiKeySubscriber.php:~30–85`, `config/services.yaml:~20–45`, `src/Controller/WebInterfaceController.php:~40–55`.


## Primäre JSON-APIs (Suche, Import, Chat)

| Methode | Pfad | Name | Controller::Action | API-Key | Request-Modell | Response-Modell |
|---|---|---|---|---|---|---|
| POST | `/api/search/text` | `api_search_text` | `ApiSearchController::searchText` (`src/Controller/ApiSearchController.php:~120`) | erforderlich | JSON `ChatRequestDto` (`src/DTO/Request/ChatRequestDto.php`) | JSON `ChatResponseDto` (`src/DTO/Response/ChatResponseDto.php`) |
| POST | `/api/search/image` | `api_search_image` | `ApiSearchController::searchImage` (`src/Controller/ApiSearchController.php:~160`) | erforderlich | multipart `image` (jpeg/png/gif/webp) | JSON `ChatResponseDto` |
| POST | `/api/search/audio` | `api_search_audio` | `ApiSearchController::searchAudio` (`src/Controller/ApiSearchController.php:~220`) | erforderlich | multipart `audio` (webm/audio/*) | JSON `ChatResponseDto` (bei STT-Fehler `500`) |
| POST | `/api/products/chat` | `api_products_chat` | `ProductFinderController::chatSearch` (`src/Controller/ProductFinderController.php:~40–150`) | erforderlich | JSON `ChatRequestDto` | JSON `ChatResponseDto` |
| POST | `/api/products` | `api_products_import` | `ProductImportController::importProduct` (`src/Controller/ProductImportController.php:~60–180`) | erforderlich | JSON Product (siehe README Beispiele) | `{ success: true }` oder `{ message: string }` |
| DELETE | `/api/products/{id}` | `api_products_delete` | `ProductImportController::deleteProduct` (`src/Controller/ProductImportController.php:~190–220`) | erforderlich | Pfad `id:int` | `{ success: true }` oder `{ message: string }` |


## Embedding-Service (Utility)

| Methode | Pfad | Name | Controller::Action | API-Key | Request-Modell | Response-Modell |
|---|---|---|---|---|---|---|
| POST | `/text-embedding` | `app_embedding_textembedding` | `EmbeddingController::textEmbedding` (`src/Controller/EmbeddingController.php:~20–60`) | erforderlich | JSON `{ texts: string[] }` | `{ vectors: number[][] }` |
| POST | `/image-embedding` | `app_embedding_imageembedding` | `EmbeddingController::imageEmbedding` (`src/Controller/EmbeddingController.php:~60–95`) | erforderlich | multipart `file` (jpeg/png/gif/webp) | `{ description: string, vector: number[], provider: 'openai' }` |
| GET | `/dimension` | `app_embedding_dimension` | `EmbeddingController::dimension` (`src/Controller/EmbeddingController.php:~97–105`) | erforderlich | – | `{ dimension: number }` |
| GET | `/healthstatus` | `app_embedding_health` | `EmbeddingController::health` (`src/Controller/EmbeddingController.php:~107–115`) | erforderlich | – | `{ status: string, provider: string }` |


## Öffentliche Web-UI Endpoints (ohne API-Key)

| Methode | Pfad | Name | Controller::Action | API-Key | Request-Modell | Response-Modell |
|---|---|---|---|---|---|---|
| ANY | `/` | `app_home` | `WebInterfaceController::index` (`src/Controller/WebInterfaceController.php:~30–55`) | nicht erforderlich | – | HTML (setzt `api_key`-Cookie) |
| POST | `/search` | `app_web_search` | `WebInterfaceController::search` (`src/Controller/WebInterfaceController.php:~57–100`) | nicht erforderlich | JSON `{ query: string }` | `{ success: bool, output: string }` |
| POST | `/search/image` | `app_web_search_image` | `WebInterfaceController::searchImage` (`src/Controller/WebInterfaceController.php:~100–150`) | nicht erforderlich | multipart `image` | `{ success: bool, output: string }` |
| POST | `/search/audio` | `app_web_search_audio` | `WebInterfaceController::searchAudio` (`src/Controller/WebInterfaceController.php:~150–220`) | nicht erforderlich | multipart `audio` | `{ success: bool, output: string }` |


## RAG/Responses/Assistants (optional)

Diese Routen stützen sich auf optionale Services unter `src/Service/rag/*` und dienen Demo-/Experimentpfaden für OpenAI Responses/Assistants. Sofern der API-Key-Subscriber aktiv ist, sind sie geschützt (nicht in der Exclude-Liste), d. h. API-Key erforderlich.

| Methode | Pfad | Name | Controller::Action | API-Key | Request-Modell | Response-Modell |
|---|---|---|---|---|---|---|
| POST | `/rag/upload-image` | `rag_upload_image` | `rag/assistant/ImageUploadController::upload` | erforderlich | multipart `image` | JSON (Upload-Ergebnis) |
| POST | `/api/rag/start` | `rag_api_start` | `rag/assistant/RagApiController::start` | erforderlich | Form/JSON je nach Implementierung | JSON (Session/Start) |
| POST | `/api/rag/message` | `rag_api_message` | `rag/assistant/RagApiController::message` | erforderlich | Text + optional Bild | JSON (Antwort/Verlauf) |
| POST | `/api/rag/reset` | `rag_api_reset` | `rag/assistant/RagApiController::reset` | erforderlich | – | JSON `{ ok: bool }` |
| GET | `/rag/response/chat` | `rag_response_chat` | `rag/response/ChatController::chat` (`src/Controller/rag/response/ChatController.php:~20–35`) | erforderlich | – | HTML (Ansicht) |
| POST | `/rag/response/chat/send` | `rag_response_chat_send` | `rag/response/ChatController::send` (`src/Controller/rag/response/ChatController.php:~35–120`) | erforderlich | Form: `message`, optional `image` | JSON `{ ok, response, answer, stats }` |
| POST | `/rag/response/__disabled_send_stream` | `rag_response_chat_send_stream_legacy` | `rag/response/ChatController::sendStream` | erforderlich | Streaming-Form | SSE/JSON-Events |
| GET | `/rag/response/response/{id}` | `rag_response_get` | `rag/response/ChatController::getOne` | erforderlich | Pfad `id` | JSON (nicht unterstützt, 400) |
| DELETE | `/rag/response/response/{id}` | `rag_response_delete` | `rag/response/ChatController::deleteOne` | erforderlich | Pfad `id` | JSON (nicht unterstützt, 400) |
| POST | `/rag/response/chat/reset` | `rag_response_chat_reset` | `rag/response/ChatController::reset` | erforderlich | – | `{ ok: true }` |
| GET | `/rag/response/chat-assistant` | `rag_response_assistant_chat` | `rag/response/AssistantChatController::chat` | erforderlich | – | HTML (Ansicht) |
| POST | `/rag/response/chat-assistant/send` | `rag_response_assistant_chat_send` | `rag/response/AssistantChatController::send` | erforderlich | Form: `message`, optional `image` | JSON Antwort |
| GET | `/rag/streaming/chat` | `rag_streaming_chat` | `rag/streaming/ChatController::chat` | erforderlich | – | HTML (Ansicht) |
| POST | `/rag/streaming/chat/send-stream` | `rag_streaming_chat_send_stream` | `rag/streaming/ChatController::sendStream` | erforderlich | Form: `message`, optional `image` | Text/Event-Stream |


OpenAPI-Hinweise
- Für Kern-DTOs sind OpenAPI-Attribute definiert: `ChatRequestDto`, `ChatResponseDto`, `ProductResponseDto` (`src/DTO/*`).
- Eine vollständige OpenAPI-Datei wird nicht automatisch exportiert; die Attribute können von Tools ausgelesen werden.


<!-- STEP DONE: 06/13 -->

