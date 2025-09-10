# Operative Runbooks

Praktische Ablaufrezepte für Betrieb/Support: typische Aufgaben, schnelle Prüfungen, sowie Monitoring/Alarming‑Hinweise.


## Häufige Aufgaben

- Dienste starten (lokal, DDEV)
  - `ddev start`
- Abhängigkeiten installieren
  - `ddev composer install`
- Caches leeren (bei Bedarf)
  - `ddev php bin/console cache:clear` (oder lokal `php bin/console cache:clear`)
- Logs ansehen
  - `var/log/dev.log` (dev) bzw. `var/log/prod.log` und Channel‑Logs (`rag.log`, `streaming.log`, ...)
- Sample‑Daten importieren (XML)
  - `ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml`
- Suche lokal testen (End‑to‑End)
  - `ddev php bin/console app:test-search "I need a waterproof smartphone"`
- Bild verarbeiten (Vision → Suche)
  - `ddev php bin/console app:process-image path/to/image.jpg`
- Audio verarbeiten (Whisper → Suche)
  - `ddev php bin/console app:process-audio path/to/audio.webm`


## Health & Quick Checks

- Embedding‑Service erreichbar
  - `GET /healthstatus` → `{ status: 'It works', provider: 'openai' }`
  - `GET /dimension` → Modell‑abhängige Zahl (z. B. 1536)
- API‑Schutz (API‑Key)
  - Request ohne `X-API-Key` gegen `/api/search/text` muss `401` liefern
  - Web‑UI (`/`) setzt HttpOnly‑Cookie `api_key`
- Milvus Verfügbarkeit
  - Import/Suche nicht erfolgreich? Checke `.env(.local)` Einträge `MILVUS_HOST/PORT/COLLECTION/TOKEN`
  - Attu (falls eingerichtet) öffnen und Collection prüfen


## Konfigurationsänderungen

- Embedding‑Modell wechseln
  - `.env(.local)` → `OPENAI_MODEL` anpassen.
  - Dimension ändert sich ggf.; Collection neu anlegen: `dropCollection()` (eigener Maintenance‑Pfad/Command empfohlen) → Daten neu importieren.
- Collection‑Name wechseln
  - `.env(.local)` → `MILVUS_COLLECTION` anpassen.
  - Bestehende Daten bleiben in alter Collection; Import in neue Collection durchführen.


## Fehlerbilder & Mitigation

- „No results“ bei Suche
  - Prüfe, ob Produkte importiert wurden und Embeddings existieren (Logs/Attu).
  - Prüfe Threshold‑Logik (Inkonsistenz `>= 0.5` vs. `<= 0.5` in Controllern) und harmonisiere.
- 401 Unauthorized
  - `APP_API_KEY` korrekt gesetzt? Header‑Name exakt `X-API-Key`? Web‑UI setzt Cookie nur auf `/`.
- Vision/Embeddings schlagen fehl
  - Check `OPENAI_API_KEY` und Model‑Parameter; Netzwerkzugriff; Log‑Einträge `openai.*` prüfen.
- Milvus Fehler bei Insert/Search
  - Host/Port/Token prüfen; Client‑Konfiguration `Milvus\Client` (Factory) und Logs `collection_name`, `result_count`.


## Wartungsmodus & Sicherheit

- Wartungsfenster: vor Modell/Collection‑Wechsel ankündigen; Daten neu indexieren.
- Secrets drehen/wechseln: `APP_API_KEY` und ggf. `OPENAI_API_KEY` rotieren; Web‑UI‑Cookie erneuert sich beim nächsten Aufruf von `/`.
- Logs
  - In Prod JSON an stderr für zentrale Sammlung; Channels in Files (prüfen, ob Container‑Filesystem persistent ist oder externe Log‑Aggregation verwenden).


## Monitoring/Alarming (Empfehlungen)

- HTTP Healthchecks
  - `/healthstatus` (Embedding), ggf. leichter Dummy‑Suchrequest
- Fehlerquoten
  - Anteil `error` Logs in `var/log/*.log`
- Latenzen
  - Time to first byte bei `/api/search/*` (OpenAI und Milvus Roundtrips)
- Ressourcen
  - Milvus Verfügbarkeit und Speichernutzung (über Attu/Monitoring)
  - OpenAI Rate Limits/Quota (über Anbieter‑Dashboard)


<!-- STEP DONE: 12/13 -->

