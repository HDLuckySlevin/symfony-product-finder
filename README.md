# Produktfinder mit GenAI und Symfony

Diese Symfony-Anwendung ermöglicht das Einlesen von XML-Produktdaten, die Vektorisierung der Produkte und die Synchronisation mit Zilliz (einer Vektordatenbank). Endnutzer können über eine natürlichsprachliche Schnittstelle Produkte finden, die ihren Bedürfnissen entsprechen.

## Funktionen

- Import von Elektronikprodukten aus XML-Dateien
- Vektorisierung aller Produkteigenschaften mit OpenAI Embeddings
- Speicherung und Suche in der Zilliz Vektordatenbank
- Natürlichsprachliche Produktsuche über API
- Web-Interface mit DeepChat für Endnutzer
- Flexible Konfiguration für API-Keys und Endpunkte

## Technische Architektur

Die Anwendung besteht aus folgenden Hauptkomponenten:

1. **XML-Import**: Liest Produktdaten aus XML-Dateien und konvertiert sie in Product-Objekte
2. **Embedding-Generator**: Erzeugt Vektorrepräsentationen für Produkte und Suchanfragen
3. **Zilliz-Integration**: Speichert und durchsucht Produktvektoren in der Vektordatenbank
4. **API-Controller**: Stellt REST-Endpunkte für die Produktsuche bereit
5. **Web-Interface**: Bietet eine benutzerfreundliche Oberfläche für Endnutzer

## Installation

### Voraussetzungen

- PHP 8.1 oder höher
- Composer
- Symfony CLI (optional, für lokale Entwicklung)

### Einrichtung

1. Klonen Sie das Repository:
   ```
   git clone [repository-url]
   cd symfony-product-finder
   ```

2. Installieren Sie die Abhängigkeiten:
   ```
   ddev composer install
   ```

3. Konfigurieren Sie die Umgebungsvariablen in einer `.env.local` Datei:
   ```
   OPENAI_API_KEY=your_openai_api_key
   OPENAI_EMBEDDING_MODEL=text-embedding-3-small
   MILVUS_API_KEY=your_zilliz_api_key
   MILVUS_HOST=your_zilliz_endpoint
   MILVUS_PORT=443
   MILVUS_COLLECTION=products
   ```

4. Starten Sie den Symfony-Server:
   ```
   ddev start
   ```

## Verwendung

### Produkte importieren

Verwenden Sie den folgenden Befehl, um Produkte aus einer XML-Datei zu importieren:

```
php bin/console app:import-products src/DataFixtures/xml/sample_products.xml
```

Die API-Keys sind optional. Wenn sie nicht angegeben werden, verwendet die Anwendung Mock-Daten für Tests.

### Produktsuche testen

Testen Sie die Produktsuche mit dem folgenden Befehl:

```
php bin/console app:test-search "Ich suche ein wasserdichtes Smartphone mit guter Kamera"
```

### Web-Interface

Öffnen Sie die Anwendung in Ihrem Browser unter `https://symfony-product-finder.ddev.site/` und verwenden Sie die Chat-Schnittstelle, um Produkte zu finden.

## Anpassung

### Eigene XML-Struktur

Sie können die XML-Importlogik in `src/Service/XmlImportService.php` anpassen, um Ihre eigene XML-Struktur zu unterstützen.

### Andere Embedding-Provider

Die Anwendung verwendet standardmäßig OpenAI für Embeddings, kann aber leicht auf andere Provider umgestellt werden. Implementieren Sie dazu das `EmbeddingGeneratorInterface`.

### Zilliz-Konfiguration

Die Zilliz-Integration kann in `src/Service/ZillizVectorDBService.php` angepasst werden, um spezifische Anforderungen zu erfüllen.

## Entwicklung

### Tests ausführen

```
ddev php bin/phpunit
```

### Neue Funktionen hinzufügen

1. Erstellen Sie neue Controller in `src/Controller/`
2. Fügen Sie neue Services in `src/Service/` hinzu
3. Erweitern Sie die Entitäten in `src/Entity/`

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz.
