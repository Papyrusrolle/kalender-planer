# Notizen (Notion als Speicher)

Einfache Notiz-App für alle Geräte – iPhone, Windows-PC, Firmenrechner/Remote-Desktop.
Alle Notizen liegen als Seiten in **einer Notion-Datenbank**. Dort sind sie auch direkt in Notion
lesbar, durchsuchbar und weiterverwendbar.

Adresse: `https://<deine-domain>/notizen/` (liegt neben dem Kalender, gleiches Passwort).

## Einmalig einrichten (5 Minuten)

1. **Integration anlegen:** <https://www.notion.so/profile/integrations> → *Neue Integration* →
   Typ *Intern*, Name z. B. „Notiz-App“ → den **Schlüssel** (`ntn_…`) kopieren.
2. **Datenbank anlegen:** In Notion eine neue Seite → *Tabelle* (ganzseitig), Name z. B. „Notizen“.
3. **Freigeben:** In der Datenbank oben rechts **••• → Verbindungen** → „Notiz-App“ hinzufügen.
4. **App öffnen**, mit dem Kalenderpasswort anmelden, Schlüssel + Link der Datenbank einfügen.
   Die Spalten **Tags** und **Angepinnt** legt die App selbst an.

Der Notion-Schlüssel wird nur auf dem Server gespeichert (`data/notizen-config.json`, per
`.htaccess` gesperrt und bei Deploy-Updates nicht überschrieben) – nie auf den Geräten und nie in Git.

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
- `notion.php` – Brücke zur Notion-API (Schlüssel bleibt serverseitig, Passwortschutz wie `api.php`).
  Beim Speichern werden nur geänderte Blöcke ersetzt, nicht die ganze Seite.
