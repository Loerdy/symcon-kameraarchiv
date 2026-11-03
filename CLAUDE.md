# CLAUDE.md – symcon-kameraarchiv

Dauerhafte Regeln für die Arbeit in diesem Repository. Fachliches Konzept: `docs/KONZEPT.md`.

## Projekt

- IP-Symcon-Bibliothek mit dem Modul **CameraArchive** (Anzeigename de: „Kamera-Archiv“).
- Zielplattform: SymBox (ARM, 1–2 GB RAM, eMMC-Flash). Flash schonen, RAM sparsam nutzen.
- Ziel: Einreichung im Symcon Module Store (zuerst Beta, später Stable mit Review).

## Dokumentation zuerst

- Vor jeder Verwendung einer Symcon-Funktion die offizielle Doku prüfen:
  - https://www.symcon.de/de/llms.txt
  - https://www.symcon.de/de/llms/function-index.md
  - SDK: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/
  - Store-Einreichung/Review: https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/store/submit
  - Best Practices: https://gist.github.com/paresy/236bfbfcb26e6936eaae919b3cfdfc4f
- Keine Funktionen, Parameter, Konstanten, Message-IDs oder GUIDs erfinden. Wenn etwas nicht belegt ist: nachfragen.
- Externe APIs (UniFi Protect Integration API, Synology WebDAV/Surveillance Station) nur so nutzen, wie in `docs/KONZEPT.md` dokumentiert oder offiziell belegt.

## GUIDs

- GUIDs (Library, Modul, ggf. weitere) einmalig zufällig erzeugen (Format `{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}`, Großbuchstaben) und mir **vor** der Verwendung zur Bestätigung zeigen.
- Bestehende GUIDs niemals ändern. Neue GUIDs nur nach Rückfrage.
- GUIDs von Symcon-Kernmodulen (z. B. SMTP, WebHook Control) nur aus der offiziellen Doku übernehmen.

## Store-Richtlinien (Review-Kriterien)

1. Dateizugriffe nur in Ausnahmefällen und nur innerhalb des Symcon-Ordners. Bilder werden als Medienobjekte unterhalb der Instanz gespeichert, nicht als Dateien.
2. Jede Lokalisierung braucht eine vollständige Übersetzung: Code und form.json auf Englisch, `locale.json` mit vollständiger deutscher Übersetzung.
3. Die Instanz muss immer anlegbar sein. Fehlende Voraussetzungen (kein API-Key, Konsole nicht erreichbar, GD fehlt …) per `SetStatus()` und Hinweis im Formular melden, nie per Exception beim Anlegen.
4. Nur eigene Objekte (unterhalb der Instanz) oder explizit zugewiesene Objekte (SelectVariable, SelectInstance) anfassen. Fremde Objekte nie verändern.
5. Objekte außerhalb der Instanz nur nach ausdrücklicher Bestätigung durch den Nutzer anlegen. Timer nur per `RegisterTimer`, Ereignisse nur per `RegisterMessage`/`MessageSink`, keine Ereignis-Objekte.
6. „IPSymcon“, „IPS“ o. Ä. in keinem lokalisierten Namen.
7. Name, Sichtbarkeit, Aktiv-Status, Icon, Beschreibung, Position gehören dem Nutzer. Diese bei eigenen Objekten höchstens beim erstmaligen Anlegen setzen und danach nie überschreiben. Keine benutzerdefinierten Aktionen, Profile oder Archiv-Logging ohne Bestätigung setzen; Standardaktionen und -profile sind erlaubt.
8. Nach dem Anlegen gehören die Properties dem Nutzer: kein `IPS_SetProperty`/`IPS_ApplyChanges` auf die eigene Instanz. Vorschläge (z. B. abgerufene Kameraliste) per `UpdateFormField`, der Nutzer bestätigt mit „Übernehmen“. Bei List-Elementen mit `loadValuesFromConfiguration: false` die Werte beim Laden trotzdem befüllen.
9. Nur `<?php`, nie Short-Tags.
10. Logging nur mit `$this->LogMessage()`, Debug mit `$this->SendDebug()`. `IPS_LogMessage` ist verboten.
11. Abweichungen nur nach Absprache und mit Begründung im Code-Kommentar.

## Projektspezifische Regeln

- **Lizenzen:** Statusvariablen nur, wenn der Nutzer sie per Option aktiviert (Variablen-Lizenzen sind begrenzt). Standard: keine Variablen.
- **Flash:** Bild-Medienobjekte mit `IPS_SetMediaCached` im RAM halten (Doku prüfen). Kein Schreiben von Bilddateien auf die SymBox.
- **Ringpuffer:** fester Slot-Pool je Kamera, Slots überschreiben statt Objekte ständig anlegen und löschen.
- **Geheimnisse:** API-Key und Passwörter als `PasswordTextBox`; niemals in `SendDebug`, `LogMessage`, Fehlermeldungen oder im Kachel-Payload ausgeben.
- **HTTP:** curl mit Timeouts (Verbindung ≤ 5 s, gesamt ≤ 15 s); Option „Zertifikat nicht prüfen“ für selbstsignierte Zertifikate, standardmäßig aus.
- **Blockieren vermeiden:** Snapshot-Abrufe nicht im `MessageSink` ausführen, sondern über die Timer-Warteschlange.
- **PHP:** `declare(strict_types=1);`, PHP-8-kompatibel, keine externen Composer-Abhängigkeiten im Modul.
- **Kachel:** HTML-SDK (`SetVisualizationType`, `GetVisualizationTile`, `UpdateVisualizationValue`, `RequestAction` aus der Kachel) – Details vor Verwendung in der Doku prüfen. Bilder über den WebHook der Instanz ausliefern, nicht als Base64 in jedem Update.
- **Präfix** der öffentlichen Funktionen: `CAR` (z. B. `CAR_TakeSnapshot`). Vor Festlegung mit mir abstimmen.

## Struktur

```
library.json
README.md
CameraArchive/
  module.json
  module.php
  form.json
  locale.json
  module.html        (Kachel)
  README.md          (Doku für den Store: Funktionsumfang, Voraussetzungen, Einrichtung, PHP-Befehlsreferenz)
docs/
  KONZEPT.md
```

## Qualität vor Abschluss jeder Aufgabe

- `php -l` für alle PHP-Dateien
- alle JSON-Dateien validieren (z. B. `php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR);' <datei>`)
- `locale.json`: jeder englische Text aus form.json, module.php (`$this->Translate`) und module.html hat eine deutsche Übersetzung
- `git diff --check`
- Prüfen, ob die von Symcon bereitgestellten Prüfwerkzeuge für Module (Code-Style-Action, SymconStubs/Tests) verfügbar sind, und sie – falls belegt – einrichten und nutzen.

## Git

- Vor Änderungen `git status` prüfen.
- Gearbeitet wird auf `beta`. `main` nur für Releases.
- **Keine Commits ohne ausdrückliche Aufforderung. Kein Push ohne ausdrückliche Erlaubnis.**
- Versionierung SemVer: Major.Minor → `version` in library.json, Patch → `build`.
- Kleine, gezielte Änderungen; bestehende Schnittstellen und Architektur erhalten.
