# Prompt zur Generierung der Projektdokumentation

**Rolle:**  
Du bist ein Senior-Softwarearchitekt für Symfony.

**Ziel:**  
Erstelle eine vollständige, nachvollziehbare Projektdokumentation, sodass ein neues Team das System ohne Rückfragen nachbauen und erweitern kann. Nach jedem schritt musst du das entsprechende Ergebnis in die entsprechendend benannte zieldatei schreiben. Schritt 1 beispielsweise in docs/01-Projekt-Beschreibung.md

**Kontext:**  
Das Repo liegt dir vollständig vor. Nutze zusätzlich die Rohdaten in `docs/_generated/` (z. B. router.json, container.txt, autowiring.txt, symfony-about.txt, composer-deps.txt).

---

## Gliederung & Zieldateien

- `docs/01-Projekt-Beschreibung.md`
    - Zweck, Business-Kontext, wichtigste Features
    - Nicht-Ziele / Abgrenzungen
    - High-Level-Systemkontext (Mermaid-Diagramm)

- `docs/02-Systemarchitektur.md`
    - Laufzeitarchitektur (Requests, Controller, Services, Repositories, Events/Messenger)
    - Schichten & Module (DDD-Kontext, falls vorhanden)
    - Externe Systeme (DB, Message Broker, APIs)
    - Mermaid-Komponentendiagramm + Sequenzdiagramme für Kern-Use-Cases

- `docs/03-Build-&-Deployment.md`
    - Lokales Setup (PHP-Version, Extensions, Composer, Symfony CLI, Docker falls vorhanden)
    - Build-Pipeline (Scripts aus composer.json, CI-Jobs)
    - Deploy-Ablauf (Env-Variablen, Migrations, Caches)

- `docs/04-Umgebung-&-Konfiguration.md`
    - `.env`-Variablen (ohne Secrets), Parameter, Feature-Flags
    - `config/packages/*.yaml` Überblick (wichtige Optionen)
    - Security-Firewalls, Access Controls

- `docs/05-Datenmodell.md`
    - Doctrine-Entities mit Feldern, Relationen, Indizes
    - Migrations-Strategie
    - Mermaid-ER-Diagramm

- `docs/06-Controller-&-REST-Endpoints.md`
    - Alle Routen aus router.json: Methode, Pfad, Controller-Action, Name, Middleware/Firewall, erwartete Request/Response-Schemas
    - Falls vorhanden: exakte OpenAPI-Spezifikation (oder generierte Skizze)

- `docs/07-Domain-Services-&-Abhängigkeiten.md`
    - Services aus container.txt: wichtigste Services, deren Konstruktor-Abhängigkeiten, Lebenszyklus
    - Sequenzdiagramme: „Controller X → Service Y → Repository Z → Externe API“

- `docs/08-Module-&-Bundles.md`
    - Eigenentwickelte Bundles, Drittanbieter-Bundles (aus composer-deps.txt)
    - Wofür sie genutzt werden, relevante Konfiguration

- `docs/09-Fehlerbehandlung-&-Logging.md`
    - Exception-Strategien, Error-Pages, Monolog-Channels/Handler

- `docs/10-Security-&-Auth.md`
    - Benutzerrollen/Rechte, Voter, Security-Events, CSRF, CORS

- `docs/11-Tests-&-Qualitätssicherung.md`
    - Testarten, Abdeckung, wie Tests lokal laufen
    - Linters/Static Analysis (PHPStan), Rector-Regeln

- `docs/12-Operative-Runbooks.md`
    - Häufige Betriebsaufgaben (Caches leeren, Migrations, Wartungsmodus)
    - Monitoring/Alarming-Punkte

- `docs/13-Glossar.md`
    - Projektspezifische Begriffe und Acronyme

---

## Konkrete Anforderungen

- Belege jede Aussage mit Code-Fundstellen (Dateipfad + Zeilennummer grob, z. B. `src/Controller/OrderController.php:~45`).
- Tabellen für Routen, Services und Entities.
- Mermaid-Diagramme für Architektur/Sequenzen/ERD.
- Keine Secrets (API Keys, Passwörter, private URLs meiden oder schwärzen).
- Abschnitt „How to reproduce“ in der Projektbeschreibung: minimaler Pfad, um das Projekt lokal startklar zu bekommen, plus mindestens ein End-to-End-Use-Case.
- Wenn Annahmen getroffen werden müssen: klar kennzeichnen und offene Fragen erfassen.

---

## Erwartetes Tabellenformat

- **Routen:**  
  `Methode | Pfad | Name | Controller::Action | Middlewares/Firewall | Request-Modell | Response-Modell`

- **Service:**  
  `Service-ID | Klasse | Konstruktor-Args | Scope | Verwendungen (Top 3 Callers)`

- **Entity:**  
  `Entity | Tabelle | Primärschlüssel | Wichtige Felder (Typ, Nullable) | Beziehungen`

---

## Optionale Extras

- OpenAPI-YAML generieren oder verifizieren (z. B. NelmioApiDocBundle).
- ADRs für wichtige Architekturentscheidungen anlegen (`docs/adr/ADR-XXXX-titel.md`).
- Checkliste „Onboarding in 30 Minuten“ am Ende von `01-Projekt-Beschreibung.md`.  

## Arbeitsmodus (sequenziell)
- Erzeuge **genau eine** Zieldatei pro Schritt, in der Reihenfolge 01 → 13.
- Schreibe die Datei direkt nach `docs/…` (falls Dateischreiben nicht möglich: gib ausschließlich den **vollständigen Markdown-Inhalt** zurück).
- **Keine** weiteren Dateien in einem Schritt anfassen oder erwähnen.
- Beende jeden Schritt mit: `<!-- STEP DONE: XX/13 -->`.
- **Warte auf mein „weiter“**, bevor du den nächsten Schritt startest.

## Leseregeln & Umfang
- Du darfst das **gesamte Repository** lesen – **ausgenommen** alles, was durch `.gitignore` ausgeschlossen ist.
- Falls dein System `.gitignore` nicht automatisch beachtet, behandle mindestens folgende Pfade/Dateitypen als ausgeschlossen: `vendor/`, `node_modules/`, `.git/`, `var/cache/`, `var/log/`, `public/build/`, `storage/`, alle `.env*`, `*.log`, `*.sqlite*`, `*.pdf`, `*.png`, `*.jpg`, `*.jpeg`, `*.gif`, `*.zip`.
- Große Dateien oder Binärdateien bitte nicht vollständig laden; falls notwendig, nur Metadaten nutzen.
- Keine Secrets in die Doku übernehmen (API-Keys, Passwörter, private URLs).