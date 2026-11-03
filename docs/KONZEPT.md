# KameraArchiv – Konzept

IP-Symcon-Modul für Kamera-Snapshots mit Archiv je Kamera. Es ersetzt das Symcon-Bilderarchiv.
Zielplattform: SymBox (Flash schonen, RAM knapp: 1–2 GB).

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
- Im Formular holt ein Button „Kameras abrufen“ die Liste; der Nutzer hakt die gewünschten Kameras an.
- Drittanbieter-Kameras (Typ nicht „UVC …“) in der Liste als „kein Snapshot über Protect“ markieren.
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
- Bedingung: jede Änderung / Wert = true / Wert = X
- Kamera(s): eine oder mehrere
- Verzögerung in Sekunden (z. B. Reed-Kontakt Tür 2 s, damit die Tür ganz offen ist; Klingel 0–1 s)
- Sperrzeit in Sekunden (gegen Dauerauslösen durch Bewegungsmelder)
- optional: automatisch per Mail senden

Umsetzung: `RegisterMessage(VM_UPDATE)` auf die Auslöser-Variablen. Fällige Snapshots kommen in eine Warteschlange (Attribut), ein einzelner Timer arbeitet sie ab.

Später möglich: Protect-Ereignisse (Klingel G4 Doorbell, Bewegung, Smart Detect) direkt als Auslöser.

## 3. Speicherung

**Stufe 1 – SymBox-RAM (immer)**
- Fester Slot-Pool pro Kamera aus Bild-Medienobjekten, mit `IPS_SetMediaCached(true)` und unterhalb der Instanz.
- Ringpuffer: Der neue Snapshot überschreibt den ältesten Slot. Es werden keine Objekte neu angelegt oder gelöscht, und nichts wird bis zum Shutdown auf den Flash geschrieben.
- Index als JSON-Attribut: Slot, Zeitstempel, Auslöser, Merker „Behalten“.
- Optional verkleinern per GD vor dem Speichern (z. B. 1280 px, Qualität 75 → ca. 100 KB).
- Anzeige des belegten RAM im Formular.
- Rechnung: 8 Kameras × 30 Bilder × 100 KB ≈ 24 MB.

**Stufe 2 – Synology per WebDAV (optional)**
- Paket „WebDAV Server“, eigener Benutzer, nur Freigabe `kamera-archiv`.
- Upload in voller Auflösung per HTTPS-PUT (curl), kein Netzlaufwerk auf der SymBox nötig.
- Pfad: `/kamera-archiv/<Kamera>/<YYYY-MM-DD>/<HHMMSS>_<auslöser>.jpg`
- Rotation durch das Modul (Anzahl und/oder Tage pro Kamera, per WebDAV-DELETE); „Behalten“-Bilder sind ausgenommen.
- NAS offline → Warteschlange im RAM, späterer Upload.
- RAM hält dann nur die neuesten N Bilder pro Kamera für die Kachel.

Keine Statusvariablen ohne Opt-in (Lizenzlimit).

## 4. Kachel (HTML-SDK)

- Kamera-Chips oben zur Auswahl
- Neuestes Bild groß; Auslöser-Badge und Zeit
- Archivleiste darunter, sortiert neu → alt, filterbar nach Auslöser
- Ältere Bilder werden bei Bedarf vom NAS nachgeladen
- Aktionen: Snapshot jetzt, Behalten, Per Mail (SMTP-Instanz, `SMTP_SendMailAttachment`), Download, Löschen
- Bildauslieferung über den WebHook des Moduls; dieser holt bei Bedarf auch Bilder vom NAS (keine Zugangsdaten oder CORS-Probleme im Browser)

## 5. Umsetzungsphasen

1. Gerüst: library.json, module.json, form.json, locale.json, module.php, README; Status-Codes; Debug
2. Quelle Protect-API: Kameraliste abrufen (UpdateFormField), Snapshot abrufen, Verbindungstest
3. RAM-Speicher: Slot-Pool (cached Media), Index, Rotation, optionale Verkleinerung, Speicheranzeige
4. Auslöser: Liste, MessageSink, Verzögerungs-Warteschlange mit einem Timer, Sperrzeit
5. Kachel: HTML-SDK, WebHook für Bilder, Aktionen (Snapshot jetzt, Behalten, Löschen, Download)
6. Mail: SMTP-Instanz wählbar, manuell und automatisch je Auslöser
7. Synology WebDAV: Upload, Rotation, Offline-Warteschlange, Nachladen in der Kachel
8. Weitere Quellen: Direkt-URL, Surveillance Station
