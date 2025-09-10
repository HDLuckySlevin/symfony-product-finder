# Domain-Services & Abhängigkeiten

Überblick über zentrale Services, deren Konstruktor-Abhängigkeiten und typische Aufrufketten. Quellen: `docs/_generated/container.txt`, `config/services.yaml`, `src/Service/*`, `src/Controller/*`.


## Wichtige Services (Auszug)

| Service-ID | Klasse | Konstruktor-Args | Scope | Verwendungen (Top 3) |
|---|---|---|---|---|
| `App\Service\OpenAIEmbeddingService` | `App\Service\OpenAIEmbeddingService` | `OpenAI\Client`, `Psr\Log\LoggerInterface`, `string $embeddingModel`, `string $imageModel`, `bool $debugVectors`, `string $imageDescriptionPrompt` (`config/services.yaml:~100–130`) | Container-Singleton | Aufrufer: `ApiSearchController::searchText` (via Interface), `ApiSearchController::searchImage` (Vision), `ImportProductsCommand` (Embeddings) |
| `App\Service\MilvusVectorStoreService` | `App\Service\MilvusVectorStoreService` | `Milvus\Client`, `Psr\Log\LoggerInterface`, `OpenAIEmbeddingService`, `string $collectionName` (`config/services.yaml:~85–110`) | Container-Singleton | Aufrufer: `ApiSearchController`, `ProductFinderController`, `ImportProductsCommand`, `ProductImportController` |
| `App\Service\OpenAISearchService` | `App\Service\OpenAISearchService` | `OpenAI\Client`, `Psr\Log\LoggerInterface`, `string $chatModel` (`config/services.yaml:~130–160`) | Container-Singleton | Aufrufer: `ApiSearchController`, `ProductFinderController`, `TestSearchCommand` |
| `App\Service\OpenAISpeechToTextService` | `App\Service\OpenAISpeechToTextService` | `OpenAI\Client`, `Psr\Log\LoggerInterface`, `string $model` (`config/services.yaml:~160–190`) | Container-Singleton | Aufrufer: `ApiSearchController::searchAudio`, `ProcessAudioCommand` |
| `App\Service\PromptService` | `App\Service\PromptService` | `ParameterBagInterface` (`src/Service/PromptService.php:~20–40`) | Container-Singleton | Aufrufer: `ApiSearchController`, `ProductFinderController`, `TestSearchCommand` |
| `App\Service\JsonImportService` | `App\Service\JsonImportService` | — | Container-Singleton | Aufrufer: `ProductImportController::importProduct` |
| `App\Service\EmbeddingGeneratorInterface` | Alias für `OpenAIEmbeddingService` (`docs/_generated/container.txt:~31–40`) | — | — | Aufrufer: Controller/Commands über Interface |
| `App\Service\SearchServiceInterface` | Alias für `OpenAISearchService` | — | — | Aufrufer: Controller/Commands |
| `App\Service\SpeechToTextServiceInterface` | Alias für `OpenAISpeechToTextService` | — | — | Aufrufer: `ProcessAudioCommand`, `ApiSearchController::searchAudio` |
| `App\Service\VectorStoreInterface` | Alias für `MilvusVectorStoreService` | — | — | Aufrufer: Controller/Commands |

Weitere DI-Factories/Clients:
- `Milvus\Client` via `App\Factory\MilvusClientFactory` (Arguments: `MILVUS_TOKEN`, `MILVUS_HOST`, `MILVUS_PORT`) (`config/services.yaml:~65–90`).
- `OpenAI\Client` via `OpenAI::client($apiKey)` (`config/services.yaml:~140–150`).


## Konstruktor-Abhängigkeiten (aus Code)

- OpenAIEmbeddingService (`src/Service/OpenAIEmbeddingService.php:~20–45`)
  - `Client $client`, `LoggerInterface $logger`, `string $embeddingModel`, `string $imageModel`, `bool $debugVectors`, `string $imageDescriptionPrompt`.
- MilvusVectorStoreService (`src/Service/MilvusVectorStoreService.php:~30–60`)
  - `MilvusClient $milvus`, `LoggerInterface $logger`, `OpenAIEmbeddingService $embeddingService`, `string $collectionName`.
- OpenAISearchService (`src/Service/OpenAISearchService.php:~25–45`)
  - `Client $client`, `LoggerInterface $logger`, `string $chatModel`.
- OpenAISpeechToTextService (`src/Service/OpenAISpeechToTextService.php:~18–30`)
  - `Client $client`, `LoggerInterface $logger`, `string $model`.
- PromptService (`src/Service/PromptService.php:~20–40`)
  - `ParameterBagInterface $parameterBag`.

Bemerkung: `MilvusVectorStoreService` bezieht die Dimension indirekt über `OpenAIEmbeddingService::getVectorDimension()`.


## Sequenzdiagramme

### Textsuche (Controller → Services → Externe Systeme)
```mermaid
sequenceDiagram
  participant C as ApiSearchController
  participant EG as EmbeddingGeneratorInterface
  participant VS as VectorStoreInterface
  participant PS as PromptService
  participant SS as SearchServiceInterface
  participant OA as OpenAI APIs
  participant MV as Milvus

  C->>EG: generateQueryEmbedding(query)
  EG->>OA: embeddings.create(model,input)
  EG-->>C: embedding vector
  C->>VS: searchSimilarProducts(vector,3)
  VS->>MV: vector.search(collection, vector, limit)
  VS-->>C: results[]
  C->>PS: getPrompt(system+user)
  C->>SS: generateChatCompletion(messages)
  SS->>OA: chat.create(model,messages)
  SS-->>C: recommendation
  C-->>Client: ChatResponseDto
```

### Bildsuche
```mermaid
sequenceDiagram
  participant C as ApiSearchController
  participant ES as OpenAIEmbeddingService
  participant VS as VectorStoreInterface
  participant PS as PromptService
  participant SS as SearchServiceInterface
  participant OA as OpenAI APIs
  participant MV as Milvus

  C->>ES: describeImageFile(file)
  ES->>OA: chat.create(vision) [image_url]
  ES-->>C: description
  ES->>OA: embeddings.create(text)
  ES-->>C: vector
  C->>VS: searchSimilarProducts(vector)
  VS->>MV: vector.search(...)
  VS-->>C: results[]
  C->>PS: getPrompt(...)
  C->>SS: generateChatCompletion(...)
  SS->>OA: chat.create(...)
  C-->>Client: ChatResponseDto
```

### Audiosuche
```mermaid
sequenceDiagram
  participant C as ApiSearchController
  participant STT as SpeechToTextService
  participant EG as EmbeddingGeneratorInterface
  participant VS as VectorStoreInterface
  participant PS as PromptService
  participant SS as SearchServiceInterface
  participant OA as OpenAI APIs
  participant MV as Milvus

  C->>STT: transcribe(tempPath)
  STT->>OA: audio.transcriptions
  STT-->>C: text
  C->>EG: generateQueryEmbedding(text)
  EG->>OA: embeddings.create
  EG-->>C: vector
  C->>VS: searchSimilarProducts(vector)
  VS->>MV: vector.search
  VS-->>C: results[]
  C->>PS: getPrompt
  C->>SS: generateChatCompletion
  SS->>OA: chat.create
  C-->>Client: ChatResponseDto
```

### Import (XML, Console)
```mermaid
sequenceDiagram
  participant Cmd as ImportProductsCommand
  participant XML as XmlImportService
  participant EG as EmbeddingGeneratorInterface
  participant VS as VectorStoreInterface
  participant OA as OpenAI APIs
  participant MV as Milvus

  Cmd->>XML: importFromFile(xml)
  XML-->>Cmd: products[]
  Cmd->>VS: initializeCollection()
  loop pro Produkt
    Cmd->>EG: generateProductEmbeddings(product)
    EG->>OA: embeddings.create
    Cmd->>VS: deleteProductVectors(id)
    Cmd->>VS: insertProductChunks(product, chunks)
    VS->>MV: vector.insert(...)
  end
  Cmd-->>Operator: success
```

### Import (Single JSON, HTTP)
```mermaid
sequenceDiagram
  participant C as ProductImportController
  participant J as JsonImportService
  participant EG as EmbeddingGeneratorInterface
  participant VS as VectorStoreInterface
  participant HC as HttpClient(OpenAI Files)

  C->>J: importFromArray(json)
  J-->>C: Product
  C->>VS: initializeCollection()
  C->>EG: generateProductEmbeddings(product)
  C->>VS: deleteProductVectors(id)
  C->>VS: insertProductChunks(product, chunks)
  C->>HC: POST /files (multipart JSON)
  C->>HC: POST /vector_stores/{id}/files (attach)
  C-->>Client: {success:true}
```


## Lebenszyklus & Scope

- Alle Services sind standardmäßig als Container‑Singletons registriert (`services._defaults.autoconfigure/autowire: true`).
- Zustandslos; Konfiguration über Env‑Variablen (DI) und pro‑Aufruf Daten.
- Externe Ressourcen: `OpenAI\Client` (HTTP), `Milvus\Client` (HTTP/gRPC, je nach Client‑Lib) werden injected und wiederverwendet.


<!-- STEP DONE: 07/13 -->

