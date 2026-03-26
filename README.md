# Praxis-Dashboard (PHP + MySQL)
---

## 1) Projektstruktur

- `index.php`  
  Hauptdatei mit kompletter Business-Logik + Rendering der Seiten (Login + Dashboard).
- `config.php`  
  Datenbankverbindung (`db()`), Schema-Erstellung (`initializeSchema()`), Session-Helfer (`sessionUser()`).
- `logout.php`  
  Session beenden und auf Login zurückleiten.
- `styles.css`  
  Darkmode-Design, Layout, Karten, Tabs, Formulare.
- `app.js`  
  Kleine UI-Absicherung (Bestätigung beim „Erledigt“-Setzen).

---

## 2) Datenbankverbindung

Die Verbindung wird zentral in `config.php` aufgebaut:

```php
new PDO('mysql:host=bszw.ddns.net;dbname=wit12a_ITP_StiefScheibl;charset=utf8', 'wit12a', 'geheim');
```

### Was `db()` macht
1. Erstellt einmalig eine PDO-Instanz (Singleton-ähnlich über `static $pdo`).
2. Aktiviert Exception-Fehlerverhalten (`PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`).
3. Setzt Standard-Fetch auf assoziative Arrays.
4. Ruft `initializeSchema()` auf, damit Tabellen beim ersten Start automatisch vorhanden sind.

---

## 3) Datenmodell / Tabellen

Beim Start werden folgende Tabellen erzeugt falls diese noch nicht vorhanden sind:

### `arzt`
- `id` (PK)
- `name` (eindeutig)
- `password`
- `faktor`

### `ort`
- `id` (PK)
- `plz`
- `ort`

### `patient`
- `id` (PK)
- `ort_id` (FK -> `ort.id`)
- `arzt_id` (FK -> `arzt.id`)
- `vorname`, `nachname`, `strasse`, `hausnummer`
- `created_at`

### `leistung`
- `id` (PK)
- `bezeichnung` (eindeutig)
- `preis`

### `patient_leistung`
- `id` (PK)
- `patient_id` (FK -> `patient.id`)
- `leistung_id` (FK -> `leistung.id`)
- `arzt_id` (FK -> `arzt.id`)
- `datum`
- `kostentraeger` (`krankenkasse` oder `selbstzahler`)
- `erledigt` (0/1 pro Leistung)

---

## 4) Ablauf der Anwendung (Request-Flow)

`index.php` führt in dieser Reihenfolge aus:

1. `session_start()` und Laden von `config.php`
2. DB-Verbindung holen (`$pdo = db()`)
3. POST-Aktionen auswerten (Login, Registrierung, Patienten-/Leistungsaktionen)
4. Prüfen, ob Arzt eingeloggt ist (`sessionUser()`)
5. Falls **nicht eingeloggt**: Login-Seite rendern
6. Falls **eingeloggt**: Dashboard-Daten laden und Dashboard rendern

---

## 5) Login-Logik im Detail

### Aktion `login`
- Liest `username` + `password` aus dem Formular.
- Sucht den Arzt in `arzt`.
- Wenn Arzt existiert:
  - Passwortvergleich
  - bei Erfolg: Session `$_SESSION['arzt'] = ['id', 'name']`
- Wenn Arzt **nicht** existiert:
  - Login wird nicht direkt erlaubt
  - stattdessen wird ein Hinweis angezeigt: „Arzt existiert nicht – speichern?“

### Aktion `register_doctor`
- Speichert den Arzt (Name + Passwort) in `arzt`.
- Danach muss der Nutzer sich normal einloggen.

---

## 6) Dashboard-Funktionen im Detail

### 6.1 Offene Patienten
- Zeigt Patienten, bei denen mindestens eine Leistung des eingeloggten Arztes noch `erledigt = 0` ist.
- Button „Erledigt abhaken“ setzt **alle offenen Leistungen dieses Arztes für den Patienten** auf erledigt.
- Der Patient wird **nicht gelöscht**, nur die Leistungs-Statuswerte werden aktualisiert.

### 6.2 Leistung hinzufügen (`add_service`)
- Speichert neue Leistung in Tabelle `leistung`.
- Doppelte Bezeichnung wird durch UNIQUE verhindert.

### 6.3 Patient hinzufügen (`add_patient`)
- Formular enthält Patientendaten + Leistung + Kostenträger.
- Ablauf in einer Transaktion:
  1. Ort suchen (`plz + ort`) oder neu anlegen
  2. Existierenden Patienten (gleiche Stammdaten) suchen; falls nicht vorhanden neu anlegen
  3. Neue Leistung in `patient_leistung` schreiben (mit Datum + Kostenträger + `erledigt = 0`)
- Bei Fehler: Rollback.

### 6.4 Suche & Transfer
- Suchfeld filtert auf Vorname/Nachname.
- Pro Patient-ID wird genau eine Karte angezeigt.
- Unter „Leistungen anzeigen“ werden alle Leistungen dieses Patienten inkl. Status (Offen/Erledigt) angezeigt.
- Wenn alle Leistungen erledigt sind:
  - nur Anzeige, kein Transfer möglich.
- Wenn offene Leistungen vorhanden sind:
  - Transfer ist möglich (`transfer_group`), verschiebt alle offenen Leistungen zur gewählten `arzt_id`.

---

## 7) JavaScript (`app.js`)

- Beim Formular mit Aktion `mark_done` erscheint eine `confirm()`-Abfrage.
- Bei „Abbrechen“ wird das Absenden verhindert.

---

## 8) Styling (`styles.css`)

- Darkmode als Standard
- Struktur über Panels/Karten/Tabs
- Badge-Farben für `Erledigt` vs `Offen`
- Formulare, Alerts und Topbar visuell konsistent

---


## 9) Kurz-Zusammenfassung

- `config.php` = DB + Schema
- `index.php` = komplette App-Logik
- `styles.css` = Darkmode-UI
- `app.js` = kleine UX-Absicherung
- `logout.php` = Session-Ende
