# Produktfinder mit GenAI und Symfony

[English](README.md) | Deutsch

[![Doku](https://img.shields.io/badge/doku-verfügbar-blue.svg)](docs/01-Projekt-Beschreibung.md)
[![CI](https://img.shields.io/badge/CI-GitHub%20Actions-blue?logo=githubactions)](.github/workflows/ci.yml)
[![Postman](https://img.shields.io/badge/Postman-collection-orange?logo=postman)](docs/api/postman_collection.json)

Diese Symfony‑Anwendung ermöglicht natürlichsprachliche Produktsuche über KI‑gestütztes semantisches Verständnis. Produktdaten (XML/JSON) werden eingelesen, mit OpenAI als Vektoren eingebettet und in Milvus gespeichert. Text‑, Bild‑ und Audioeingaben werden unterstützt; ein Chat‑Modell erzeugt darauf basierend eine knappe Produktempfehlung.

## Features

- Produkte aus XML oder JSON importieren
- OpenAI‑Embeddings für Produkte und Suchanfragen erzeugen
- Vektoren in Milvus speichern und nach Ähnlichkeit durchsuchen (COSINE)
- Natürlichsprachliche Suche per API (Text, Bild→Vision‑Beschreibung, Audio→Whisper/STT)
- Prompt‑gesteuerte Empfehlung mit der OpenAI Chat API
- Web‑UI mit HttpOnly API‑Key‑Cookie‑Bridge
- Flexible Konfiguration über Umgebungsvariablen

## Tech‑Stack

- **Symfony 6.4** (PHP 8.2+)
- **OpenAI API**: Embeddings (Text), Chat (Empfehlung), Vision (Bildbeschreibung), Whisper (STT)
- **Milvus** (über `helgesverre/milvus`) für Vektor‑Suche
- **DDEV** für lokale Entwicklung (inkl. Milvus/Attu)
- **PHPUnit**, optional **PHPStan**

## Architektur (Überblick)

1. **Controller**
   - `ApiSearchController`: `/api/search/text|image|audio`
   - `ProductImportController`: `/api/products` (JSON‑Import), `DELETE /api/products/{id}`
   - `EmbeddingController`: `/text-embedding`, `/image-embedding`, `/dimension`, `/healthstatus`
   - `ProductFinderController`: `/api/products/chat`
   - `WebInterfaceController`: Web‑UI (`/`, `/search*`)
2. **Services**
   - `OpenAIEmbeddingService`: Text‑Embeddings, Vision‑Beschreibung, Query/Product‑Vektoren
   - `MilvusVectorStoreService`: Collection‑Init, Insert, Delete, Vector‑Search
   - `OpenAISearchService`: Chat‑Completion für Empfehlung
   - `OpenAISpeechToTextService`: Whisper‑Transkription
   - `XmlImportService` / `JsonImportService`: Parser → `Product`
   - `PromptService`: lädt Prompt‑Vorlagen (YAML)
3. **Entity/DTOs**
   - `Product` (Plain PHP Entity für die Vektorisierung)
   - Request/Response‑DTOs mit OpenAPI‑Attributen (z. B. `ChatRequestDto`, `ChatResponseDto`)

### Suchablauf

1) Nutzer sendet Text (oder Bild/Audio)
2) Vektorisierung mit OpenAI (Bild→Vision‑Beschreibung; Audio→Whisper)
3) Vektor‑Ähnlichkeitssuche in Milvus
4) Optionale Threshold‑Filterung (siehe Doku)
5) Prompt‑basierte Chat‑Completion erzeugt eine knappe Empfehlung
6) API liefert Empfehlung + Trefferliste

## Installation

### Voraussetzungen

- PHP 8.2+, Composer
- DDEV (empfohlen für lokales Setup)

### Lokales Setup mit DDEV

1) Repo klonen

```
git clone <REPO_URL>
cd symfony-product-finder
```

2) Abhängigkeiten installieren

```
ddev composer install
```

3) `.env.local` konfigurieren (Beispiele, keine Secrets committen)

```
OPENAI_API_KEY=your_openai_api_key
OPENAI_MODEL=text-embedding-3-small
OPENAI_MODEL_IMAGE=gpt-4o
OPENAI_CHAT_MODEL=gpt-3.5-turbo
OPENAI_STT_MODEL=whisper-1
DEBUG_VECTORS=false
APP_API_KEY=choose_a_secret_key
MILVUS_HOST=http://milvus
MILVUS_PORT=19530
MILVUS_COLLECTION=products
MILVUS_TOKEN=
```

4) Starten

```
ddev start
```

## Nutzung

### Produkte importieren (XML)

```
ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml
```

### Einzelnes Produkt per API importieren

POST `/api/products` mit JSON wie z. B.:

```json
{
  "id": 6,
  "title": "Google Pixel 8 Pro",
  "sku": "GOOPIX8P-128-BLU",
  "description": "...",
  "brand": "Google",
  "category": "Smartphones",
  "price": 1099.00,
  "image_url": "https://example.com/images/pixel-8-pro.jpg",
  "rating": 4.7,
  "stock": 28,
  "specifications": {
    "display": "6,7 Zoll LTPO OLED, 2992 x 1344 Pixel",
    "processor": "Google Tensor G3"
  },
  "features": ["Face Unlock", "Wireless Charging"]
}
```

### Suche testen

```
ddev php bin/console app:test-search "I need a waterproof smartphone"
```

### Bild verarbeiten

```
ddev php bin/console app:process-image path/to/image.jpg
```

### Audio verarbeiten

```
ddev php bin/console app:process-audio path/to/audio.webm
```

### Web‑Interface

`https://symfony-product-finder.ddev.site/`

### API‑Authentifizierung

- Alle API‑Endpoints erwarten den Header `X-API-Key` mit dem Wert aus `APP_API_KEY`.
- Beim Aufruf von `/` (Web‑UI) wird ein HttpOnly‑Cookie `api_key` gesetzt, sodass nachfolgende AJAX‑Requests den Key nicht im Browser offenlegen.

### Embedding‑Service API

- `GET /dimension`
- `POST /text-embedding` → `{ "texts": ["hello"] }`
- `POST /image-embedding` → multipart `file`
- `GET /healthstatus`

### Search API

- `POST /api/search/text` → `{ "message": "smartphone" }`
- `POST /api/search/image` → multipart `image`
- `POST /api/search/audio` → multipart `audio`
- Zusätzlich: `POST /api/products/chat` (chat‑basierte Empfehlung)

## Dokumentation

- Projektüberblick: `docs/01-Projekt-Beschreibung.md`
- Systemarchitektur: `docs/02-Systemarchitektur.md`
- Build & Deployment: `docs/03-Build-&-Deployment.md`
- Umgebung & Konfiguration: `docs/04-Umgebung-&-Konfiguration.md`
- Datenmodell: `docs/05-Datenmodell.md`
- Controller & Endpoints: `docs/06-Controller-&-REST-Endpoints.md`
- Domain‑Services & Abhängigkeiten: `docs/07-Domain-Services-&-Abhängigkeiten.md`
- Module & Bundles: `docs/08-Module-&-Bundles.md`
- Fehlerbehandlung & Logging: `docs/09-Fehlerbehandlung-&-Logging.md`
- Security & Auth: `docs/10-Security-&-Auth.md`
- Tests & Qualität: `docs/11-Tests-&-Qualitätssicherung.md`
- Operative Runbooks: `docs/12-Operative-Runbooks.md`
- Glossar: `docs/13-Glossar.md`
