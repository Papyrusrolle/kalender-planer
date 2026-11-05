# Notizen (Notion als Speicher)

Einfache Notiz-App für alle Geräte – iPhone, Windows-PC, Firmenrechner/Remote-Desktop.
Alle Notizen liegen als Seiten in **einer Notion-Datenbank**. Dort sind sie auch direkt in Notion
lesbar, durchsuchbar und weiterverwendbar.

Die App läuft auf **Netlify** (empfohlen) oder alternativ auf dem IONOS-Webspace neben dem Kalender.
Die Oberfläche ist in beiden Fällen dieselbe (`notizen/`), nur die Brücke zu Notion unterscheidet sich:
`netlify/functions/notion.mts` auf Netlify, `notizen/notion.php` auf IONOS.

## Einmalig einrichten

### 1. Notion vorbereiten (beide Varianten)

1. **Integration anlegen:** <https://www.notion.so/profile/integrations> → *Neue Integration* →
   Typ *Intern*, Name z. B. „Notiz-App“ → den **Schlüssel** (`ntn_…`) kopieren.
2. **Datenbank anlegen:** In Notion eine neue Seite → *Tabelle* (ganzseitig), Name z. B. „Notizen“.
3. **Freigeben:** In der Datenbank oben rechts **••• → Verbindungen** → „Notiz-App“ hinzufügen.

### 2a. Netlify

1. Netlify-Projekt mit diesem GitHub-Repo verbinden (Branch `main`). `netlify.toml` stellt alles
   Weitere ein (veröffentlicht nur `notizen/`, die Function liegt unter `/notion.php`).
2. Unter *Project configuration → Environment variables* die Variable **`NOTIZEN_PASSWORT`** anlegen
   (Scope *Functions*, als Secret markieren) – das ist das Passwort, mit dem du dich in der App anmeldest.
   Danach einmal neu deployen.
3. Prüfen, ob unter *Project configuration → Access & security → Visitor access* ein Netlify-Team-Login
   vorgeschaltet ist. Für die Nutzung auf dem iPhone und am Firmenrechner diesen für *Production*
   abschalten – die App ist durch das eigene Passwort geschützt.
4. App öffnen, anmelden, Notion-Schlüssel + Link der Datenbank einfügen. Gespeichert wird beides in
   Netlify Blobs (nur für die Function lesbar). Alternativ `NOTION_TOKEN` und `NOTION_DB` als
   Umgebungsvariablen setzen.

### 2b. IONOS (Alternative)

Adresse `https://<deine-domain>/notizen/`, gleiches Passwort wie der Kalender. App öffnen, anmelden,
Schlüssel + Datenbank-Link einfügen. Gespeichert wird beides in `data/notizen-config.json`
(per `.htaccess` gesperrt, bei Deploy-Updates nicht überschrieben).

In beiden Fällen: Die Spalten **Tags** und **Angepinnt** legt die App selbst an. Der Notion-Schlüssel
liegt nur auf dem Server – nie auf den Geräten und nie in Git.

## Als App installieren

| Gerät | So geht's |
|---|---|
| iPhone / iPad | In **Safari** öffnen → Teilen → **Zum Home-Bildschirm** |
| Windows (Edge) | ••• → **Apps** → **Diese Seite als App installieren** |
| Windows (Chrome) | Installieren-Symbol rechts in der Adressleiste |
| Remote-Desktop / Firmen-PC | Im Browser öffnen, ggf. wie oben installieren |

Danach startet sie wie eine normale App mit eigenem Symbol und Fenster.

## Bedienung

- Speichert automatisch (ca. 1 Sekunde nach dem Tippen) direkt in Notion.
- **Offline:** Zuletzt geladene Notizen sind lesbar, Änderungen werden auf dem Gerät gepuffert und
  automatisch übertragen, sobald wieder Verbindung besteht (oranger Punkt = noch nicht gespeichert).
- **Gleichzeitig auf zwei Geräten geändert?** Die App fragt nach; nichts geht verloren
  (die eigene Fassung kann als „Konflikt-Kopie“ gesichert werden).
- Löschen verschiebt die Seite in den Notion-Papierkorb (30 Tage wiederherstellbar).
- Tastatur: `Strg+Alt+N` neue Notiz, `Strg+S` sofort speichern, `Strg+F` suchen.

### Formatierung (wird zu echten Notion-Blöcken)

```
# Überschrift   ## kleiner   ### noch kleiner
- Aufzählung    1. Nummeriert    [ ] Aufgabe    [x] erledigt
> Zitat         ---  (Trennlinie)
**fett**  *kursiv*  ~~durchgestrichen~~  `Code`  [Text](https://…)
```

Jede Zeile wird ein eigener Notion-Block. Seiten, die in Notion Bilder, Tabellen, Toggles o. Ä.
bekommen haben, zeigt die App an, lässt dort aber nur Titel und Tags ändern, damit nichts kaputtgeht.

## Technik

- `index.html` – die App (PWA, keine Abhängigkeiten), `sw.js` – Offline-Cache, `manifest.webmanifest` – Installation
- `../netlify/functions/notion.mts` (Netlify) bzw. `notion.php` (IONOS) – Brücke zur Notion-API, gleiche Logik,
  Schlüssel bleibt serverseitig.
  Beim Speichern werden nur geänderte Blöcke ersetzt, nicht die ganze Seite.
