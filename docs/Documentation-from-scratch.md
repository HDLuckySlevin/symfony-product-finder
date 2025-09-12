# Projekt von Grund auf: Symfony + Milvus + OpenAI (APIs, RAG-Streaming)

Ziel: Eine saubere Referenz, um das Backend von Scratch zu bauen. Fokus auf APIs, die Daten in Milvus speichern/abrufen und mit OpenAI (Embeddings, Vision, Chat, STT) interagieren. Frontend nur minimal für RAG/Streaming/Chat.

## Ziel & Scope
- APIs: Produktimport (JSON), Suche (Text/Bild/Audio), Embedding-Utilities, Chat/RAG.
- Daten: Produkttexte in Milvus als Vektoren; Query → Embedding → Vector Search → LLM-Empfehlung.
- Sicherheit: `X-API-Key`-Header für alle API-Endpunkte.
- Frontend: Nur minimaler Chat-Stream (SSE oder Chunked Streaming) zur Demo.

## Tech‑Stack
- Backend: Symfony 6.4 (PHP 8.2+)
- OpenAI: `openai-php/client` (Embeddings, Vision, Chat, Whisper/STT)
- Vektorstore: Milvus via `helgesverre/milvus`
- Qualität: PHPUnit, PHPStan
- Optional Dev: DDEV mit Milvus/MinIO/etcd/Attu

## Systemüberblick (High‑Level)
- Import: Produkt(JSON) → serialisieren (Chunks) → Embeddings → Milvus upsert.
- Suche:
  - Text: user message → embed → Milvus search → OpenAI Chat (Empfehlung/Begründung)
  - Bild: Bild → Vision beschreibt → embed → Milvus → Chat
  - Audio: Audio → Whisper transkribiert → wie Text
- Utilities: Health/Dimension/Text-Embedding/Image-Embedding.
- Chat/RAG: Streaming von LLM‑Antworten an Client.

## Greenfield Setup (Schritt für Schritt)
1) Projekt anlegen
- `composer create-project symfony/skeleton product-finder && cd product-finder`
- `composer require symfony/framework-bundle symfony/runtime symfony/console symfony/http-client symfony/validator symfony/serializer symfony/yaml symfony/mime symfony/translation symfony/monolog-bundle symfony/property-access twig/twig symfony/twig-bundle`
- `composer require openai-php/client:^0.13 helgesverre/milvus:^0.1`

2) Verzeichnisstruktur
- `src/Controller/` – HTTP APIs
- `src/Service/` – Domänenlogik + Integrationen
- `src/DTO/` – Request/Response-DTOs
- `config/services.yaml` – DI-Bindings (Interfaces → Implementierungen)
- `config/prompts.yaml` – Prompt-Texte (System/User Templates)

3) Environment‑Variablen (.env.local)
- Core: `APP_ENV`, `APP_SECRET`, `APP_API_KEY`
- OpenAI: `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_MODEL_IMAGE`, `OPENAI_CHAT_MODEL`, `OPENAI_STT_MODEL`, optional `OPENAI_API_BASE`
- Milvus: `MILVUS_HOST`, `MILVUS_PORT`, `MILVUS_COLLECTION`, optional `MILVUS_TOKEN`
- Prompt: `IMAGE_DESCRIPTION_PROMPT`

4) DI‑Schnittstellen definieren (src/Service/*Interface.php)
- `EmbeddingGeneratorInterface`
  - `public function getVectorDimension(): int`
  - `public function generateQueryEmbedding(string $text): array` (floats)
  - `public function generateProductEmbeddings(array $chunks): array` (list<float[]>)
  - `public function describeImageFile(string $path): array` (`['description' => string, 'vector' => float[]]`)
- `SearchServiceInterface`
  - `public function generateChatCompletion(array $context): string|iterable`
    - Übergibt: Query, Treffer, Prompt‑Texte; Rückgabe string oder Stream (Iterator)
- `VectorStoreInterface`
  - `public function initializeCollection(int $dimension): void`
  - `public function insertProductChunks(array $rows): int`
  - `public function deleteProductVectors(string $productId): int`
  - `public function searchSimilarProducts(array $vector, int $topK = 5, ?array $filter = null): array`
- `SpeechToTextInterface` (optional)
  - `public function transcribe(string $path): string`

5) Services implementieren (Kernlogik)
- `OpenAIEmbeddingService` (nutzt `OpenAI\Client`)
  - Mappe Model → Dimension (z. B. `text-embedding-3-small` → 1536). Liefere via `getVectorDimension()`.
  - `generateQueryEmbedding()`/`generateProductEmbeddings()` nutzen Embeddings‑API.
  - `describeImageFile(path)`: Vision‑Chat (Systemprompt + Bild) → Beschreibung → Embedding.
- `MilvusVectorStoreService` (helgesverre/milvus)
  - `initializeCollection(dim)`: Erzeuge Collection mit Cosine‑Metric. Felder: `id` (auto), `product_id` (string), `chunk_id` (string), `text` (string), `vector` (float[dim]), optionale Metadaten.
  - `insertProductChunks(rows)`: Upsert auf Basis (`product_id`,`chunk_id`).
  - `searchSimilarProducts(vector, topK, filter)`: Cosine‑Ähnlichkeit; gib Treffer inkl. Score und Produktdaten zurück.
  - `deleteProductVectors(productId)`: Lösche alle Chunks zu Produkt.
- `OpenAISearchService`
  - Baut Chat‑Prompt (System + User) aus `PromptService` und Kontext (Query, Top‑Treffer).
  - `generateChatCompletion()`: Nicht‑Streaming (string) oder Streaming (Iterator) – s. Abschnitt „Streaming“.
- `OpenAISpeechToTextService` (optional)
  - `transcribe(path)`: Whisper‑Upload → Text.
- `PromptService`
  - Lädt `config/prompts.yaml` und ersetzt `%placeholder%`.

6) Controller (Attribute Routing)
- `ApiSearchController`
  - `POST /api/search/text` → Body `{ "message": "..." }` → embed → Milvus → Chat → `{ products: [...], recommendation: "..." }`
  - `POST /api/search/image` → Form `image` (jpeg/png/gif/webp) → Vision→embed→Milvus→Chat
  - `POST /api/search/audio` → Form `audio` → STT → wie Textsuche
- `EmbeddingController`
  - `GET /dimension` → `{ dimension: number }`
  - `GET /healthstatus` → `{ status: "ok", openai: bool, milvus: bool }`
  - `POST /text-embedding` → `{ texts: string[] }` → `{ vectors: number[][] }`
  - `POST /image-embedding` → Form `file` → `{ description: string, vector: number[] }`
- `ProductImportController`
  - `POST /api/products` → Produkt‑JSON → Embeddings → Milvus Upsert → `{ upserted: number }`
  - `DELETE /api/products/{id}` → `{ deleted: number }`
- `ProductFinderController` (Chat/RAG)
  - `GET /chat/stream?message=...` (SSE) oder `POST /chat/stream` → tokenweise Ausgabe.

7) Sicherheit
- API‑Key: Jeder API‑Request muss Header `X-API-Key: <APP_API_KEY>` senden.
- Uploads: MIME‑Type prüfen (Bild/Audio); Größenlimits setzen.
- CORS: Eng konfigurieren, nur benötigte Origins/Methoden/Headers erlauben.

## Daten & Milvus Schema
- Collection: z. B. `products`
- Dimension: muss Embedding‑Modell entsprechen (z. B. 1536)
- Felder (Empfehlung):
  - `id` (auto/int64)
  - `product_id` (string)
  - `chunk_id` (string)
  - `text` (string)
  - `vector` (float[dim])
  - optional: `metadata` (JSON/string)
- Suchparameter
  - Metric: Cosine
  - topK: 5–20
  - Schwelle: Konsistent interpretieren (Cosine‑Score: höher = ähnlicher). Keine gemischten `<=`/`>=`‑Vergleiche.

## Prompt‑Konzept (config/prompts.yaml)
- `product_finder.system_prompt` – Rolle/Verhalten des Assistenten.
- `product_finder.user_message_template` – Template inkl. Platzhalter (z. B. `%query%`, `%products%`).
- `product_finder.no_results_message` – Text, wenn keine Treffer.
- Zugriff über `PromptService::getPrompt(section, key, params)`

## Streaming (RAG/Chat)
- Server: SSE oder `StreamedResponse` (Chunked). Bei OpenAI‑Streaming die Tokens/Chunks weiterreichen.
- SSE Beispiel (Pseudo):
  - Response‑Header: `Content-Type: text/event-stream`, `Cache-Control: no-cache`
  - Für jeden Token: `echo "data: ".json_encode(['delta' => $token])."\n\n"; flush();`
- Minimal‑Frontend: JS `EventSource` oder `fetch` mit ReadableStream. Keine komplexe UI notwendig.

## Fehlerbehandlung & Logging
- Services kapseln externe Fehler (OpenAI/Milvus), loggen strukturiert via `LoggerInterface`.
- Controller geben JSON‑Fehler (ohne Stacktrace) mit klarer Message/Code zurück.
- Upload‑Fehler, Auth‑Fehler, Rate‑Limits differenzieren.

## Tests & Qualität
- PHPUnit: Unit‑Tests für Services; API‑Tests für Controller‑Happy‑Path und Fehlerfälle.
- PHPStan Level ~6: Typsicherheit, Dead‑Code vermeiden.
- Keine echten Secrets in Tests/Logs; Mocks/Fakes für externe Clients.

## Lokales Setup
- Ohne DDEV
  - Milvus bereitstellen (`MILVUS_HOST/PORT/COLLECTION`), Composer install, PHP‑Server (`symfony server:start` oder `php -S ... -t public`).
  - Health prüfen: `GET /healthstatus`; Dimension: `GET /dimension`.
- Mit DDEV
  - Docker‑Compose mit Milvus/MinIO/etcd/Attu. App via HTTPS, Attu UI zur Einsicht der Vektoren.

## Stolpersteine & Entscheidungen
- Cosine‑Score‑Semantik: Höher ist besser; einheitliche Threshold‑Logik.
- Collection‑Name vs. DB‑Name: Einheitlich halten, Client‑Konventionen beachten.
- Dimension/Modell: Bei Modellwechsel Collection migrieren oder neu initialisieren.
- Sicherheit: API‑Key nie im Repo; `.env.local` ist git‑ignored.

## Quickstart (Checkliste)
- `composer install`
- `.env.local` mit `APP_API_KEY`, `OPENAI_API_KEY`, `OPENAI_*_MODEL`, `MILVUS_*` befüllen
- Server starten: `symfony server:start`
- `GET /healthstatus` → ok
- `POST /api/products` → Daten einspielen
- `POST /api/search/text` mit `{ "message": "..." }` testen
- `/chat/stream?message=...` im Browser/CLI testen

## Erweiterungen (optional)
- Weitere Importe (XML/CSV), zusätzliche Filter/Ranking‑Features.
- Persistente Chat‑History, Nutzerprofile.
- Observability: Tracing/Metrics (OpenTelemetry), strukturierte Logs an Central Log.

-- Ende der Anleitung --

