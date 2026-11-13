# KameraArchiv – Konzept

IP-Symcon-Modul für Kamera-Snapshots mit Archiv je Kamera. Es ersetzt das Symcon-Bilderarchiv.
Zielplattform: SymBox (Flash schonen, RAM knapp: 1–2 GB).

## 0. Aufbau

| Modul | Typ | Aufgabe |
|---|---|---|
| Kamera-Archiv Konsole (`CARC`) | I/O | Protect-Konsole, API-Key, Verkleinern, In-Memory-Cache, später NAS und Mail; Übersichtskachel aller Kameras |
| Kamera-Archiv Konfigurator (`CARK`) | Konfigurator | listet die Kameras der Konsole, legt je Kamera eine Instanz an |
| Kamera-Archiv Kamera (`CAR`) | Gerät | eine Kamera: Auslöser, Ringpuffer unterhalb der Instanz, eigene Kachel und WebHook |

- Datenfluss Kamera/Konfigurator → Konsole `{9EC0ACA4-E5E8-FBE2-061E-944A1C1A13BB}`, Konsole → Kinder `{0EACC956-88F0-E151-304C-A22F37DDA6EA}`. Befehle als JSON, HEX-kodiert im Feld `Buffer` (IPSModuleStrict); Antworten als JSON, Bilder Base64.
- Befehle an die Konsole: `GetCameras`, `Snapshot` (liefert das verkleinerte Bild und die Cache-Option), `GetSettings`, `StateChanged`. Befehle an die Kinder: `GetTileState` (für die Übersichtskachel), `Settings` (Cache-Option geändert).
- Der Name der Kamera-Instanz ist der Name des Kamera-Buttons in den Kacheln.

## 1. Bildquellen (pro Kamera wählbar)

| Quelltyp | Verwendung | Status |
|---|---|---|
| UniFi Protect Integration API | 8 UniFi-Kameras | getestet, funktioniert (Haustür) |
| Synology Surveillance Station API | künftige Fremdkameras | eingeplant |
| Direkt-URL (z. B. `http://<cam>/snap.jpeg`) | Fallback | wie bisher |

**Protect-API**
- `GET https://<Konsole>/proxy/protect/integration/v1/cameras` → Kameraliste (id, name, type, state, featureFlags)
- `GET https://<Konsole>/proxy/protect/integration/v1/cameras/<id>/snapshot[?highQuality=true]`
- Header `X-API-KEY`. Der Key wird unter Control Plane → Integrations angelegt und hat Admin-Rechte (kein Read-only), also vertraulich behandeln.
- `highQuality` nur setzen, wenn `featureFlags.supportFullHdSnapshot = true`.
- Der Konfigurator holt die Liste und legt je Kamera eine Instanz an.
- Drittanbieter-Kameras (Typ nicht „UVC …“) im Konfigurator als „kein Snapshot über Protect“ markieren und nicht anlegen.
- Selbstsigniertes Zertifikat: Option „Zertifikat nicht prüfen“.
- Die interne Protect-API (`/proxy/protect/api/...`, Login mit lokalem Benutzer) wird nicht genutzt. Sie ist undokumentiert, und der Haustür-Snapshot darüber hatte nur ca. 18 KB, also vermutlich eine reduzierte Auflösung.

**Tapo C500 (`tapo-01-garten`) – zurückgestellt**
- Integration API: `{"error":"Unexpected error","name":"UNKNOWN_ERROR"}`
- Interne API: HTTP 500, 0 Byte (Gegenprobe Haustür: 200 OK)
- Ergebnis: Protect liefert für Drittkameras keine Snapshots.
- Surveillance Station kommt nicht in Frage: Die Tapo erlaubt nur eine Server-Verbindung und soll in Protect bleiben.

## 2. Auslöser

Liste in der Konfiguration; je Eintrag:
- Variable (Klingel, Reed-Kontakt, Bewegungsmelder …)
- Bedingung: jede Änderung / jede Aktualisierung / wechselt auf Wert X / wird mit Wert X aktualisiert (X: Auswahl wahr/falsch bei Bool-Variablen, gespeichert in `ValueBool`, sonst Textfeld für Zahl oder Text in `Value`; der Dialog blendet das passende Feld ein. SelectValue ließ den Dialog in der Konsole nicht laden)
- gilt für die Kamera-Instanz, in der er eingetragen ist (mehrere Kameras: Auslöser in jeder Kamera eintragen)
- Verzögerung in Sekunden (z. B. Reed-Kontakt Tür 2 s, damit die Tür ganz offen ist; Klingel 0–1 s)
- Sperrzeit in Sekunden (gegen Dauerauslösen durch Bewegungsmelder)
- optional: automatisch per Mail senden

Umsetzung: `RegisterMessage(VM_UPDATE)` auf die Auslöser-Variablen. Fällige Snapshots kommen in eine Warteschlange (Buffer, nur RAM, kein Flash-Schreiben je Auslösung), ein einzelner Timer je Kamera arbeitet sie ab. Zusätzlich zur Sperrzeit je Auslöser gilt ein Mindestabstand je Kamera.

Später möglich: Protect-Ereignisse (Klingel G4 Doorbell, Bewegung, Smart Detect) direkt als Auslöser.

## 3. Speicherung

**Stufe 1 – SymBox-RAM (immer)**
- Fester Slot-Pool pro Kamera aus Bild-Medienobjekten, mit `IPS_SetMediaCached(true)` und unterhalb der Kamera-Instanz. Standard: 5 Bilder (mit NAS liegt das vollständige Archiv dort).
- Ringpuffer: Der neue Snapshot überschreibt den ältesten Slot. Es werden keine Objekte neu angelegt oder gelöscht, und nichts wird bis zum Shutdown auf den Flash geschrieben.
- Index als JSON in einem ebenfalls gecachten Dokument-Medienobjekt unterhalb der Kamera-Instanz: Slot, Zeitstempel, Auslöser, Merker „Behalten“. Kein Attribut, da Attribute laut Doku sofort gespeichert werden (Flash); so bleiben Index und Bilder auf demselben Stand.
- Optional verkleinern per GD vor dem Speichern (z. B. 1280 px, Qualität 75 → ca. 100 KB). Das übernimmt die Konsole, damit sie später das Original aufs NAS laden kann.
- Anzeige des belegten RAM im Formular.
- Rechnung: 8 Kameras × 5 Bilder × 100 KB ≈ 4 MB (bei 30 Bildern ≈ 24 MB).

**Stufe 2 – Synology per WebDAV (optional)**
- Paket „WebDAV Server“, eigener Benutzer, nur Freigabe `kamera-archiv`.
- Upload in voller Auflösung per HTTPS-PUT (curl), kein Netzlaufwerk auf der SymBox nötig.
- Pfad: `/kamera-archiv/<Kamera>/<YYYY-MM-DD>/<HHMMSS>_<auslöser>.jpg`
- Rotation durch das Modul (Anzahl und/oder Tage pro Kamera, per WebDAV-DELETE); „Behalten“-Bilder sind ausgenommen.
- NAS offline → Warteschlange im RAM, späterer Upload.
- RAM hält dann nur die neuesten N Bilder pro Kamera für die Kachel.

Keine Statusvariablen ohne Opt-in (Lizenzlimit).

## 4. Kachel (HTML-SDK)

- Kachel je Kamera-Instanz; Übersichtskachel der Konsole mit Kamera-Chips oben zur Auswahl (Name = Instanzname)
- Neuestes Bild groß; Auslöser-Badge und Zeit
- Archivleiste darunter, sortiert neu → alt, filterbar nach Auslöser
- Antippen des Bildes: Vollbildansicht (Fullscreen-API, falls für die Kachel erlaubt, sonst über die Kachel), Wischen/Pfeile zum Blättern
- Ältere Bilder werden bei Bedarf vom NAS nachgeladen
- Aktionen: Snapshot jetzt, Behalten, Download, Löschen (eine Taste „Per Mail“ ist nicht vorgesehen)
- Bildauslieferung über den WebHook des Moduls; dieser holt bei Bedarf auch Bilder vom NAS (keine Zugangsdaten oder CORS-Probleme im Browser)

## 5. Umsetzungsphasen

1. Gerüst: library.json, module.json, form.json, locale.json, module.php, README; Status-Codes; Debug
2. Quelle Protect-API: Kameraliste abrufen (UpdateFormField), Snapshot abrufen, Verbindungstest
3. RAM-Speicher: Slot-Pool (cached Media), Index, Rotation, optionale Verkleinerung, Speicheranzeige
4. Auslöser: Liste, MessageSink, Verzögerungs-Warteschlange mit einem Timer, Sperrzeit
5. Kachel: HTML-SDK, WebHook für Bilder (5a); Aktionen Snapshot jetzt, Behalten, Löschen, Download (5b, per `requestAction` mit JSON-Wert; die Übersichtskachel leitet über die Konsole an die Kamera weiter, Snapshot über die Warteschlange)
5u. Umbau: Konsole, Konfigurator, Instanz je Kamera, Übersichtskachel, Vollbildansicht
6. Benachrichtigungen je Kamera: E-Mail per `SMTP_SendMailMedia(Ex)` mit dem Bild-Medienobjekt als Anhang (keine Datei) oder Push per `VISU_PostNotification` (ohne Bild); je Auslöser oder alle, optionale Bedingung Variable = Wert; nur Auslöser-Snapshots
7. Synology WebDAV (7a): Zugang in der Konsole, Upload/Ordner/Aufbewahrung je Kamera; die Konsole lädt das Original hoch (Warteschlange als kleiner Buffer, Originale in einem Pool aus 30 gecachten Medienobjekten unter der Konsole, da ein Buffer nur 512 KiB fasst und der Kernel alle Buffer einer Instanz über 1 MiB löscht; max. 25 MB, Wiederholung 1–30 min, Größenprüfung per HEAD nach dem Upload), Rotation täglich nach Tagesordnern per PROPFIND/DELETE, „Behalten“ per WebDAV-COPY in `<Kamera>/Behalten`. 7b: Archiv in der bestehenden Kachel (Umschaltung „Archiv“/„Neueste“, Tagesauswahl), Listen per `requestAction` mit Anfrage-ID und Antwort über `UpdateVisualizationValue`, Bilder über den WebHook der Kamera (Konsole liest per WebDAV, Vorschau aus `<Tag>/thumbs`, sonst Original)
8. Weitere Quellen: Direkt-URL, Surveillance Station
9. Geplant: Testfunktion für Benachrichtigungen (Mail mit dem neuesten Bild und Push direkt aus dem Formular senden, ohne Auslöser und Bedingung)
10. Live-Ansicht in der Kachel als Bildfolge (Abrufintervall je Kamera 0,5–30 s, Standard 1 s), umgesetzt in [Unreleased]
    - Taste „Live“ in Kamera- und Übersichtskachel, auch im Vollbild; aus der Live-Ansicht heraus Snapshot speichern.
    - Die Kachel lädt das Bild periodisch über den WebHook der Kamera (`live=1`); der WebHook holt über die Konsole einen Snapshot ohne `highQuality` (schneller). Nichts wird gespeichert: kein Ringpuffer, kein NAS, kein Flash.
    - Ein Bild wird je Kamera ca. 1 s für alle Zuschauer wiederverwendet: eine eigene Sperre je Kamera, das Bild im Buffer der Kamera nur bis 300 000 Zeichen Base64 (deutlich unter der 512-KiB-Grenze je Buffer), größere Bilder werden nicht geteilt.
    - Ende automatisch bei unsichtbarer Kachel (`visibilitychange` und `IntersectionObserver`, da nicht dokumentiert ist, ob die Visualisierung Kacheln anderer Seiten entfernt oder nur ausblendet), nach der Höchstdauer (Property je Kamera, 1–30 min), beim Kamerawechsel oder nach 3 fehlgeschlagenen Bildern in Folge.
    - Optionaler automatischer Start je Kamera beim Anzeigen der Kachel und nach erneutem Anzeigen; nach Stopp durch den Nutzer, Höchstdauer oder Fehler erst beim nächsten Anzeigen. Die Bilder lädt die Kachel nacheinander per `fetch` (das nächste erst nach Eingang des vorigen).
    - Echtes Video ist nicht vorgesehen: Laut Drittquellen bietet die Integration API RTSPS-Streams je Kamera an (abrufen, für Qualitätsstufen anlegen, löschen; URLs auf Port 7441 mit `?enableSrtp`), außerdem Talkback-Sitzungen für Audio. Browser und die Symcon-App können RTSP(S) nicht abspielen; dafür wäre eine Umwandlung (z. B. ffmpeg) nötig, die auf der SymBox und im Module Store nicht in Frage kommt. Die offizielle Doku (developer.ui.com) war beim Prüfen am 08.11.2026 nicht lesbar (Seite wird per JavaScript geladen); Endpunkte vor einer Nutzung dort belegen. Quellen: uiprotect-Bibliothek (`get_camera_rtsps_streams`, `create_camera_rtsps_streams`, `delete_camera_rtsps_streams`), Home-Assistant-Integration „UniFi Protect“.
    - Nebenbefund aus derselben Quelle: Der öffentliche Snapshot-Abruf kennt laut uiprotect neben `highQuality` einen Parameter für die Paketkamera (nur Kameras mit `hasPackageCamera`, z. B. Türklingel mit zweiter Kamera). Vor einer Nutzung offiziell belegen.
