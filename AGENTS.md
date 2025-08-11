# AGENTS.md — Working With This Repository

This document briefs code agents and contributors on how to work productively and safely with this Symfony-based Product Finder. It summarizes the architecture, local setup, environment, commands, APIs, conventions, and common pitfalls so you can make precise changes with confidence.

## Overview

- Purpose: Natural-language product search powered by OpenAI embeddings and chat completions, backed by Milvus for vector similarity search.
- Core flows:
  - Import product data (XML or JSON) → generate embeddings → store in Milvus.
  - Search via text, image, or audio → embed query → vector search → LLM recommendation.
- Primary entry points: HTTP controllers in `src/Controller`, console commands in `src/Command`, services in `src/Service`.

## Tech Stack

- Symfony 6.4, PHP 8.2+
- OpenAI API (`openai-php/client`) for embeddings, vision, chat, and STT
- Milvus vector database (via `helgesverre/milvus` client)
- DDEV for local development (Milvus, MinIO, etcd, Attu via compose)
- PHPUnit for tests, PHPStan for static analysis

## Repository Map

- `src/Controller/`:
  - `ApiSearchController.php`: JSON search endpoints for text, image, audio.
  - `EmbeddingController.php`: Embedding service endpoints (dimension/health/text/image).
  - `ProductImportController.php`: Import single product as JSON; delete by ID.
  - `ProductFinderController.php`: Chat-style search endpoint assembling prompts.
  - `WebInterfaceController.php`: Web UI, bridges UI actions to console commands.
- `src/Service/`:
  - `OpenAIEmbeddingService.php`: Embeddings + image description; implements `EmbeddingGeneratorInterface`.
  - `OpenAISearchService.php`: Chat completions for recommendations; implements `SearchServiceInterface`.
  - `MilvusVectorStoreService.php`: Milvus collection init, insert, query, delete; implements `VectorStoreInterface`.
  - `XmlImportService.php` / `JsonImportService.php`: Parse inputs → `Product` entities.
  - `PromptService.php`: Loads prompts from `config/prompts.yaml`.
  - `OpenAISpeechToTextService.php`: Audio transcription (Whisper).
  - `rag/assistant/*`, `rag/response/*`: Optional OpenAI Assistants + Responses API integration.
- `src/DTO/`: Request/response DTOs for API schemas.
- `config/services.yaml`: DI wiring, parameters, and env bindings.
- `config/prompts.yaml`: Prompt templates and messages.
- `src/DataFixtures/xml/`: Sample XML data.
- `templates/home/index.html.twig`: Web UI with chat/record/upload interactions.
- `.ddev/`: DDEV config and Milvus/Attu compose.
- `tests/`: PHPUnit tests for XML import and commands.

## Local Development

Recommended: DDEV (see `.ddev/config.yaml`).

- Install dependencies: `ddev composer install`
- Start services: `ddev start`
- App URL: `https://symfony-product-finder.ddev.site/`
- Attu (Milvus UI): `https://symfony-product-finder.ddev.site:8521`

Without DDEV, you must provision:
- PHP 8.2+, Composer, web server for `public/`, and a Milvus instance reachable at `MILVUS_HOST`/`MILVUS_PORT`.

## Environment Variables

Define sensitive values in `.env.local` (do not commit). Defaults live in `.env` / `.env.dist`.

- Core
  - `APP_ENV` (default: `dev`)
  - `APP_SECRET`
  - `APP_API_KEY`: Required by API (sent via `X-API-Key` header; Web UI sets an HTTP-only cookie).
- OpenAI
  - `OPENAI_API_KEY`: Required.
  - `OPENAI_MODEL`: Embedding model (e.g., `text-embedding-3-small`).
  - `OPENAI_MODEL_IMAGE`: Vision-capable chat model (e.g., `gpt-4o`).
  - `OPENAI_CHAT_MODEL`: Chat model used for recommendations (e.g., `gpt-3.5-turbo`).
  - `OPENAI_STT_MODEL`: Whisper model (e.g., `whisper-1`).
  - `IMAGE_DESCRIPTION_PROMPT`: System/user instruction passed to vision model for image description.
- Milvus
  - `MILVUS_HOST` (DDEV default points to internal service)
  - `MILVUS_PORT` (e.g., `19530`)
  - `MILVUS_COLLECTION` (e.g., `products` or `default`)
  - `MILVUS_TOKEN` (optional)
- Optional RAG/Responses/Assistants
  - `OPENAI_API_BASE` (default: `https://api.openai.com/v1`)
  - `RAG_RESPONSES_MODEL`
  - `OPENAI_VECTOR_STORE_IDS` (CSV of vector store IDs)
  - `OPENAI_API_BASE_RAG`, `OPENAI_ASSISTANT_ID`, `RAG_ASSISTANT_ID`, `RAG_VECTOR_STORE_ID`

Security
- Never commit real API keys or secrets. If present locally, treat as placeholders and do not replicate into PRs.
- `.env.local` is ignored by Git and must remain untracked.

## Commands and Workflows

- Composer
  - Install: `ddev composer install`
- Testing
  - PHPUnit: `ddev php bin/phpunit`
  - PHPStan (level 6): `ddev php vendor/bin/phpstan analyse -c phpstan.dist.neon`
- Console (via DDEV)
  - Import XML: `ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml`
  - Test search: `ddev php bin/console app:test-search "I need a waterproof smartphone"`
  - Process image: `ddev php bin/console app:process-image path/to/image.jpg`
  - Process audio: `ddev php bin/console app:process-audio path/to/audio.webm`

## HTTP APIs

Auth
- All API endpoints require `X-API-Key` matching `APP_API_KEY`.
- Web UI at `/` sets an HTTP-only `api_key` cookie so the browser can call protected APIs.

Endpoints (high-level)
- Search
  - `POST /api/search/text` → `{ message: string }` → products + recommendation
  - `POST /api/search/image` → form-data `image` (jpeg/png/gif/webp)
  - `POST /api/search/audio` → form-data `audio` (webm/audio/*) → STT → search
- Embedding service
  - `GET /dimension` → vector dimension for current embedding model
  - `POST /text-embedding` → `{ texts: string[] }` → vectors
  - `POST /image-embedding` → form-data `file` → { description, vector }
  - `GET /healthstatus` → basic liveness
- Product import
  - `POST /api/products` → single product JSON → vectors upserted
  - `DELETE /api/products/{id}` → remove vectors for product id

See `docs/API.md` and `docs/api/postman_collection.json` for concrete payloads and usage.

## Services and Data Flow

- Query embedding: `OpenAIEmbeddingService::generateQueryEmbedding()` → vector → `MilvusVectorStoreService::searchSimilarProducts()` → results → `OpenAISearchService::generateChatCompletion()` builds recommendation using prompts from `PromptService`.
- Product import: `XmlImportService`/`JsonImportService` → `Product` → `OpenAIEmbeddingService::generateProductEmbeddings()` → `MilvusVectorStoreService::insertProductChunks()`.
- Image search: `OpenAIEmbeddingService::describeImageFile()` uses `OPENAI_MODEL_IMAGE` to describe, then embeds description.
- Audio search: `OpenAISpeechToTextService::transcribe()` to text, then normal text search flow.

Milvus
- Collection dimension must match embedding model dimension. `OpenAIEmbeddingService::getVectorDimension()` maps common models to their dimension; `MilvusVectorStoreService::createCollection()` uses this.
- DDEV spins up etcd, MinIO, Milvus, and Attu (`.ddev/docker-compose.milvus.yaml`).

## Prompts

- Location: `config/prompts.yaml`
- Retrieval: `PromptService::getPrompt(section, key, parameters)`; templates can contain `%variable%` placeholders.
- Product finder keys: `product_finder.system_prompt`, `product_finder.user_message_template`, `product_finder.no_results_message`.

## Coding Conventions

- Symfony DI: Prefer interfaces (`EmbeddingGeneratorInterface`, `SearchServiceInterface`, `VectorStoreInterface`) and bind implementations in `config/services.yaml`.
- Controllers: Attribute routing; keep request validation minimal and delegate logic to services.
- DTOs: Use immutable DTOs in `src/DTO` with OpenAPI attributes for schema.
- Logging: Inject `Psr\Log\LoggerInterface` in services/controllers; prefer structured context.
- Error handling: Catch and log at service boundaries; respond with JSON including a helpful message; avoid leaking stack traces.
- Tests: Add/adjust PHPUnit tests when changing service contracts or behaviors; follow existing patterns in `tests/`.
- Static analysis: Keep PHPStan at level 6 passing.

## Extensibility Pointers

- New embedding provider: Implement `EmbeddingGeneratorInterface` and rebind in `services.yaml`.
- Alternate vector store: Implement `VectorStoreInterface` with equivalent methods (`initializeCollection`, `insertProductChunks`, `deleteProductVectors`, `searchSimilarProducts`).
- Adjust chat model/behavior: Update `OPENAI_CHAT_MODEL` and adapt `OpenAISearchService` if advanced options are needed.
- Import formats: Extend `XmlImportService` or add new importers; ensure products serialize into the text that captures the product’s salient attributes before embedding.
- Prompts: Update `config/prompts.yaml`; keep messages concise and machine-friendly; preserve placeholders.

## CI

- GitHub Actions (`.github/workflows`): PHP 8.2, installs dependencies, runs PHPUnit.
- Consider adding a PHPStan step if raising code quality is in scope.

## Known Pitfalls and Notes

- Similarity threshold inconsistency: Some filters use `distance <= 0.5` (e.g., `ProductFinderController`), others use `>= 0.5` (`ApiSearchController`, `TestSearchCommand`). Confirm intended metric (Milvus COSINE returns similarity vs distance depending on client) and unify comparisons before changing behavior.
- Milvus collection name vs dbName: `searchSimilarProducts()` passes `dbName: $this->collectionName`. Ensure this matches your Milvus setup/client expectations before refactoring.
- Secrets in `.env.local`: Treat any existing keys as local placeholders; never propagate to PRs or logs.
- License discrepancy: README mentions MIT, `composer.json` lists `proprietary`. Clarify with maintainers before changing license metadata.

## Agent Workflow Tips

- Before changes: Identify the exact controller/service/DTO involved; confirm DI bindings in `config/services.yaml`.
- When adding behavior: Prefer new services behind interfaces; wire via DI; add narrow unit tests where feasible.
- When touching prompts: Update `config/prompts.yaml` and verify consumer code paths (`PromptService` usage).
- For endpoints: Keep attribute routes consistent; update `docs/API.md` if you change request/response shapes.
- For data changes: Ensure Milvus collection dimension aligns with embedding model; call `initializeCollection()` before inserts.
- Validation: Run `bin/phpunit` and (optionally) PHPStan. In DDEV use `ddev php ...`.

## Quick Commands (DDEV)

- Start: `ddev start`
- Install: `ddev composer install`
- Tests: `ddev php bin/phpunit`
- Import sample: `ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml`
- Web UI: `https://symfony-product-finder.ddev.site/`
- Attu UI: `https://symfony-product-finder.ddev.site:8521`

