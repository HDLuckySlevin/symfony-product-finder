# Product Finder with GenAI and Symfony

This Symfony application enables natural language product search through AI-powered semantic understanding. It imports product data from XML, vectorizes product attributes using OpenAI embeddings, and stores them in Milvus vector database for efficient similarity search.

## Features

- Import electronic products from XML files
- Vectorize product properties with OpenAI Embeddings
- Store and search products in Milvus vector database
- Natural language product search via API
- Web interface with DeepChat for intuitive user interaction
- Flexible configuration for API keys and endpoints

## Technical Stack

- **Symfony 6.4**: Core application framework
- **PHP 8.2+**: Required runtime
- **OpenAI API**: For embeddings and chat completions
- **Milvus**: Vector database for similarity search
- **DDEV**: Local development environment
- **Gitpod**: Cloud development environment

## Architecture

The application follows a service-oriented architecture with key components organized into controllers, services, and entities.

### Key Components

1. **Controllers**:
   - `ProductFinderController`: Handles product search API endpoints
   - `WebInterfaceController`: Manages the web interface

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
2. Query is vectorized using OpenAI embeddings
3. Vector search finds similar products in Milvus
4. Results are filtered by relevance threshold (distance ≤ 0.5)
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

3. Configure environment variables in `.env.local`:
   ```
  OPENAI_API_KEY=your_openai_api_key
  OPENAI_MODEL=text-embedding-3-small
  OPENAI_MODEL_IMAGE=gpt-4o
  OPENAI_CHAT_MODEL=gpt-3.5-turbo
  DEBUG_VECTORS=false
  MILVUS_API_KEY=your_milvus_api_key
   MILVUS_HOST=your_milvus_endpoint
   MILVUS_PORT=443
   MILVUS_COLLECTION=products
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

### Web Interface

Access the chat interface at `https://symfony-product-finder.ddev.site/` to search for products using natural language.

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
- `POST /api/search/audio` – upload an audio file (field name `audio`) which is
  transcribed and used as the search query.

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

### Project Structure

- **Controllers**: `src/Controller/` - API endpoints and web interface
- **Services**: `src/Service/` - Business logic and integrations
- **Entities**: `src/Entity/` - Data models
- **DTOs**: `src/DTO/` - Data transfer objects for API requests/responses

## License

This project is licensed under the MIT License.
