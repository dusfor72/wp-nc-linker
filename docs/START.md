# Startdokument: WP Nextcloud Linker (`wp-nc-linker`) – Entwicklung mit Claude Code

Dieses Dokument ist der Einstieg für die Entwicklung in Claude Code (Container, angebunden an GitHub).
Der obere Teil (Arbeitsregeln, Systemkontext) gilt für die gesamte Entwicklung. Danach folgen
verifizierte Schnittstellen, Anforderungen, Testumgebung, Abnahme, offene Themen und zuletzt die
konkrete Problemstellung.

Stand: 24.10.2026.

---

## Arbeitsregeln (verbindlich)

**Grundhaltung**
- **Keine Daten des Zielsystems ins Repository:** keine Domains, IPs, Benutzernamen, Pfade oder Zugangsdaten
  des Produktivservers – weder in Code, Tests, Commits, Issues noch in der Doku. Das Plugin ist universell;
  alle Beispiele nutzen Platzhalter wie `https://cloud.example.org` und `linker`.
- Nichtwissen offen aussprechen. Keine Annahmen, kein Halluzinieren. Schnittstellen von Nextcloud und
  WordPress **am Quellcode der passenden Version** prüfen (Tags/Zweige, siehe unten), nicht aus dem Gedächtnis.
- Bei Unklarheit oder Annahmen **vor** der Umsetzung fragen. Fehlende Unterlagen erst anfordern.
- Klartext: gesicherte Befunde als Fakten, kein zaghaftes „könnte“. Echte offene Punkte einmal kurz nennen.
  Erklärungen kurz.
- Fehler und Warnungen in Logs sind nicht „harmlos“: Sie werden beseitigt. Das Plugin erzeugt im Normalbetrieb
  **keine** PHP-Notices, -Warnings oder -Deprecations (PHP 8.4, `WP_DEBUG` an).
- **Sicherheit von Anfang an, ohne Erinnerung** (Details unter „Nicht-funktionale Anforderungen“).
- Themen außerhalb des Fokus nicht nebenbei lösen, sondern als offenen Punkt (OP-…) notieren.
- Vorwarnen, wenn etwas spürbar Kontingent kostet (Recherche, große Builds, lange Testläufe).

**Ablauf**
- Erst Bestandsaufnahme und Plan (Struktur, Klassen, Endpunkte) vorlegen, dann umsetzen.
- In sinnvoll gruppierten Schritten arbeiten; jeder Schritt endet mit grünen Tests.
- Commits klein und thematisch, aussagekräftige Messages (Englisch). Arbeit über Branches und Pull Requests.
- Offene Punkte mit festen Codes (OP-1, OP-2 …) als aktuelle Liste führen.
- Abschlussdokument erst auf Anforderung. Dokumente als `.md`.
- **Kein Deployment auf den Produktivserver aus Claude Code.** Ergebnis ist ein Release-Zip auf GitHub;
  das Einspielen erfolgt getrennt nach den Server-Regeln.

**Technik**
- Sprache im Code und in Quell-Strings: Englisch. Übersetzung `de_DE` (Du-Form) wird mitgeliefert.
- Text-Domain `wp-nc-linker`, Präfix für PHP (Namespace `WpNcLinker\`), Optionen, Hooks, Konstanten
  `WPNCL_` / `wpncl_`.
- Lizenz GPL-2.0-or-later (Voraussetzung für WordPress-Plugins).

---

## Systemkontext (Zielumgebung, ohne Serverdaten)

| Komponente | Version |
|---|---|
| WordPress | 7.1.3 (de_DE) |
| PHP | 8.4-FPM (eigener Pool, `open_basedir`) |
| Webserver / DB | Apache 2.4.68, MariaDB 11.8.6 |
| Nextcloud | 34.0.4 |
| Nextcloud-Apps | `photos` 7.0.0, `memories` 10.2.0, `viewer` 7.0.0-dev.0 |

**Eigenschaften der Zielinstallation, die das Plugin einhalten muss**
- `DISALLOW_FILE_MODS = true`: keine Installation/Updates aus der Oberfläche; Plugin wird per WP-CLI eingespielt.
- **Direkte Aufrufe von `.php`-Dateien unter `/wp-content/` sind per Webserver gesperrt.** Alle Endpunkte laufen
  über WordPress selbst (REST-API, `admin-ajax.php` nur wenn unvermeidbar, oder Rewrite-Regel auf `index.php`).
- PHP läuft als eigener Benutzer, der **Code nicht ändern** und nur in `uploads` und einem Temp-Verzeichnis
  schreiben darf. Das Plugin **schreibt keine Dateien** (kein Cache, keine Logs, keine Temp-Dateien auf Dauer).
- `memory_limit` 256M; Kamera-Originale können groß sein → Durchreichen per **Streaming**, nie komplett im Speicher.
- Secrets stehen als Konstanten in `wp-config.php` (außerhalb des Webroots).
- WordPress und Nextcloud können auf demselben Server laufen; Verbindung immer per HTTPS mit Zertifikatsprüfung.
- Album-Pflege erfolgt in Nextcloud über **Memories** oder **Photos**. Memories nutzt für Alben die Photos-App;
  Photos bleibt daher aktiviert. Für das Plugin sind beide gleichwertig (gleiche Datenbasis).

---

## Verifizierte Nextcloud-Schnittstellen

Geprüft am Quellcode der Zweige `nextcloud/server@stable34` und `nextcloud/photos@stable34`.
**In der Entwicklung gegen den Tag `photos` v7.0.0 gegenprüfen.**

1. **Anmeldung:** Für nicht angemeldete Anfragen an `index.php`-Routen versucht Nextcloud u. a.
   `tryBasicAuthLogin` (`lib/base.php`, `handleLogin()`). Das Plugin meldet sich per HTTP Basic mit
   Benutzername + **App-Passwort** an. Kein Session-Cookie speichern.
2. **Vorschau (Bilder):** `GET {base}/index.php/apps/photos/api/v1/preview/{fileId}?x={w}&y={h}`
   (`PreviewController::index`, `@NoAdminRequired`, `@NoCSRFRequired`). Findet die Datei
   a) im Dateibaum des Kontos (inkl. an das Konto freigegebener Ordner), b) in eigenen Alben,
   c) in direkt an das Konto freigegebenen Alben, d) in an seine Gruppen freigegebenen Alben.
   → **Die Datei-ID genügt als Referenz**, unabhängig von Ordner oder Album.
   Antwort: Bild mit `Content-Type`, `cacheFor(86400, false, true)` (privat, immutable);
   Fehler: 400 (Parameter), 403, 404.
3. **Download-Sperre:** Bei an das Konto freigegebenen Ordnern verwirft die Vorschau-Route Knoten, deren
   Freigabe das Attribut `permissions/download = false` trägt („Download verbergen“). Doku/Fehlermeldung des
   Plugins muss darauf hinweisen: **Freigaben an das Verbindungskonto mit Download-Recht, nur lesend.**
4. **Ordner (WebDAV):** `{base}/remote.php/dav/files/{user}/…` – `PROPFIND` (Depth 1) zum Durchsuchen,
   `GET` für Originale/Dokumente. Datei-ID über `oc:fileid`, Typ über `d:getcontenttype`, Größe,
   Änderungsdatum, ETag; Vorschau-Verfügbarkeit über `nc:has-preview`. Exakte Property-Namen am Code prüfen.
5. **Alben (WebDAV, Photos):** `{base}/remote.php/dav/photos/{user}/albums/` und `…/sharedalbums/`
   (`PhotosHome::getChild`; dritte Sammlung `places` wird nicht genutzt). Album-Inhalte enthalten die Dateien
   mit Datei-ID; Abruf der Datei ebenfalls über diese Sammlung möglich. Exakte Struktur/Properties
   (`AlbumsHome`, `SharedAlbumsHome`, `AlbumRoot`, `AlbumPhoto`) am Code prüfen.
6. **Original zu einer Datei-ID:** Für Ordnerdateien über den Dateibaum; für Albumdateien über die
   Album-Sammlung. Das Plugin speichert dazu je Anhang die **Quelle** (Ordner-Pfad bzw. Album-Pfad) und
   aktualisiert sie, wenn die Datei-ID an anderer Stelle gefunden wird (Umbenennen/Verschieben ändert die
   Datei-ID nicht).

---

## Funktionale Anforderungen

**F1 – Einstellungsseite und Konfiguration**
- **Einstellungsseite** „Einstellungen → Nextcloud Linker“ (Standardweg für alle Nutzer des Plugins) mit:
  Nextcloud-Basis-URL, Benutzername des Verbindungskontos, App-Passwort, Verbindungstest (F2),
  Cache-Dauer der Auslieferung, erlaubte MIME-Typen (F6), Präfix der Proxy-URL, Verhalten bei Deinstallation (F10).
- Speicherung als Option; das App-Passwort **verschlüsselt** (libsodium, Schlüssel aus den WordPress-Salts
  abgeleitet), nie im Klartext ausgegeben (Feld bleibt leer, „gesetzt“-Hinweis).
- **Optionaler Vorrang über Konstanten** in `wp-config.php` für Admins, die Secrets aus der Datenbank
  heraushalten wollen: `WPNCL_URL`, `WPNCL_USER`, `WPNCL_APP_PASSWORD`. Ist eine Konstante gesetzt, ist das
  Feld auf der Einstellungsseite gesperrt und als „durch wp-config.php festgelegt“ gekennzeichnet
  (Passwort nie angezeigt).
- **Zugriff auf die Einstellungsseite** standardmäßig nur mit `manage_options`; per Filter
  `wpncl_settings_capability` änderbar (z. B. auf `create_users`, wenn eine Site-Rolle `manage_options` hat,
  die Verbindung aber nicht ändern soll). Die Prüfung gilt für Menü, Seite **und** Speichern
  (`option_page_capability_…`).
- Keine fest eingebauten Pfade, Benutzer oder Hosts.

**F2 – Verbindungstest / Diagnose**
- Auf der Einstellungsseite und per WP-CLI: Erreichbarkeit, Anmeldung, Zugriff auf Dateibaum und
  Album-Sammlungen, Vorschau einer Beispieldatei. Klare Fehlermeldungen (z. B. 401, TLS, Download-Sperre).

**F3 – Auswahl in der Mediathek**
- Im Medien-Dialog (Block-Editor und klassische Mediathek) ein Reiter **„Nextcloud“** mit drei Bereichen:
  Ordner, eigene Alben, geteilte Alben.
- Navigation in Ordnern, Vorschaubilder (über den Plugin-Proxy, F5), Mehrfachauswahl.
- Auswahl einzelner Bilder und Dokumente.

**F4 – Virtuelle Anhänge (nur Datenbank, keine Datei)**
- Je ausgewählter Datei ein Anhang (`post_type = attachment`) mit Metadaten:
  Datei-ID, Quelle (Ordner/Album + Pfad), MIME-Typ, Dateiname, Breite/Höhe (bei Bildern), ETag/Änderungsdatum.
- **Keine Datei in `uploads`**, keine von WordPress erzeugten Bildgrößen auf Platte.
- Doppelte Auswahl derselben Datei-ID legt keinen zweiten Anhang an.
- Anhänge funktionieren in den **Standardblöcken** (Bild, Galerie, Cover, Medien+Text, Datei) und in Themes:
  Filter u. a. `wp_get_attachment_url`, `image_downsize`, `wp_get_attachment_image_src`,
  `wp_calculate_image_srcset`, `wp_get_attachment_metadata` so bedienen, dass WordPress die registrierten
  Bildgrößen als Proxy-URLs erhält (Breite/Höhe je Größe).
- Titel/Alt-Text in WordPress pflegbar wie bei normalen Medien.

**F5 – Auslieferung (Proxy)**
- Öffentliche URL über WordPress, z. B. `/{prefix}/{attachment_id}/{size}/{dateiname}` (Rewrite auf `index.php`)
  oder REST-Route; Präfix per Filter änderbar.
- **Nur registrierte virtuelle Anhänge** werden ausgeliefert – das Plugin ist **kein offener Proxy** für den
  Speicher des Verbindungskontos. Datei-IDs aus der URL werden nie direkt an Nextcloud weitergereicht.
- Bildgrößen → Vorschau-Route (F2 der Schnittstellen) mit passendem `x`/`y`; Originalgröße („full“) und
  Dokumente → WebDAV-`GET`.
- **Streaming** (Chunks durchreichen, kein komplettes Puffern, keine Temp-Datei).
- Header: korrekter `Content-Type`, `Content-Length` wenn bekannt, `Cache-Control: public, max-age=…`
  (Standard 1 Tag, per Filter/Einstellung), `ETag`/`Last-Modified` durchreichen, `304` bei passendem
  `If-None-Match`/`If-Modified-Since`, `X-Content-Type-Options: nosniff`; Dokumente mit
  `Content-Disposition: inline` bzw. `attachment` je MIME-Typ.
- Keine Nextcloud-Header oder -Adressen an den Besucher durchreichen.

**F6 – Dokumente**
- Dokumente (z. B. PDF) als virtuelle Anhänge; Einbindung über den Datei-Block.
- Erlaubte MIME-Typen per Einstellung/Filter (Standard: Bilder, PDF, gängige Office-/ODF-Formate).

**F7 – Fehlende Dateien**
- Liefert Nextcloud 403/404 (gelöscht, Freigabe entzogen, Download gesperrt), zeigt die Seite einen
  **unauffälligen Platzhalter** (generisches SVG) bzw. bei Dokumenten eine 404-Antwort.
- Betroffene Anhänge werden markiert; **Hinweis im Dashboard** für Benutzer mit der Auswahl-Berechtigung
  (Liste der Anhänge mit Link).
- Regelmäßige Prüfung per WP-Cron-Ereignis **und** per WP-CLI-Befehl (damit Server mit eigenem Timer statt
  WP-Cron arbeiten können).

**F8 – Berechtigungen**
- Auswahl und Anlage virtueller Anhänge: Capability `upload_files` (Standard), per Filter änderbar.
- Einstellungen und Verbindungstest: `manage_options`, per Filter `wpncl_settings_capability` änderbar (F1).
  Benutzer, die nur auswählen dürfen, sehen weder Einstellungen noch Verbindungsdaten.
- Alle Aktionen mit Nonce und Capability-Prüfung.

**F9 – WP-CLI**
- `wp nc-linker test` (Verbindungstest), `wp nc-linker check` (Prüfung F7),
  `wp nc-linker list` (virtuelle Anhänge mit Status).

**F10 – Deinstallation**
- Optionen und Cron-Ereignisse entfernen. Virtuelle Anhänge nur auf ausdrückliche Einstellung entfernen
  (sonst bleiben Inhalte mit Platzhalter stehen).

---

## Nicht-funktionale Anforderungen

- **Mindestversionen:** WordPress 6.6, PHP 8.1; getestet mit WordPress 7.1 und PHP 8.4.
- **Sicherheit**
  - Secrets nie in Ausgaben, Logs, Fehlermeldungen, REST-Antworten oder im HTML.
  - SSRF-Schutz: Ziel-Host ausschließlich aus der Konfiguration; Pfade aus Benutzereingaben normalisieren
    (kein `..`, nur unterhalb der Sammlungen des Verbindungskontos); Datei-IDs als Integer.
  - TLS-Prüfung immer an; Timeouts gesetzt; Weiterleitungen nicht auf fremde Hosts folgen.
  - Escaping/Sanitizing nach WordPress-Standard; Nonces; Capability-Checks.
  - Keine Schreibzugriffe auf Nextcloud (nur `PROPFIND`/`GET`).
- **Keine Dateischreibzugriffe** auf dem WordPress-Server (siehe Systemkontext).
- **Keine externen Ressourcen** (keine CDNs, keine Telemetrie). JS/CSS mit `@wordpress/scripts` gebaut,
  gebaute Dateien im Release-Zip.
- **Code-Qualität:** WordPress Coding Standards (PHPCS mit WPCS), PHPStan (mind. Level 6),
  ESLint per `@wordpress/scripts`; alles in CI (GitHub Actions).
- **Leistung:** Verzeichnis-/Albumlisten im Auswahldialog dürfen kurz im Objekt-Cache/Transient liegen
  (Minuten); Auslieferung ohne Dateicache, über Browser-Cache-Header.
- **i18n:** alle Strings übersetzbar; `de_DE` (Du-Form) beiliegend.
- **Doku:** README (Englisch) mit Konfiguration über Konstanten, Hinweis auf Freigaben mit Download-Recht,
  Memories/Photos-Hinweis, WP-CLI-Befehle.

**Nicht im Umfang von Version 1**
- Videos (Memories verwaltet sie; später als Option).
- Hochladen oder Ändern von Dateien in Nextcloud aus WordPress.
- Öffentliche Nextcloud-Freigabelinks als Quelle.
- Bildbearbeitung/Zuschnitt in WordPress auf Nextcloud-Dateien.

---

## Testumgebung (Container in Claude Code)

- `docker compose` im Repository (Ordner `dev/`): WordPress 7.1 + PHP 8.4 + MariaDB, Nextcloud 34 mit
  `photos` 7.0.0 und `memories` 10.2.0.
- Seed-Skript: Admin-Konto, Verbindungskonto `linker` mit App-Passwort, ein Inhaltskonto mit Testbildern
  (inkl. großem Original), einem PDF, einem freigegebenen Ordner (mit Download-Recht), einem Ordner mit
  „Download verbergen“, einem eigenen Album des Verbindungskontos, einem an `linker` geteilten Album und einem
  an eine Gruppe von `linker` geteilten Album.
- Interne TLS-Verbindung im Container (selbstsigniertes CA-Zertifikat, dem WordPress vertraut) –
  **keine** Abschaltung der TLS-Prüfung im Plugin-Code.
- Tests: PHPUnit (Unit + Integration gegen den Nextcloud-Container), E2E für den Medien-Dialog
  (Playwright mit `@wordpress/e2e-test-utils-playwright`).

---

## Abnahmetests

1. Verbindungstest grün – einmal über die Einstellungsseite, einmal mit Konstanten (Felder dann gesperrt);
   Passwort nirgends sichtbar.
1a. Mit `wpncl_settings_capability = create_users`: Rolle mit `manage_options` ohne `create_users` sieht die
   Einstellungsseite nicht und kann nicht speichern (auch nicht per direktem POST); Medienauswahl funktioniert.
2. Reiter „Nextcloud“ zeigt Ordner, eigene Alben und geteilte Alben (auch Gruppen-Freigabe).
3. Bild aus Album ausgewählt → Anhang ohne Datei in `uploads`; Bild-Block zeigt es mit `srcset` aus Proxy-URLs.
4. Galerie-Block aus mehreren Albumbildern funktioniert; Theme-Ausgabe korrekt.
5. Großes Original (> 50 MB) wird ohne Überschreiten von `memory_limit` gestreamt.
6. Proxy-URL mit nicht registrierter oder manipulierter ID → 404; kein Zugriff auf beliebige Dateien.
7. Wiederholter Abruf mit `If-None-Match` → `304`.
8. Datei in Nextcloud umbenannt/verschoben → weiter sichtbar. Gelöscht oder Freigabe entzogen →
   Platzhalter + Dashboard-Hinweis nach `wp nc-linker check`.
9. Ordner mit „Download verbergen“ → verständliche Diagnosemeldung.
10. PDF über Datei-Block abrufbar mit korrekten Headern.
11. Rollen Administrator, Redakteur und eine Rolle mit `upload_files` können auswählen; Abonnent nicht.
12. Kein direkter Aufruf einer Plugin-PHP-Datei nötig (Test mit gesperrtem `/wp-content/*.php`).
13. Keine Dateien außerhalb der Datenbank geschrieben (Dateisystem-Diff vor/nach Testlauf).
14. Keine PHP-Notices/Warnings/Deprecations im Log bei `WP_DEBUG`.
15. PHPCS, PHPStan, ESLint und alle Tests in CI grün; Release-Zip enthält gebaute Assets und `de_DE`.

---

## Auslieferung

- GitHub-Release mit installierbarem Zip (ohne `dev/`, Tests, `node_modules`).
- Installation auf dem Zielsystem getrennt per WP-CLI aus dem Release-Zip; Konfiguration über Konstanten.
- Updates kommen nicht über wordpress.org (siehe OP-3).

---

## Offene Themen

- **OP-1:** Videos als Option (Version 2).
- **OP-2:** Veröffentlichung im WordPress-Plugin-Verzeichnis (Prüfrichtlinien, Name, Assets).
- **OP-3:** Update-Weg für Installationen ohne wordpress.org (z. B. Release-Prüfung per WP-CLI).
- **OP-4:** Sichtbarkeit des GitHub-Repositorys (privat während der Entwicklung, später öffentlich?).
- **OP-5:** Externe Konfigurationsdatei außerhalb des Webroots (Pfad per Konstante) – nur bei Bedarf;
  Version 1 nutzt Einstellungsseite und Konstanten.

---

## Problemstellung

Entwickle das WordPress-Plugin **„WP Nextcloud Linker“** (`wp-nc-linker`) in einem neuen GitHub-Repository
`wp-nc-linker`. Redakteure sollen Bilder und Dokumente aus **Ordnern und Alben** (Photos/Memories), die einem
Nextcloud-Verbindungskonto freigegeben sind, direkt in der WordPress-Mediathek auswählen und in Seiten nutzen
können – **ohne die Dateien in WordPress abzulegen**. WordPress speichert nur Verweise (Datei-ID) und liefert
Bilder und Dokumente per Streaming-Proxy aus Nextcloud aus.

Vorgehen:
1. Schnittstellen (Abschnitt „Verifizierte Nextcloud-Schnittstellen“) am Quellcode der Tags gegenprüfen,
   offene Details (Property-Namen, Album-Struktur) klären.
2. Repository-Grundgerüst: passende `.gitignore` für ein Plugin-Projekt (u. a. `node_modules/`, `vendor/`,
   `build/`, Daten/Volumes der Testumgebung, `.env`, lokale Secrets); `LICENSE` (GPLv2) und Platzhalter-`README.md`
   sind vorhanden, das README wird später ersetzt. Dann Testumgebung aufbauen (Container, Seed-Daten).
3. Architektur und Plan vorlegen (Klassen, Endpunkte, Datenmodell der Anhang-Metadaten) – erst nach
   Rückmeldung umsetzen.
4. Umsetzung in Schritten F1 → F10 mit Tests; zum Schluss Abnahmetests und Release-Zip.
