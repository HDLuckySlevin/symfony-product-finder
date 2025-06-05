# Product Finder with GenAI and Symfony

This Symfony application enables the import of XML product data, the vectorization of products, and synchronization with Zilliz (a vector database). End users can find products that meet their needs through a natural language interface.

## Features

- Import of electronic products from XML files
- Vectorization of all product properties with OpenAI Embeddings
- Individual storage of product features and specifications in the vector database
- Storage and search in the Zilliz vector database
- Natural language product search via API
- Web interface with DeepChat for end users
- Flexible configuration for API keys and endpoints

## Technical Architecture

The application consists of the following main components:

1. **XML Import**: Reads product data from XML files and converts them into Product objects
2. **Embedding Generator**: Creates vector representations for products, product features, specifications, and search queries
3. **Zilliz Integration**: Stores and searches product vectors, feature vectors, and specification vectors in the vector database
4. **API Controller**: Provides REST endpoints for product search
5. **Web Interface**: Offers a user-friendly interface for end users

The core of the application is built upon the following key libraries and technologies:

- **Symfony Framework**: Provides the foundational structure for the application.
- **`helgesverre/milvus`**: Used for all interactions with the Milvus/Zilliz vector database, including storing and querying product vectors.
- **`openai-php/client`**: Enables the generation of text embeddings for products and search queries via the OpenAI API.

## Installation

### Prerequisites

- PHP 8.1 or higher
- Composer
- Symfony CLI (optional, for local development)

### Setup

1. Clone the repository:
   ```
   git clone [repository-url]
   cd symfony-product-finder
   ```

2. Install the dependencies:
   ```
   ddev composer install
   ```

3. Configure the environment variables in a `.env.local` file:
   ```
   OPENAI_API_KEY=your_openai_api_key
   OPENAI_EMBEDDING_MODEL=text-embedding-3-small
   MILVUS_API_KEY=your_zilliz_api_key
   MILVUS_HOST=your_zilliz_endpoint
   MILVUS_PORT=443
   MILVUS_COLLECTION=products
   ```

4. Start the Symfony server:
   ```
   ddev start
   ```

### Development with Gitpod

This project can be opened and used directly in Gitpod, an online IDE for GitHub.

[![Open in Gitpod](https://gitpod.io/button/open-in-gitpod.svg)](https://gitpod.io/#https://github.com/iGore/symfony-product-finder)

Gitpod automatically starts a fully functional development environment, including DDEV integration. After starting the workspace, DDEV is automatically started, and the application is accessible via the URL provided by Gitpod. The necessary ports (e.g., for the web server and Mailpit) are automatically opened.

You can use DDEV commands in the Gitpod terminal as usual (e.g., `ddev ssh`, `ddev logs`).

## Usage

### Importing Products

Use the following command to import products from an XML file:

```
ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml
```

During import, not only the products as a whole but also their individual features and specifications are stored as separate vectors in the database. This enables more precise search results and better matching of user queries to specific product properties.

The API keys are optional. If not provided, the application uses mock data for testing.

### Testing Product Search

Test the product search with the following command:

```
ddev php bin/console app:test-search "I'm looking for a waterproof smartphone with a good camera"
```

### Web Interface

Open the application in your browser at `https://symfony-product-finder.ddev.site/` and use the chat interface to find products.

## Customization

### Custom XML Structure

You can adapt the XML import logic in `src/Service/XmlImportService.php` to support your own XML structure.

### Other Embedding Providers

The application uses OpenAI for embeddings by default but can be easily switched to other providers. To do this, implement the `EmbeddingGeneratorInterface`. If you want to use another provider, you also need to implement the methods `generateFeatureEmbeddings` and `generateSpecificationEmbeddings` to create embeddings for individual product features and specifications.

### Zilliz Configuration

The Zilliz integration can be customized in `src/Service/ZillizVectorDBService.php` to meet specific requirements. You can adapt the methods `insertProductFeatures` and `insertProductSpecifications` to change how product features and specifications are stored in the vector database.

## Development

### Running Tests

```
ddev php bin/phpunit
```

### Adding New Features

1. Create new controllers in `src/Controller/`
2. Add new services in `src/Service/`
3. Extend the entities in `src/Entity/`

## License

This project is licensed under the MIT License.
