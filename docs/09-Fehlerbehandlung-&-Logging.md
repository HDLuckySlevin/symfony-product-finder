# Fehlerbehandlung & Logging

Überblick über Exception-Strategien, Validierung, HTTP-Fehlercodes und das Logging-Setup (Monolog-Channels, Formatter, Handler).

Belegstellen (Auswahl)
- Controller: `src/Controller/ApiSearchController.php:~40–160, ~160–320`, `src/Controller/EmbeddingController.php:~20–115`, `src/Controller/ProductImportController.php:~40–220`, `src/Controller/ProductFinderController.php:~20–160`, `src/Controller/WebInterfaceController.php:~60–220`
- Services: `src/Service/OpenAIEmbeddingService.php:~90–170, ~200–230`, `src/Service/OpenAISearchService.php:~60–130`, `src/Service/OpenAISpeechToTextService.php:~55–110`, `src/Service/MilvusVectorStoreService.php:~80–170, ~300–500`, `src/Service/PromptService.php:~50–85`
- Security: `src/EventSubscriber/ApiKeySubscriber.php:~30–85`
- Monolog: `config/packages/monolog.yaml:~1–120`
- DI (Channel-spezifische Logger): `config/services.yaml:~120–200`


## Fehlerbehandlung (Controller)

- Allgemein
  - Eingaben werden validiert (z. B. leere Texte, Dateitypen/-größen) und bei Fehlern konsistente HTTP-Codes zurückgegeben.
  - Fehlerdetails werden geloggt; JSON-Antworten enthalten nur hilfreiche, nicht vertrauliche Meldungen.
- Textsuche (`/api/search/text`)
  - Validierung: leere Nachricht → `400` (`ApiSearchController::searchText`, `src/Controller/ApiSearchController.php:~140–155`).
- Bildsuche (`/api/search/image`)
  - Validierung: fehlende Datei, ungültiger MIME-Type → `400` (`src/Controller/ApiSearchController.php:~170–200`).
  - Vision/Embedding-Fehler werden im Service geloggt; Controller liefert reguläre Erfolgs-/Fehlerantwort basierend auf Ergebnis.
- Audiosuche (`/api/search/audio`)
  - Validierung: fehlende Datei → `400`; Transkriptionsfehler → `500` (`src/Controller/ApiSearchController.php:~240–310`).
- Embedding-Service
  - `/text-embedding`: ungültiges JSON/keine Texte → `400`; Exceptions → `500` mit geloggter Exception (`src/Controller/EmbeddingController.php:~25–60`).
  - `/image-embedding`: fehlende/ungültige Datei → `400`; Exceptions → `500` (`src/Controller/EmbeddingController.php:~60–95`).
- Produktimport (HTTP)
  - Ungültiges JSON → `400`; fachliche Fehler (z. B. fehlendes `sku` oder OpenAI-Konfiguration) → `400`; unerwartete Exceptions → `500` (`src/Controller/ProductImportController.php:~60–220`).
- Produktfinder (Chat)
  - Exceptions im Suchfluss werden abgefangen und als `500` mit generischer Fehlermeldung ausgegeben (`src/Controller/ProductFinderController.php:~110–160`).
- Web-UI
  - Validiert Query-Längen, Datei-MIME/Größe; gibt `{ success, output }` zurück, protokolliert Fehler (`src/Controller/WebInterfaceController.php:~60–220`).
- Auth/Access
  - Ungültiger/missing API-Key → `401 {"message":"Invalid API key"}` via Subscriber (`src/EventSubscriber/ApiKeySubscriber.php:~60–85`).


## Fehlerbehandlung (Services)

- OpenAIEmbeddingService
  - Embeddings: Exceptions werden geloggt und als `RuntimeException('Failed to create text embeddings')` geworfen (`src/Service/OpenAIEmbeddingService.php:~120–160`).
  - Vision: Exceptions werden geloggt und als `RuntimeException('OpenAI image description failed: ...')` propagiert (`src/Service/OpenAIEmbeddingService.php:~145–230`).
- OpenAISearchService
  - Chat-Aufrufe: Responses validiert; bei ungültiger Struktur wird geloggt und `RuntimeException` geworfen (`src/Service/OpenAISearchService.php:~90–130`).
- OpenAISpeechToTextService
  - Rückgabe `null` bei Fehlern (Datei fehlt/Exception); Fehler werden geloggt (`src/Service/OpenAISpeechToTextService.php:~60–110`).
- MilvusVectorStoreService
  - Alle Operationen `try/catch` mit Logging; bei Fehlern wird `false`/`[]` zurückgegeben statt Exceptions (
    - `initializeCollection()`/`createCollection()` → bool (`src/Service/MilvusVectorStoreService.php:~90–170`)
    - `insertProducts()`/`insertProductChunks()`/`deleteProductVectors()` → bool (`src/Service/MilvusVectorStoreService.php:~170–340`)
    - `searchSimilarProducts()` → leeres Array bei Fehlern (`src/Service/MilvusVectorStoreService.php:~340–420`)
  ).
- PromptService
  - Fehlende Prompt-Keys → `InvalidArgumentException` (wird von aufrufenden Controllern nicht speziell behandelt) (`src/Service/PromptService.php:~60–85`).


## Logging-Konzept

- Log-Level-Nutzung
  - `info`: Start/Ende von Operationen, Kontext (Query, Vektor-Längen, Result-Counts).
  - `debug`: detaillierte Zwischenergebnisse (Filter-Counts, Bildbeschreibung, Rohdaten), optional Vektordaten bei `DEBUG_VECTORS=true` (`src/Service/OpenAIEmbeddingService.php:~135–155`).
  - `warning`: Validierungsfehler, keine Treffer, leere Ergebnisse.
  - `error`: API-/Client-Fehler, Exceptions, IO-/Netzwerkfehler.
- Strukturierte Felder
  - Einheitliche Keys wie `collection_name`, `result_count`, `product_id`, `payload`, `content_length`, `image_bytes` u. a. (über Services verstreut, z. B. Embeddings/Vision/Search/Vector‑Ops).
- Sensitive Daten
  - API-Keys werden nie geloggt; Prompts/Requests werden, wenn, nur in redigierter Form geloggt (z. B. Vision–Payload mit Platzhalter) (`src/Service/OpenAIEmbeddingService.php:~120–140`).


## Monolog-Konfiguration

- Channels
  - `rag`, `streaming`, `response_basic`, `response_assistant`, zusätzlich Standard‐`main` (`config/packages/monolog.yaml:~1–10`).
- Handler (dev/test/prod)
  - dev: `main`→File `%kernel.logs_dir%/%env%.log`, Console-Handler, separate Files pro Channel (`rag.log`, `streaming.log`, `response_basic.log`, `response_assistant.log`) (`config/packages/monolog.yaml:~5–40`).
  - test: `fingers_crossed`→`nested` File, separate Channel-Files (`config/packages/monolog.yaml:~40–80`).
  - prod: `fingers_crossed`→`nested` (`php://stderr`, JSON-Formatter), `deprecation` auf stderr (JSON), Channel-Files auf INFO (`config/packages/monolog.yaml:~80–120`).
- Channel-spezifische Logger via DI
  - Zuweisung in `config/services.yaml`: Controller/Services aus `src/Service/rag/*` und `src/Controller/rag/*` erhalten dedizierte Logger: `@monolog.logger.streaming`, `@monolog.logger.response_basic`, `@monolog.logger.response_assistant` (`config/services.yaml:~150–200`).


## Response-Formate bei Fehlern (Beispiele)

- API-Key ungültig → `401 {"message": "Invalid API key"}` (`src/EventSubscriber/ApiKeySubscriber.php:~70–85`).
- Embedding-Service (Text) → `400 {"error": "Invalid JSON payload"}` oder `400 {"error":"No texts provided"}`; bei Laufzeitfehlern `500 {"error": "..."}` (`src/Controller/EmbeddingController.php:~25–60`).
- Bildsuche (API) → `400 {"success": false, "message": "No image uploaded|Invalid image type"}` (`src/Controller/ApiSearchController.php:~170–205`).
- Audiosuche (API) → `400 {"success": false, "message": "No audio uploaded"}` bzw. `500 {"success": false, "message": "Transcription failed"}` (`src/Controller/ApiSearchController.php:~240–310`).
- Produktimport (HTTP) → `400/500 {"message": "..."}` je nach Exception-Klasse (`src/Controller/ProductImportController.php:~60–220`).
- Chat-basierte Suche (Controller) → `500` mit `ChatResponseDto(success=false, message='An error occurred during search: ...')` (`src/Controller/ProductFinderController.php:~120–160`).


## Operative Hinweise

- Logs liegen standardmäßig in `var/log/`. In Production werden Hauptlogs im JSON-Format an stderr geschrieben (Container-/Plattform-Logaufnahme), Channels in separate Files (`config/packages/monolog.yaml`).
- `DEBUG_VECTORS=true` erhöht Log‐Volumen (Embedding‐Vektoren). Nur in Dev/Diagnose setzen, niemals in Prod mit sensiblen Daten (`src/Service/OpenAIEmbeddingService.php:~70–90, ~135–155`).
- Fehlertoleranz Milvus: Services liefern bei Fehlern leere Ergebnisse/`false` zurück; aufrufende Layer sollten damit umgehen (z. B. „No results“ Texte über `PromptService`) (`src/Controller/ApiSearchController.php:~70–120`).


<!-- STEP DONE: 09/13 -->

