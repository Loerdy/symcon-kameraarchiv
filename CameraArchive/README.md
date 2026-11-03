# Kamera-Archiv (CameraArchive)

Nimmt Snapshots von Kameras auf und legt sie je Kamera in einem Archiv ab. Als Bildquelle dient zunächst die UniFi Protect Integration API.

> Status: in Entwicklung (Phase 2 von 8). Snapshots werden derzeit nur abgerufen und in der Debug-Ausgabe gemeldet, aber noch nicht gespeichert.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Verbindung zur UniFi-Protect-Konsole über die offizielle Integration API (API-Key)
* Abruf der Kameraliste und Auswahl der Kameras im Konfigurationsformular
* Kennzeichnung von Kameras, für die Protect keine Snapshots liefert (z. B. Drittanbieter-Kameras)
* Snapshot-Abruf je Kamera, in voller Auflösung, wenn die Kamera Full-HD-Snapshots unterstützt

Geplant: Speicherung im RAM (Ringpuffer), Auslöser, Kachel, Mailversand, Ablage auf einem NAS per WebDAV.

### 2. Voraussetzungen

* Symcon ab Version 8.1
* PHP-Erweiterung cURL (in Symcon enthalten)
* UniFi-OS-Konsole mit UniFi Protect und einem API-Key (Control Plane > Integrations)

### 3. Software-Installation

* Über den Module Store das Modul „Kamera-Archiv“ installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/Loerdy/symcon-kameraarchiv`

### 4. Einrichten der Instanzen in Symcon

Unter „Instanz hinzufügen“ kann das „Kamera-Archiv“-Modul mithilfe des Schnellfilters gefunden werden.
Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen).

__Konfigurationsseite__:

| Name | Beschreibung |
| ---- | ------------ |
| Protect-Konsole | Hostname oder IP-Adresse der UniFi-OS-Konsole, optional mit Port. Die Verbindung erfolgt immer per HTTPS. |
| API-Key | API-Key aus UniFi OS (Control Plane > Integrations). Er hat Administratorrechte und muss geheim bleiben. |
| Zertifikat nicht prüfen | Für Konsolen mit selbstsigniertem Zertifikat. Standardmäßig aus. |
| Kameras | Liste der Kameras. Mit „Aktiv“ werden Kameras für das Archiv ausgewählt. Rot markierte Kameras liefern keinen Snapshot über Protect, gelb markierte sind nicht verbunden. |

__Testbereich__:

| Name | Beschreibung |
| ---- | ------------ |
| Verbindung testen | Fragt die Kameraliste ab und meldet das Ergebnis. |
| Kameras abrufen | Trägt die Kameras der Konsole in die Liste ein. Bestehende Auswahl bleibt erhalten. Danach „Übernehmen“ klicken. |
| Snapshot testen | Ruft einen Snapshot der gewählten aktiven Kamera ab. Details stehen in der Debug-Ausgabe. |

__Status__:

| Code | Bedeutung |
| ---- | --------- |
| 102 | Bereit |
| 201 | Protect-Konsole oder API-Key fehlt |
| 202 | PHP-Erweiterung cURL ist nicht verfügbar |
| 203 | Protect-Konsole nicht erreichbar |
| 204 | API-Key ungültig oder ohne Berechtigung |
| 205 | Zertifikat konnte nicht geprüft werden |
| 206 | Unerwartete Antwort der Protect-Konsole |

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an.

### 6. Visualisierung

Noch keine. Eine Kachel ist geplant.

### 7. PHP-Befehlsreferenz

`boolean CAR_TakeSnapshot(integer $InstanzID, string $CameraID);`
Ruft einen Snapshot der Kamera mit der Protect-ID `$CameraID` ab. Die Kamera muss in der Kameraliste aktiv sein. Liefert `true` bei Erfolg. Derzeit wird das Bild nur in der Debug-Ausgabe gemeldet und nicht gespeichert.

Beispiel:
`CAR_TakeSnapshot(12345, '653fd7e100601403e40003f3');`

Die Funktionen `CAR_UITestConnection`, `CAR_UIFetchCameras` und `CAR_UITestSnapshot` dienen den Buttons im Konfigurationsformular und sind nicht für Skripte gedacht.
