# Glossar

Kurze Erläuterungen projektspezifischer Begriffe und Abkürzungen.

- API-Key
  - Geheimer Schlüssel, der per Header `X-API-Key` oder HttpOnly‑Cookie `api_key` übermittelt wird und den Zugriff auf geschützte Endpoints erlaubt (`APP_API_KEY`).
- Attu
  - Web‑UI für Milvus (Vektordatenbank) zur Inspektion von Collections und Vektoreinträgen.
- Embedding
  - Vektorielle Repräsentation von Text/Bildinhalten zur semantischen Suche. Erzeugt via OpenAI‑Embeddings; Dimension abhängig vom Modell (z. B. 1536).
- Milvus
  - Vektordatenbank für Ähnlichkeitssuche (COSINE‑Metrik); speichert Produkt‑Vektoren (Collection) und ermöglicht `search`, `insert`, `delete`.
- OpenAI (Client)
  - Externe API für Embeddings (Text), Chat (Empfehlungen), Vision (Bildbeschreibung via Chat), Whisper (Speech‑to‑Text).
- Product Finder (Controller)
  - Chat‑basierter Such‑Controller, der Prompts nutzt und ein LLM für die finale Produktempfehlung anspricht.
- PromptService
  - Lädt Prompts aus `config/prompts.yaml` und ersetzt Platzhalter (`%query%`, `%products_list%`).
- RAG / Responses / Assistants
  - Optionale Integrationen für OpenAI Responses/Assistants; zusätzliche Routen unter `/rag/*` zu Demo‑/Experimentzwecken.
- Similarity / Distance
  - Rückgabewert der Milvus‑Suche; in Code teils als `distance` bezeichnet, teils als Ähnlichkeit interpretiert. Schwellenlogik (`>= 0.5` vs. `<= 0.5`) konsolidieren.
- VectorStore
  - Service‑Abstraktion über Milvus für Collection‑Init, Insert, Delete, Search.
- Vision
  - Bildbeschreibung mit multimodalem Chat‑Modell (z. B. `gpt-4o`); Ergebnis wird für Embeddings genutzt.
- Whisper (STT)
  - Speech‑to‑Text‑Modell (z. B. `whisper-1`) zur Transkription von Audioeingaben.


<!-- STEP DONE: 13/13 -->

