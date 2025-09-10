# Module & Bundles

Überblick über eingesetzte Bundles/Module und relevante Konfigurationen.

Belegstellen:
- Bundles: `config/bundles.php:~1–20`
- Composer-Dependencies: `composer.json:~1–80`, `docs/_generated/composer-deps.txt`
- Konfiguration: `config/packages/*.yaml`, `config/services.yaml`


## Symfony Bundles (aktiv)

- FrameworkBundle
  - Basisfunktionalität, Routing, Sessions, Console.
  - Config: `config/packages/framework.yaml`, `config/packages/routing.yaml`.
- TwigBundle
  - Templating für Web-UI (`templates/*`).
  - Config: `config/packages/twig.yaml`.
- MonologBundle
  - Logging (inkl. dedizierter Channels für RAG/Streaming/Responses).
  - Config: `config/packages/monolog.yaml`.

Hinweis: Kein SecurityBundle, kein DoctrineBundle im Einsatz. Auth erfolgt via Subscriber (API-Key), Persistenz über Milvus.


## Symfony Komponenten (ohne Bundle)

- `symfony/http-client`
  - HTTP-Aufrufe (z. B. Upload zu OpenAI Vector Stores in `ProductImportController`).
- `symfony/serializer`, `symfony/validator`, `symfony/property-access`, `symfony/mime`, `symfony/yaml`, `symfony/translation`
  - DTO-Serialisierung, Validierung, Hilfsfunktionen, YAML-Laden (Prompts).


## Drittanbieter-Libraries

- `openai-php/client`
  - OpenAI-API (Embeddings, Chat, Audio/Whisper, Vision via Chat mit Image-Content).
  - DI: `OpenAI\\Client` Factory in `config/services.yaml:~140–150` (API-Key aus `OPENAI_API_KEY`).
- `helgesverre/milvus`
  - Milvus-Client (Collections, Vector-Insert/Search/Delete).
  - DI: `Milvus\\Client` via `App\\Factory\\MilvusClientFactory` (`MILVUS_HOST/PORT/TOKEN`).
- PSR-17 Discovery (`Http\\Discovery\\Psr17Factory`)
  - Bereitstellung PSR-17 Interfaces (Factories) — Konfig: `config/packages/http_discovery.yaml`.


## Relevante Eigenmodule

- Services
  - Embeddings/Vision: `App\\Service\\OpenAIEmbeddingService`
  - Suchempfehlung (Chat): `App\\Service\\OpenAISearchService`
  - Speech-to-Text: `App\\Service\\OpenAISpeechToTextService`
  - Vector Store: `App\\Service\\MilvusVectorStoreService`
  - Prompts: `App\\Service\\PromptService`
  - Import: `App\\Service\\XmlImportService`, `App\\Service\\JsonImportService`
- Controller
  - Suche/Embedding/Import/API-Key-Cookie-Bridge in `src/Controller/*`
- Subscriber
  - `App\\EventSubscriber\\ApiKeySubscriber` erzwingt API-Key für geschützte Pfade.


## Konfiguration (Auszug)

- Framework
  - `framework.secret`, Sessions, `handle_all_throwables` (`config/packages/framework.yaml`).
- Routing
  - Attribute-Routing (`config/routes.yaml`), UTF-8, Prod-Strict (`config/packages/routing.yaml`).
- Monolog
  - Channels: `rag`, `streaming`, `response_basic`, `response_assistant` mit eigenen Handlern (`config/packages/monolog.yaml`).
- DI-Bindings
  - Interfaces → Implementierungen (Embedding/Search/STT/VectorStore/Prompt) (`config/services.yaml`).


<!-- STEP DONE: 08/13 -->

