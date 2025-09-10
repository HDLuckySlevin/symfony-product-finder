# Security & Auth

Überblick über Authentifizierung, Autorisierung, Event-Hooks, CSRF/CORS und sichere Defaults. Die App verwendet kein SecurityBundle und keine Benutzerrollen – der Zugriffsschutz erfolgt über einen einfachen API‑Key‑Mechanismus.

Belegstellen (Auswahl)
- API‑Key‑Enforcement: `src/EventSubscriber/ApiKeySubscriber.php:~1–120`, `config/services.yaml:~15–45`
- API‑Key‑Cookie (Web‑UI Bridge): `src/Controller/WebInterfaceController.php:~30–55`
- Öffentliche vs. geschützte Pfade: `config/services.yaml:~25–45`
- Env‑Variablen (Keys/Modelle): `.env(.dist):~20–55`, `config/services.yaml:~90–170`
- Fehlerinformationen/HTTP‑Codes: Controller in `src/Controller/*`


## Authentifizierung

- API‑Key Header
  - Alle API‑Endpoints verlangen `X-API-Key`, dessen Wert mit `APP_API_KEY` übereinstimmt.
  - Implementiert als Kernel‑Request‑Subscriber: `ApiKeySubscriber` prüft Header, alternativ Cookie, und antwortet bei Missmatch mit `401` (`src/EventSubscriber/ApiKeySubscriber.php:~48–85`).
- API‑Key Cookie (Web‑UI)
  - Web‑Startseite `/` setzt ein HttpOnly‑Cookie `api_key` mit dem konfigurierten Key, damit die Browser‑UI geschützte APIs aufrufen kann, ohne den Key im JS offen zu legen (`src/Controller/WebInterfaceController.php:~40–55`).
  - Hinweis: In Produktion sollte das Cookie zusätzlich `Secure` und `SameSite` erhalten (z. B. `->withSecure(true)->withSameSite('lax')`).


## Autorisierung (Rollen/Rechte)

- Keine Rollen/Voter/ACLs vorhanden (kein `security.yaml`, kein SecurityBundle). Zugriff wird ausschließlich per Geheimnis (API‑Key) gewährt oder verweigert.
- Fein granularer Zugriff (pro Benutzer/Rolle) ist nicht implementiert.


## Security‑Events & Subscriber

- `ApiKeySubscriber` (KernelEvents::REQUEST, hohe Priorität)
  - Überspringt Prüfung für bestimmte Pfade (siehe „Öffentliche Pfade“), sonst vergleicht `X-API-Key` bzw. Cookie `api_key` gegen `APP_API_KEY`.
  - Bei Fehler: beendet Request früh mit `401 Invalid API key` (`src/EventSubscriber/ApiKeySubscriber.php:~60–85`).


## Öffentliche vs. geschützte Pfade

- Öffentliche Pfade (vom API‑Key‑Check ausgenommen):
  - `/`, `/search`, `/search/image`, `/search/audio` (`config/services.yaml:~25–45`).
  - Dienen der Web‑UI und rufen intern Console‑Kommandos auf; nehmen Eingaben (Text/Bild/Audio) entgegen.
- Geschützte Pfade (API‑Key erforderlich):
  - Alle APIs unter `/api/*` (Suche, Import, RAG) und die Embedding‑Utility‑Routen (`/text-embedding`, `/image-embedding`, `/dimension`, `/healthstatus`).


## CSRF

- Kein SecurityBundle/CSRF‑System aktiv. Die öffentlichen POST‑Endpoints (`/search*`) haben keinen CSRF‑Token‑Schutz.
- Bewertung: Die Endpoints ändern keinen persistenten Zustand (führen Suchen aus) – Risiko primär Server‑Last/Abuse. Für striktere Policies kann ein CSRF‑Token oder eine zusätzliche Prüfgröße (z. B. Token im Web‑UI) ergänzt werden.


## CORS

- Kein CORS‑Bundle/keine CORS‑Konfiguration vorhanden. Standard: gleiche Origin.
- Web‑UI und APIs werden i. d. R. von derselben Domain (DDEV) bedient – keine Cross‑Origin‑Aufrufe nötig. Bei externen Frontends wäre CORS (z. B. NelmioCorsBundle) zu konfigurieren.


## Upload‑Härtung & Eingabevalidierung

- Bildsuche (API): MIME‑Whitelist `image/jpeg|png|gif|webp` (`src/Controller/ApiSearchController.php:~185–200`).
- Bildsuche (Web‑UI): MIME‑Whitelist und Größenlimit 5 MB (`src/Controller/WebInterfaceController.php:~16–25, ~110–135`).
- Audiosuche (Web‑UI): Größenlimit 5 MB, MIME‑Check (audio/* bzw. video/webm) (`src/Controller/WebInterfaceController.php:~22–25, ~160–190`).
- Audiosuche (API): prüft Vorhandensein, akzeptiert audio/* oder video/webm; kein Größenlimit auf API‑Pfad – Empfehlung: ein Limit ergänzen (`src/Controller/ApiSearchController.php:~240–275`).
- Textsuche: Leere Query → 400 (`src/Controller/ApiSearchController.php:~140–155`).
- Embedding‑Routen: JSON‑/File‑Validierung und klare 400/500‑Codes (`src/Controller/EmbeddingController.php:~25–95`).


## Geheimnisse & Konfiguration

- API‑Key und OpenAI‑Keys stammen aus Env (`.env.local` nicht committen). In Code/Logs werden Keys nicht ausgegeben.
- Relevante Vars: `APP_API_KEY`, `OPENAI_API_KEY`, Modellnamen, Milvus‑Zugang (`.env(.dist), config/services.yaml`).


## Empfehlungen (Härtung Production)

- API‑Key‑Cookie: `Secure` + `SameSite=Lax/Strict` setzen; optional kurze TTL und Rotation.
- Rate‑Limiting/Abuse‑Schutz: z. B. per Proxy/WAF oder Symfony RateLimiter‑Component vor die öffentlichen POST‑Routen.
- CSRF‑Schutz: Für `/search*` optional aktivieren, wenn Frontend Forms verwendet oder wenn abgrenzbare Nutzeraktionen vorliegen.
- Upload‑Limits auch auf API‑Pfaden (Audio/Bild) ergänzen; Dateitypen strenger prüfen (Magic Bytes).
- Logging: sicherstellen, dass keine sensiblen Inhalte (vollständige Vektoren, Rohbilder, Audio) dauerhaft geloggt werden; `DEBUG_VECTORS` in Prod deaktiviert lassen.
- Secrets‑Handling: `.env.local` nur lokal, Prod per echte Env/Secret‑Stores; keine Secrets in Repos/CI‑Logs.


<!-- STEP DONE: 10/13 -->

