# Kamera-Archiv (CameraArchive)

Nimmt Snapshots von Kameras auf und legt sie je Kamera in einem Archiv ab. Als Bildquelle dient zunächst die UniFi Protect Integration API.

> Status: in Entwicklung (Phase 3 von 8). Snapshots werden manuell ausgelöst und im RAM gespeichert. Auslöser und Kachel folgen.

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
* Speicherung im RAM als Ringpuffer je Kamera, Anzahl der Bilder je Kamera einstellbar
* Optionale Verkleinerung vor dem Speichern (Standard: an, 1280 px Breite, JPEG-Qualität 75)

Geplant: Auslöser, Kachel, Mailversand, Ablage auf einem NAS per WebDAV.

### 2. Voraussetzungen

* Symcon ab Version 8.1
* PHP-Erweiterung cURL (in Symcon enthalten)
* Für die Verkleinerung: PHP-Erweiterung GD. Fehlt sie, werden die Bilder unverkleinert gespeichert.
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
| Protect-Konsole | Hostname, IPv4- oder IPv6-Adresse der UniFi-OS-Konsole, optional mit Port (z. B. `192.168.1.1:443`; IPv6 mit Port in eckigen Klammern, z. B. `[fd00::1]:443`). Die Verbindung erfolgt immer per HTTPS. |
| API-Key | API-Key aus UniFi OS (Control Plane > Integrations). Er hat Administratorrechte und muss geheim bleiben. |
| Zertifikat nicht prüfen | Für Konsolen mit selbstsigniertem Zertifikat. Standardmäßig aus. |
| Kameras | Liste der Kameras. Mit „Aktiv“ werden Kameras für das Archiv ausgewählt. „Bilder“ legt fest, wie viele Bilder je Kamera gespeichert werden (1–200, Standard 30). „Gespeichert“ zeigt Anzahl und Größe der gespeicherten Bilder. Rot markierte Kameras liefern keinen Snapshot über Protect, gelb markierte sind nicht verbunden. |
| Bilder vor dem Speichern verkleinern | Verkleinert Bilder, die breiter als die maximale Breite sind. Kleinere Bilder werden unverändert gespeichert. Standardmäßig an. |
| Maximale Breite | Breite in Pixeln, das Seitenverhältnis bleibt erhalten. Standard 1280. |
| JPEG-Qualität | Qualität der verkleinerten Bilder (30–95). Standard 75. |

__Testbereich__:

| Name | Beschreibung |
| ---- | ------------ |
| Verbindung testen | Fragt die Kameraliste ab und meldet das Ergebnis. |
| Kameras abrufen | Trägt die Kameras der Konsole in die Liste ein. Bestehende Auswahl bleibt erhalten. Danach „Übernehmen“ klicken. |
| Snapshot testen | Ruft einen Snapshot der gewählten aktiven Kamera ab und speichert ihn. Details stehen in der Debug-Ausgabe. |
| Bilder entfernter Kameras löschen | Löscht nach Rückfrage alle Bilder von Kameras, die nicht mehr in der Kameraliste stehen. |

__Speicherung__:

* Jedes Bild liegt in einem eigenen Medienobjekt (Ident `IMG_<Kamera-ID>_<Nr>`) direkt unterhalb der Instanz. Ein Medienobjekt wird erst beim ersten Bild für diesen Platz angelegt und danach immer wieder überschrieben.
* Ist die eingestellte Anzahl erreicht, überschreibt ein neues Bild das älteste Bild, das nicht als „Behalten“ markiert ist. Sind alle Bilder einer Kamera markiert, wird kein neues Bild gespeichert.
* Wird die Anzahl verringert, werden die ältesten nicht markierten Bilder gelöscht. Markierte Bilder bleiben erhalten.
* Der Index (Zeitpunkt, Auslöser, „Behalten“, Größe) liegt im Medienobjekt „Bildindex“ (Ident `ImageIndex`).
* Alle diese Medienobjekte sind im Arbeitsspeicher gecacht und werden erst beim Herunterfahren von Symcon geschrieben. Das schont den Flash-Speicher der SymBox. Bei einem Stromausfall gehen die Bilder seit dem letzten Herunterfahren verloren.
* Name und Sichtbarkeit der Medienobjekte werden nur beim Anlegen gesetzt (versteckt) und können danach frei geändert werden.
* Ist der Bildindex beschädigt, speichert das Modul keine Bilder mehr und meldet dies im Meldungsfenster. Abhilfe: Medienobjekt „Bildindex“ löschen. Die vorhandenen Bild-Medienobjekte werden dann nach und nach wieder überschrieben.

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
Ruft einen Snapshot der Kamera mit der Protect-ID `$CameraID` ab und speichert ihn im Ringpuffer der Kamera (Auslöser „manual“). Die Kamera muss in der Kameraliste aktiv sein. Liefert `true`, wenn das Bild gespeichert wurde.

Beispiel:
`CAR_TakeSnapshot(12345, '653fd7e100601403e40003f3');`

`boolean CAR_DeleteImages(integer $InstanzID, string $CameraID);`
Löscht alle gespeicherten Bilder der Kamera, auch die als „Behalten“ markierten. Liefert `true` bei Erfolg.

Beispiel:
`CAR_DeleteImages(12345, '653fd7e100601403e40003f3');`

Die Funktionen `CAR_UITestConnection`, `CAR_UIFetchCameras`, `CAR_UITestSnapshot` und `CAR_UIDeleteRemovedCameraImages` dienen den Buttons im Konfigurationsformular und sind nicht für Skripte gedacht.
