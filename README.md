# Product Finder with GenAI and Symfony

English | [Deutsch](README.de.md)

[![Docs](https://img.shields.io/badge/docs-available-blue.svg)](docs/01-Projekt-Beschreibung.md)
[![CI](https://img.shields.io/badge/CI-GitHub%20Actions-blue?logo=githubactions)](.github/workflows/ci.yml)
[![Postman](https://img.shields.io/badge/Postman-collection-orange?logo=postman)](docs/api/postman_collection.json)

This Symfony application enables natural-language product search via AI-powered semantic understanding. It imports product data (XML/JSON), generates embeddings with OpenAI, and stores them in Milvus for efficient similarity search. Text, image, and audio inputs are supported; a chat model generates concise product recommendations.

## Features

- Import products from XML or JSON
- Generate OpenAI embeddings for products and queries
- Store and search vectors in Milvus (COSINE)
- Natural-language search via API (text, image→vision, audio→STT)
- Prompt-driven recommendation with OpenAI Chat API
- Web UI with HttpOnly API-key cookie bridge
- Flexible configuration via environment variables

## Technical Stack

- **Symfony 6.4** (PHP 8.2+)
- **OpenAI API**: Embeddings (text), Chat (recommendation), Vision (image description), Whisper (STT)
- **Milvus** (via `helgesverre/milvus`) for vector similarity
- **DDEV** for local dev (Milvus/Attu)
- **PHPUnit**, optional **PHPStan**

## Architecture

The application follows a service-oriented architecture with key components organized into controllers, services, and entities.

### Key Components

1. **Controllers**:
   - `ApiSearchController`: `/api/search/text|image|audio`
   - `ProductImportController`: `/api/products` (JSON import), `DELETE /api/products/{id}`
   - `EmbeddingController`: `/text-embedding`, `/image-embedding`, `/dimension`, `/healthstatus`
   - `ProductFinderController`: `/api/products/chat`
   - `WebInterfaceController`: Web UI (`/`, `/search*`)

2. **Services**:
   - `XmlImportService`: Parses XML files and extracts product data
   - `OpenAIEmbeddingGenerator`: Generates vector embeddings using OpenAI
   - `MilvusVectorStoreService`: Manages vector database interactions
   - `OpenAISearchService`: Generates natural language recommendations
   - `PromptService`: Manages prompts for OpenAI chat models

3. **Entities**:
   - `Product`: Represents products with properties, features, and specifications

### Search Flow

1. User submits natural language query
2. Query is vectorized using OpenAI embeddings (image→vision description; audio→Whisper)
3. Vector search finds similar products in Milvus
4. Optional threshold filtering (see docs)
5. OpenAI generates natural language recommendations based on results
6. User receives product recommendations and matching products

For detailed architecture diagrams, see the [Architecture Documentation](https://github.com/iGore/symfony-product-finder/wiki/Architecture).

## Installation

### Prerequisites

- PHP 8.2 or higher
- Composer
- DDEV (recommended for local development)

### Local Setup with DDEV

1. Clone the repository:
   ```
   git clone git@github.com:iGore/symfony-product-finder.git
   cd symfony-product-finder
   ```

2. Install dependencies:
   ```
   ddev composer install
   ```

3. Configure environment variables in `.env.local` (examples, do not commit secrets):
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

4. Start the application:
   ```
   ddev start
   ```

### Cloud Development with Gitpod

For quick development without local setup, use Gitpod:

[![Open in Gitpod](https://gitpod.io/button/open-in-gitpod.svg)](https://gitpod.io/#https://gitlab.adesso-group.com/Igor.Besel/symfony-product-finder)

Gitpod provides a ready-to-use environment with DDEV pre-configured. The application is automatically started and accessible via the URL provided by Gitpod.

## Usage

### Importing Products

Import sample products from XML:

```
ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml
```

The import process vectorizes each product as a single chunk for semantic search.

### Importing a Single Product via API

A single product can also be submitted as JSON and will be vectorized in the same way. Send a `POST` request to `/api/products` with the product data:

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

The endpoint creates one chunk for the product, vectorizes it and stores it in the database. It responds with `200 OK` on success or an error message if something goes wrong.
If a product with the same `id` already exists in Milvus, the old vectors are removed before the new ones are stored. This ensures that re-importing the same product replaces the existing entry instead of creating duplicates.

### Testing Search

Try the natural language search:

```
ddev php bin/console app:test-search "I need a waterproof smartphone with a good camera"
```

### Processing an Image

Send an image to the embedding service. The returned text description is
automatically used as the query for `app:test-search`:

```
ddev php bin/console app:process-image path/to/image.jpg
```

### Processing Audio

Transcribe an audio file (Whisper) and use the text for search:

```
ddev php bin/console app:process-audio path/to/audio.webm
```

### Web Interface

Access the chat interface at `https://symfony-product-finder.ddev.site/` to search for products using natural language.

### API Authentication

All API endpoints require an `X-API-Key` header containing the value of `APP_API_KEY` defined in your environment. Requests without a valid key will be rejected with `401 Unauthorized`.
When you open the web interface at `/`, the application issues the API key as an HTTP-only cookie so that subsequent AJAX requests can access the protected API without exposing the key in the browser.

### Embedding API

The application exposes a small API for generating embeddings directly:

- `GET /dimension` – returns the vector dimension of the current model.
- `POST /text-embedding` – send `{ "texts": ["hello"] }` and receive embedding vectors.
- `POST /image-embedding` – upload an image file to receive a description and vector.
- `GET /healthstatus` – simple health check of the embedding service.

### Search API

Product search can also be accessed via JSON endpoints which mirror the
functionality of the web interface:

- `POST /api/search/text` – send `{ "message": "smartphone" }` and receive search
  results.
- `POST /api/search/image` – upload an image file (field name `image`) to search
  based on its description.
- `POST /api/search/audio` – upload an audio file (field name `audio`) which is transcribed and used as the search query.

Additional:

- `POST /api/products/chat` – chat-style search that returns a recommendation based on similar products.

Further examples are available in [docs/API.md](docs/API.md). A sanitized Postman collection can be found in [docs/api/postman_collection.json](docs/api/postman_collection.json). See also the documentation section below.


## Customization

### Extending the Application

- **Custom XML Format**: Modify `XmlImportService.php` to support different XML structures
- **Alternative Embedding Providers**: Implement `EmbeddingGeneratorInterface` to use different vector providers
- **Vector Database Configuration**: Customize `MilvusVectorStoreService.php` for specific vector storage needs

## Development

### Testing

Run the test suite:

```
ddev php bin/phpunit
```

## Documentation

- Project overview: `docs/01-Projekt-Beschreibung.md`
- Architecture: `docs/02-Systemarchitektur.md`
- Build & deployment: `docs/03-Build-&-Deployment.md`
- Environment & configuration: `docs/04-Umgebung-&-Konfiguration.md`
- Data model: `docs/05-Datenmodell.md`
- Controllers & endpoints: `docs/06-Controller-&-REST-Endpoints.md`
- Domain services & dependencies: `docs/07-Domain-Services-&-Abhängigkeiten.md`
- Modules & bundles: `docs/08-Module-&-Bundles.md`
- Error handling & logging: `docs/09-Fehlerbehandlung-&-Logging.md`
- Security & auth: `docs/10-Security-&-Auth.md`
- Tests & quality: `docs/11-Tests-&-Qualitätssicherung.md`
- Operational runbooks: `docs/12-Operative-Runbooks.md`
- Glossary: `docs/13-Glossar.md`

### Project Structure

- **Controllers**: `src/Controller/` - API endpoints and web interface
- **Services**: `src/Service/` - Business logic and integrations
- **Entities**: `src/Entity/` - Data models
- **DTOs**: `src/DTO/` - Data transfer objects for API requests/responses

## License

This project is licensed under the MIT License.
