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
   OPENAI_EMBEDDING_MODEL=text-embedding-3-small
   OPENAI_CHAT_MODEL=gpt-3.5-turbo
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

The import process vectorizes both complete products and their individual features/specifications, enabling precise semantic matching.

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
