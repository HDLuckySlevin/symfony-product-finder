# Produktfinder mit GenAI und Symfony

Diese Symfony-Anwendung ermöglicht das Einlesen von XML-Produktdaten, die Vektorisierung der Produkte und die Synchronisation mit Zilliz (einer Vektordatenbank). Endnutzer können über eine natürlichsprachliche Schnittstelle Produkte finden, die ihren Bedürfnissen entsprechen.

## Funktionen

- Import von Elektronikprodukten aus XML-Dateien
- Vektorisierung aller Produkteigenschaften mit OpenAI Embeddings
- Einzelne Speicherung von Produktmerkmalen und Spezifikationen in der Vektordatenbank
- Speicherung und Suche in der Zilliz Vektordatenbank
- Natürlichsprachliche Produktsuche über API
- Web-Interface mit DeepChat für Endnutzer
- Flexible Konfiguration für API-Keys und Endpunkte

## Technische Architektur

Die Anwendung besteht aus folgenden Hauptkomponenten:

1. **XML-Import**: Liest Produktdaten aus XML-Dateien und konvertiert sie in Product-Objekte
2. **Embedding-Generator**: Erzeugt Vektorrepräsentationen für Produkte, Produktmerkmale, Spezifikationen und Suchanfragen
3. **Zilliz-Integration**: Speichert und durchsucht Produktvektoren, Merkmalvektoren und Spezifikationsvektoren in der Vektordatenbank
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

### Entwicklung mit Gitpod

Dieses Projekt kann direkt in Gitpod geöffnet und verwendet werden, einer Online-IDE für GitHub.

[![Open in Gitpod](https://gitpod.io/button/open-in-gitpod.svg)](https://gitpod.io/#https://github.com/iGore/symfony-product-finder)

Gitpod startet automatisch eine voll funktionsfähige Entwicklungsumgebung, einschließlich der DDEV-Integration. Nach dem Start des Workspaces wird DDEV automatisch gestartet, und die Anwendung ist über die von Gitpod bereitgestellte URL zugänglich. Die notwendigen Ports (z.B. für den Webserver und Mailpit) werden automatisch geöffnet.

Sie können DDEV-Befehle wie gewohnt im Gitpod-Terminal verwenden (z.B. `ddev ssh`, `ddev logs`).

## Verwendung

### Produkte importieren

Verwenden Sie den folgenden Befehl, um Produkte aus einer XML-Datei zu importieren:

```
ddev php bin/console app:import-products src/DataFixtures/xml/sample_products.xml
```

Beim Import werden nicht nur die Produkte als Ganzes, sondern auch deren einzelne Merkmale und Spezifikationen als separate Vektoren in der Datenbank gespeichert. Dies ermöglicht präzisere Suchergebnisse und eine bessere Zuordnung von Nutzeranfragen zu spezifischen Produkteigenschaften.

Die API-Keys sind optional. Wenn sie nicht angegeben werden, verwendet die Anwendung Mock-Daten für Tests.

### Produktsuche testen

Testen Sie die Produktsuche mit dem folgenden Befehl:

```
ddev php bin/console app:test-search "Ich suche ein wasserdichtes Smartphone mit guter Kamera"
```

### Web-Interface

Öffnen Sie die Anwendung in Ihrem Browser unter `https://symfony-product-finder.ddev.site/` und verwenden Sie die Chat-Schnittstelle, um Produkte zu finden.

## Anpassung

### Eigene XML-Struktur

Sie können die XML-Importlogik in `src/Service/XmlImportService.php` anpassen, um Ihre eigene XML-Struktur zu unterstützen.

### Andere Embedding-Provider

Die Anwendung verwendet standardmäßig OpenAI für Embeddings, kann aber leicht auf andere Provider umgestellt werden. Implementieren Sie dazu das `EmbeddingGeneratorInterface`. Wenn Sie einen anderen Provider verwenden möchten, müssen Sie auch die Methoden `generateFeatureEmbeddings` und `generateSpecificationEmbeddings` implementieren, um Embeddings für einzelne Produktmerkmale und Spezifikationen zu erzeugen.

### Zilliz-Konfiguration

Die Zilliz-Integration kann in `src/Service/ZillizVectorDBService.php` angepasst werden, um spezifische Anforderungen zu erfüllen. Sie können die Methoden `insertProductFeatures` und `insertProductSpecifications` anpassen, um die Art und Weise zu ändern, wie Produktmerkmale und Spezifikationen in der Vektordatenbank gespeichert werden.

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
