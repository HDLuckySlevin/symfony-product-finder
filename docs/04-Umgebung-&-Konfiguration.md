# Umgebung & Konfiguration

Überblick über Umgebungsvariablen, Parameter/DI-Bindings, zentrale Package-Konfiguration sowie Security/Access-Control.

Belegstellen:
- .env-Kaskade und Defaults: `.env:~1–40`, `.env.dist:~20–60`
- DI/Parameter/Env-Bindings: `config/services.yaml:~1–140`
- Security (API-Key): `src/EventSubscriber/ApiKeySubscriber.php:~1–120`, `config/services.yaml:~20–45`, `src/Controller/WebInterfaceController.php:~35–55`
- Framework/Router/Twig/Validator/Monolog: `config/packages/*.yaml`


## .env / Umgebungen

- Reihenfolge der Laden-Logik: `.env` → `.env.local` (ungetrackt) → `.env.$APP_ENV` → `.env.$APP_ENV.local` → echte Umgebung (höchste Priorität). Siehe Header in `.env:~1–20`.
- Keine Secrets in getrackten Dateien ablegen (nur Platzhalter). `.env.local` ist unversioniert; Werte dort bleiben lokal (`.env:~1–20`).
- Typische Umgebungen: `dev` (Default), `test` (besondere Framework‑Settings), `prod` (optimiert, anderes Logging). Siehe `config/packages/* when@dev|test|prod`.


## Environment-Variablen (ohne Secrets)

Core
- `APP_ENV` (z. B. `dev`), `APP_SECRET` (Framework‑Secret)
- `APP_API_KEY` (Pflicht für alle API‑Requests)

OpenAI / Modelle
- `OPENAI_API_KEY` (erforderlich)
- `OPENAI_MODEL` (Embeddings, z. B. `text-embedding-ada-002`), `OPENAI_MODEL_IMAGE` (Vision, z. B. `gpt-4o`)
- `OPENAI_CHAT_MODEL` (Empfehlungen/Chat, z. B. `gpt-3.5-turbo`)
- `OPENAI_STT_MODEL` (Whisper, z. B. `whisper-1`)
- `OPENAI_API_BASE`, `OPENAI_API_BASE_RAG` (optional Base-URLs)

Milvus
- `MILVUS_HOST` (z. B. `http://milvus`), `MILVUS_PORT` (z. B. `19530`)
- `MILVUS_COLLECTION` (z. B. `default` oder `products`)
- `MILVUS_TOKEN` (optional)

Sonstiges
- `DEBUG_VECTORS` (bool; zusätzliche Vektor‑Logs im Embedding‑Service)
- `IMAGE_DESCRIPTION_PROMPT` (Prompttext für Vision‑Beschreibung)

RAG/Responses/Assistants (optional)
- `RAG_RESPONSES_MODEL`, `OPENAI_VECTOR_STORE_IDS` (CSV), `RAG_ASSISTANT_ID`, `OPENAI_ASSISTANT_ID`
- `RAG_RESPONSES_PROMPT_ID`, `RAG_RESPONSES_PROMPT_VERSION`
- Tuning: `RAG_RESPONSES_MAX_OUTPUT_TOKENS`, `RAG_RESPONSES_TEMPERATURE`

Verwendung in DI
- OpenAI‑Client/Modelle: `config/services.yaml:~90–125`
- Milvus‑Client: `config/services.yaml:~65–85`
- VectorStore‑Collection: `config/services.yaml:~100–115`
- API‑Key Parameter: `config/services.yaml:~1–25`


## Parameter & DI‑Bindings

- Parameter
  - `parameters.app.api_key` bezieht `APP_API_KEY` (`config/services.yaml:~1–10`).
- Subscriber (Security)
  - `App\EventSubscriber\ApiKeySubscriber` erhält `$apiKey`, `$cookieName='api_key'`, `$excludedPaths=['/', '/search', '/search/image', '/search/audio']` (`config/services.yaml:~20–45`).
- Controller/Services (Auswahl)
  - `OpenAIEmbeddingService` mit `$embeddingModel`, `$imageModel`, `$debugVectors`, `$imageDescriptionPrompt` (`config/services.yaml:~105–125`).
  - `MilvusVectorStoreService` mit `$collectionName` (`config/services.yaml:~95–110`).
  - Interface-Bindings: `EmbeddingGeneratorInterface → OpenAIEmbeddingService`, `SearchServiceInterface → OpenAISearchService`, `SpeechToTextServiceInterface → OpenAISpeechToTextService` (`config/services.yaml:~125–170`).


## Framework & Packages

Framework (`config/packages/framework.yaml`)
- `secret: %env(APP_SECRET)%`, `annotations: false`, `http_method_override: false`, `handle_all_throwables: true` (`config/packages/framework.yaml:~1–10`).
- Session aktiviert (Start nur bei Zugriff): `cookie_secure: auto`, `cookie_samesite: lax` (`config/packages/framework.yaml:~10–20`).
- `when@test` setzt `test: true` und Mock‑Session‑Storage (`config/packages/framework.yaml:~22–30`).

Routing
- Attribute‑Routing für `src/Controller/*` in `config/routes.yaml` (`config/routes.yaml:~1–10`).
- Framework Router UTF‑8, `strict_requirements` nur prod‑spezifisch (`config/packages/routing.yaml:~1–15`).

Twig (`config/packages/twig.yaml`)
- `file_name_pattern: '*.twig'` und Basis‑Konfiguration (`config/packages/twig.yaml:~1–10`).

Validator/Cache (`config/packages/validator.yaml`, `cache.yaml`)
- Standard‑Konfigurationen, u. a. Namespaces und Pfade (`config/packages/validator.yaml:~1–20`, `config/packages/cache.yaml:~1–20`).

HTTP Discovery (`config/packages/http_discovery.yaml`)
- Bindet PSR‑17 Factory‑Interfaces auf `Http\Discovery\Psr17Factory` (`config/packages/http_discovery.yaml:~1–20`).

Monolog (`config/packages/monolog.yaml`)
- Channels: `deprecation`, `rag`, `streaming`, `response_basic`, `response_assistant` (`config/packages/monolog.yaml:~1–5`).
- when@dev: `main` Stream auf `%kernel.environment%.log`, zusätzliche Channel‑Handler (`config/packages/monolog.yaml:~5–35`).
- when@test/prod: `fingers_crossed`/`nested`, JSON‑Formatter auf stderr, separate Files für RAG/Streaming/Responses (`config/packages/monolog.yaml:~40–120`).

Bundles (`config/bundles.php`)
- FrameworkBundle, TwigBundle, MonologBundle aktiviert (`config/bundles.php:~1–20`).


## Security & Access Control

API‑Key Enforcement (kein `security.yaml` vorhanden)
- Subscriber prüft pro Request:
  - Wenn Pfad in `excludedPaths` → kein Check (`src/EventSubscriber/ApiKeySubscriber.php:~35–50`).
  - Sonst `X-API-Key` Header, fallback Cookie `api_key` (`src/EventSubscriber/ApiKeySubscriber.php:~50–70`).
  - Ungültig → `401 Invalid API key` (`src/EventSubscriber/ApiKeySubscriber.php:~70–85`).
- Web‑UI legt Cookie mit API‑Key (`api_key`) auf `/` (`src/Controller/WebInterfaceController.php:~40–55`).

Exkludierte Pfade (öffentlich)
- `/` (Home/Web‑UI), `/search`, `/search/image`, `/search/audio` (`config/services.yaml:~25–45`).

Geschützte Pfade (Beispiele)
- `/api/search/text|image|audio`, `/api/products*`, Embedding‑Service‑Routen (`/text-embedding`, `/image-embedding`, `/dimension`, `/healthstatus`) — Header `X-API-Key` erforderlich. Controller: `src/Controller/*`.


## Feature-Flags & Betriebsrelevantes

- `DEBUG_VECTORS` (bool): loggt Embedding‑Vektoren zusätzlich (`src/Service/OpenAIEmbeddingService.php:~70–90, ~135–155`).
- RAG/Responses aktivieren zusätzliche Controller/Services (nur wenn Env‑Variablen gesetzt; DI‑Konfiguration vorhanden in `config/services.yaml:~130–end`).
- Prompttexte zentral in `config/prompts.yaml`; Zugriff via `PromptService` (`src/Service/PromptService.php:~35–70`).
- Milvus‑Dimensionskonsistenz: `OpenAIEmbeddingService::getVectorDimension` ↔ `MilvusVectorStoreService::createCollection` (Modellwechsel erfordert angepasste Collection).


<!-- STEP DONE: 04/13 -->

