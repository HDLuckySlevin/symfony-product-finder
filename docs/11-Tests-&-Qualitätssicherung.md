# Tests & Qualitätssicherung

Überblick über vorhandene Tests, Ausführung, sowie statische Analyse und empfohlene QS‑Praktiken.

Belegstellen:
- PHPUnit: `.github/workflows/ci.yml:~25–35`, `docs/_generated/phpunit-tests.txt`
- Tests: `tests/*.php`
- PHPStan: `phpstan.dist.neon`, `docs/_generated/phpstan.json`
- Composer/Docs‑Scripts: `composer.json:~90–140`, `Makefile:~20–70`


## Tests (PHPUnit)

- Framework: PHPUnit 10.x (siehe CI Output). Ausführung lokal:
  - `bin/phpunit` oder `vendor/bin/phpunit`
  - In DDEV: `ddev php bin/phpunit`
- Verfügbare Tests (Auszug, aus `docs/_generated/phpunit-tests.txt`):
  - App\\Tests\\ImportProductsCommandTest::testExistingVectorsAreRemovedBeforeInsert
  - App\\Tests\\ProcessImageCommandTest::testExecuteRunsSearchCommandWithDescription
  - App\\Tests\\ProcessImageCommandTest::testExecuteFailsOnMissingDescription
  - App\\Tests\\XmlImportServiceTest::testImportFromString
  - App\\Tests\\XmlImportServiceTest::testImportFromStringWithInvalidXml
  - App\\Tests\\XmlImportServiceTest::testImportFromFileWithNonExistentFile
- Abgedeckte Bereiche:
  - XML‑Import end‑to‑end für Parser/Validierung/Serialisierung (`tests/XmlImportServiceTest.php`).
  - CLI‑Import: Korrektes Löschen/Neuindizieren vor Insert (`tests/ImportProductsCommandTest.php`).
  - CLI Bildverarbeitung: Kaskade Vision→Textsuche und Fehlerfall ohne Beschreibung (`tests/ProcessImageCommandTest.php`).
- Muster/Techniken:
  - Abhängigkeiten werden konsequent gemockt (Interfaces/Services), Fokus auf Verhaltensprüfung.
  - Nutzung von `CommandTester` für Console‑Kommandos.

Empfehlungen für neue Tests
- Controller: kleinere HTTP‑Tests über Symfony KernelTestCase oder gezielte Service‑Unit‑Tests (hier: primär Service‑Tests vorhanden).
- Services: Eingaben/Fehlerpfade und Logging‑Seiteneffekte prüfen; externe Aufrufe mocken (OpenAI/Milvus/HTTP‑Client).
- DTO/Serializer: Minimaltests für (De)Serialisierung bei Änderungen an Feldern.


## Statische Analyse (PHPStan)

- Konfiguration: `phpstan.dist.neon` (Level 6)
  - Analysierte Pfade: `bin/, config/, public/, src/, tests/`
  - Aufruf lokal: `vendor/bin/phpstan analyse -c phpstan.dist.neon`
  - DDEV: `ddev php vendor/bin/phpstan analyse -c phpstan.dist.neon`
- Aktueller Status (aus `docs/_generated/phpstan.json`):
  - `file_errors`: 100 (viele Meldungen stammen von fehlenden OpenAPI‑Attributklassen im Analyser‑Kontext, z. B. `OpenApi\\Attributes\\Post`).
  - Diese lassen sich beheben/unterdrücken durch:
    - Installation einer passenden OpenAPI‑Attribut‑Library für Static‑Analysis oder
    - Ignorieren der Attribute in PHPStan (baseline/ignoreErrors) oder
    - Bedingtes Laden via `class_exists` (nicht empfohlen für reine Doku‑Attribute).
- Weitere Hinweise:
  - Einige Hinweise zu Null‑Koaleszenz auf existenten Offsets (z. B. in Image‑Pfad) sind kosmetisch und können bereinigt werden.


## Zusätzliches (Dokugenerierung)

- `composer docs:generate` erzeugt Rohdaten (Router, Container, Autowiring, PHPUnit‑Liste, PHPStan JSON, Rector Dry‑Run wenn vorhanden) unter `docs/_generated/`.
- Alternativ: `make docs-generate` (Makefile) mit ähnlicher Pipeline.


## Code‑Qualität & Formatierung

- Es ist kein dedizierter Linter/Formatter (z. B. PHP‑CS‑Fixer) im Repo konfiguriert.
- Empfehlungen:
  - Einheitlichen Coding‑Style (PSR‑12) manuell oder mit Tooling sicherstellen.
  - Dead‑Code und zu breite Abhängigkeiten minimieren; öffentliche API der Services stabil halten.


## CI‑Pipeline (QS‑relevant)

- GitHub Actions führt auf Pull Requests aus (`.github/workflows/ci.yml`):
  - PHP 8.2, Composer Cache, `composer install`, `bin/phpunit`.
  - Statische Analyse ist nicht in CI erzwungen; kann bei Bedarf ergänzt werden (PHPStan Schritt hinzufügen).


<!-- STEP DONE: 11/13 -->

