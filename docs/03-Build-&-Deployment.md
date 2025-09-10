# Build & Deployment

Diese Anleitung fasst lokales Setup, Build-Pipeline und einen pragmatischen Deploy-Ablauf zusammen.

Belegstellen:
- Composer/Runtime: `composer.json:~1–60`
- Composer-Skripte (Auto-Scripts, Docs): `composer.json:~60–120`
- CI-Workflow: `.github/workflows/ci.yml:~1–80`
- DDEV-Setup: `.ddev/config.yaml:~1–40`
- Env-Variablen: `.env.dist:~20–55`, `config/services.yaml:~10–130`


## Lokales Setup

- Voraussetzungen
  - PHP ≥ 8.2, Composer 2 (`composer.json:~1–20`)
  - Extensions: `ctype`, `iconv`, `simplexml` (`composer.json:~10–20`)
  - Optional empfohlen: DDEV für Milvus/Services (`.ddev/config.yaml`)
- Schritte (DDEV)
  - Abhängigkeiten: `ddev composer install` (siehe README)
  - Start: `ddev start`
  - Web‑UI: `https://symfony-product-finder.ddev.site/`
  - Tests: `ddev php bin/phpunit`
- Ohne DDEV
  - Einen Webserver (nginx/apache) auf `public/` konfigurieren
  - PHP 8.2+ mit o. g. Extensions
  - Erreichbaren Milvus‑Dienst per `MILVUS_HOST`/`MILVUS_PORT`


## Konfiguration (Überblick)

- Env‑Variablen (Beispiele in `.env.dist`)
  - Core: `APP_ENV`, `APP_SECRET`, `APP_API_KEY`
  - OpenAI: `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_MODEL_IMAGE`, `OPENAI_CHAT_MODEL`, `OPENAI_STT_MODEL`, optional `OPENAI_API_BASE`
  - Milvus: `MILVUS_HOST`, `MILVUS_PORT`, `MILVUS_COLLECTION`, optional `MILVUS_TOKEN`
  - RAG/Responses (optional): `RAG_RESPONSES_MODEL`, `OPENAI_VECTOR_STORE_IDS`, `RAG_ASSISTANT_ID`, … (`.env.dist:~40–55`)
- Geheimnisse gehören in `.env.local` (nicht einchecken). In Doku und Logs keine Secret‑Werte ausgeben.
- Wichtige DI‑Bindings: `config/services.yaml:~60–130` (OpenAI‑Client, Milvus‑Client, Implementierungen der Interfaces)


## Build-Pipeline

- Composer Auto‑Scripts
  - `cache:clear` und `assets:install %PUBLIC_DIR%` werden nach Install/Update ausgeführt (`composer.json:~80–110`).
- Projektinterne Doku‑Generierung (optional)
  - `composer docs:generate` erzeugt Rohdaten unter `docs/_generated/` (Router, Container, Autowiring, Tests, PHPStan) (`composer.json:~90–130`).
  - Alternativ Makefile: `make docs-generate` (gleiches Ziel) (`Makefile:~20–70`).
- Tests
  - PHPUnit: `bin/phpunit` (oder `vendor/bin/phpunit`) (`.github/workflows/ci.yml:~25–35`).
- Static Analysis (lokal/optional)
  - PHPStan konfiguriert (Level per Dist‑Config), Aufruf: `vendor/bin/phpstan analyse -c phpstan.dist.neon`.


## CI (GitHub Actions)

- Workflow: `.github/workflows/ci.yml`
  - Trigger: `pull_request`
  - Matrix: Ubuntu, PHP 8.2 (`setup-php@v2`)
  - Schritte:
    - Checkout
    - Composer Cache
    - `composer install --no-progress --prefer-dist --optimize-autoloader`
    - Tests: `bin/phpunit`
  - Hinweise:
    - Keine externen Dienste (Milvus/OpenAI) werden im CI gestartet; Unit‑Tests sollten ohne diese Abhängigkeiten laufen.


## Deployment (Pragmatiker‑Pfad)

- Zielumgebung vorbereiten
  - Webserver auf `public/` terminieren (nginx/apache, PHP‑FPM 8.2+)
  - Schreibrechte für `var/` (Cache/Logs)
  - Milvus erreichbar (Network/Firewall)
- Build am Ziel (oder in CI‑Artifact)
  - `composer install --no-dev --prefer-dist --optimize-autoloader`
  - (optional) `php bin/console cache:clear --env=prod`
  - (optional) `php bin/console assets:install --env=prod`
- Konfiguration setzen
  - Env via Prozess/Server (z. B. systemd, php‑fpm pool, .env.prod.local)
  - Muss‑Werte: `APP_ENV=prod`, `APP_SECRET`, `APP_API_KEY`, `OPENAI_API_KEY`, `OPENAI_MODEL`, `MILVUS_HOST`, `MILVUS_PORT`, `MILVUS_COLLECTION`
  - Optional: `OPENAI_API_BASE`, `OPENAI_STT_MODEL`, RAG‑Variablen
- Warmup/Smoke
  - Health: `GET /healthstatus` (Embedding‑Service) (`src/Controller/EmbeddingController.php:~80–100`)
  - Dimension: `GET /dimension`
  - Test‑Suche (Konsole): `php bin/console app:test-search "waterproof smartphone"`
  - Attu (falls vorhanden) prüfen


## Betriebsnotizen

- Dimensionen: Milvus‑Collection muss zur Embedding‑Dimension passen (`OpenAIEmbeddingService::getVectorDimension`) — bei Modellwechsel Collection neu anlegen (`MilvusVectorStoreService::createCollection`).
- Threshold‑Vergleich vereinheitlichen (Inkonsistenz `>= 0.5` vs `<= 0.5`) bevor produktive Relevanzgrenzen festgelegt werden.
- Sicherheit: Alle API‑Routes benötigen gültigen `X-API-Key`; Web‑UI setzt Cookie `api_key` (Subscriber: `src/EventSubscriber/ApiKeySubscriber.php`).


<!-- STEP DONE: 03/13 -->

