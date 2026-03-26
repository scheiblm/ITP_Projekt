<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

$pdo = db();
$errors = [];
$messages = [];
$pendingRegistration = null;
$activeTab = $_GET['tab'] ?? 'offen';

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $name = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($name === '' || $password === '') {
        $errors[] = 'Bitte Benutzername und Passwort eingeben.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM arzt WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $arzt = $stmt->fetch();

        if ($arzt) {
            if ($arzt['password'] !== $password) {
                $errors[] = 'Passwort ist falsch.';
            } else {
                $_SESSION['arzt'] = ['id' => (int) $arzt['id'], 'name' => $arzt['name']];
                header('Location: index.php');
                exit;
            }
        } else {
            $pendingRegistration = ['username' => $name, 'password' => $password];
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'register_doctor') {
    $name = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($name !== '' && $password !== '') {
        $stmt = $pdo->prepare('INSERT INTO arzt (name, password, faktor) VALUES (:name, :password, 1.0)');
        try {
            $stmt->execute(['name' => $name, 'password' => $password]);
            $messages[] = 'Arzt wurde gespeichert. Bitte jetzt erneut einloggen.';
        } catch (PDOException) {
            $errors[] = 'Arzt konnte nicht gespeichert werden. Name existiert evtl. bereits.';
        }
    }
}

$user = sessionUser();

if ($user && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_service':
            $bezeichnung = trim($_POST['bezeichnung'] ?? '');
            $preis = (float) ($_POST['preis'] ?? 0);
            if ($bezeichnung === '' || $preis < 0) {
                $errors[] = 'Bitte gültige Leistungsdaten eingeben.';
                break;
            }
            try {
                $stmt = $pdo->prepare('INSERT INTO leistung (bezeichnung, preis) VALUES (:bezeichnung, :preis)');
                $stmt->execute(['bezeichnung' => $bezeichnung, 'preis' => $preis]);
                $messages[] = 'Leistung gespeichert.';
                $activeTab = 'patient-hinzufuegen';
            } catch (PDOException) {
                $errors[] = 'Leistung konnte nicht gespeichert werden (evtl. doppelt).';
            }
            break;

        case 'add_patient':
            $vorname = trim($_POST['vorname'] ?? '');
            $nachname = trim($_POST['nachname'] ?? '');
            $strasse = trim($_POST['strasse'] ?? '');
            $hausnummer = trim($_POST['hausnummer'] ?? '');
            $plz = trim($_POST['plz'] ?? '');
            $ortName = trim($_POST['ort'] ?? '');
            $leistungId = (int) ($_POST['leistung_id'] ?? 0);
            $kostentraeger = $_POST['kostentraeger'] ?? '';

            if ($vorname === '' || $nachname === '' || $strasse === '' || $hausnummer === '' || $plz === '' || $ortName === '' || $leistungId <= 0 || !in_array($kostentraeger, ['krankenkasse', 'selbstzahler'], true)) {
                $errors[] = 'Bitte Patient, Ort und Leistung vollständig angeben.';
                break;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT id FROM ort WHERE plz = :plz AND ort = :ort');
                $stmt->execute(['plz' => $plz, 'ort' => $ortName]);
                $ortId = $stmt->fetchColumn();

                if (!$ortId) {
                    $stmt = $pdo->prepare('INSERT INTO ort (plz, ort) VALUES (:plz, :ort)');
                    $stmt->execute(['plz' => $plz, 'ort' => $ortName]);
                    $ortId = (int) $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare('SELECT id FROM patient WHERE vorname = :vorname AND nachname = :nachname AND strasse = :strasse AND hausnummer = :hausnummer AND ort_id = :ort_id LIMIT 1');
                $stmt->execute([
                    'vorname' => $vorname,
                    'nachname' => $nachname,
                    'strasse' => $strasse,
                    'hausnummer' => $hausnummer,
                    'ort_id' => $ortId,
                ]);
                $patientId = (int) $stmt->fetchColumn();

                if ($patientId <= 0) {
                    $stmt = $pdo->prepare('INSERT INTO patient (ort_id, arzt_id, vorname, nachname, strasse, hausnummer) VALUES (:ort_id, :arzt_id, :vorname, :nachname, :strasse, :hausnummer)');
                    $stmt->execute([
                        'ort_id' => $ortId,
                        'arzt_id' => $user['id'],
                        'vorname' => $vorname,
                        'nachname' => $nachname,
                        'strasse' => $strasse,
                        'hausnummer' => $hausnummer,
                    ]);
                    $patientId = (int) $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare('INSERT INTO patient_leistung (patient_id, leistung_id, arzt_id, datum, kostentraeger, erledigt) VALUES (:patient_id, :leistung_id, :arzt_id, :datum, :kostentraeger, 0)');
                $stmt->execute([
                    'patient_id' => $patientId,
                    'leistung_id' => $leistungId,
                    'arzt_id' => $user['id'],
                    'datum' => date('Y-m-d'),
                    'kostentraeger' => $kostentraeger,
                ]);

                $pdo->commit();
                $messages[] = 'Patient wurde angelegt.';
            } catch (Throwable $exception) {
                $pdo->rollBack();
                $errors[] = 'Patient konnte nicht gespeichert werden: ' . $exception->getMessage();
            }
            $activeTab = 'patient-hinzufuegen';
            break;

        case 'mark_done':
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE patient_leistung SET erledigt = 1 WHERE patient_id = :patient_id AND arzt_id = :arzt_id AND erledigt = 0');
            $stmt->execute(['patient_id' => $patientId, 'arzt_id' => $user['id']]);
            $messages[] = 'Patient wurde als erledigt markiert.';
            $activeTab = 'offen';
            break;

        case 'transfer_patient':
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            $newDoctorId = (int) ($_POST['new_arzt_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE patient_leistung SET arzt_id = :new_arzt_id WHERE patient_id = :id AND erledigt = 0');
            $stmt->execute(['new_arzt_id' => $newDoctorId, 'id' => $patientId]);
            $messages[] = 'Offene Leistungen wurden verschoben.';
            $activeTab = 'suche';
            break;

        case 'transfer_group':
            $newDoctorId = (int) ($_POST['new_arzt_id'] ?? 0);
            $rawIds = trim($_POST['patient_ids'] ?? '');
            $ids = array_values(array_filter(array_map('intval', explode(',', $rawIds))));
            if (!$ids || $newDoctorId <= 0) {
                $errors[] = 'Ungültige Transferdaten.';
                break;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE patient_leistung SET arzt_id = ? WHERE erledigt = 0 AND patient_id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$newDoctorId], $ids));
            $messages[] = 'Offene Leistungen wurden zum gewählten Arzt verschoben.';
            $activeTab = 'suche';
            break;
    }
}

if (!$user) {
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Praxis Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-body">
<div class="auth-card">
    <h1>Arzt-Login</h1>
    <p>Bitte Username und Passwort eingeben.</p>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $message): ?>
        <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endforeach; ?>

    <?php if ($pendingRegistration): ?>
        <div class="alert warning">
            Der Arzt "<?= htmlspecialchars($pendingRegistration['username']) ?>" existiert nicht. Möchtest du den Login speichern?
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="register_doctor">
                <input type="hidden" name="username" value="<?= htmlspecialchars($pendingRegistration['username']) ?>">
                <input type="hidden" name="password" value="<?= htmlspecialchars($pendingRegistration['password']) ?>">
                <button type="submit">Ja, speichern</button>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="login">
        <label>Username<input type="text" name="username" required></label>
        <label>Password<input type="password" name="password" required></label>
        <button type="submit">Einloggen</button>
    </form>
</div>
</body>
</html>
<?php
    exit;
}

$doctors = $pdo->query('SELECT id, name FROM arzt ORDER BY name')->fetchAll();
$services = $pdo->query('SELECT * FROM leistung ORDER BY bezeichnung')->fetchAll();
$offenePatientenStmt = $pdo->prepare('SELECT p.*, o.plz, o.ort
    FROM patient p
    LEFT JOIN ort o ON o.id = p.ort_id
    WHERE EXISTS (
        SELECT 1 FROM patient_leistung pl
        WHERE pl.patient_id = p.id AND pl.arzt_id = :arzt_id AND pl.erledigt = 0
    )
    ORDER BY p.nachname, p.vorname');
$offenePatientenStmt->execute(['arzt_id' => $user['id']]);
$offenePatienten = $offenePatientenStmt->fetchAll();

$search = trim($_GET['q'] ?? '');
$searchSql = 'SELECT p.*, o.plz, o.ort FROM patient p
LEFT JOIN ort o ON o.id = p.ort_id';
$params = [];
if ($search !== '') {
    $searchSql .= ' WHERE p.vorname LIKE :q OR p.nachname LIKE :q';
    $params['q'] = '%' . $search . '%';
}
$searchSql .= ' ORDER BY p.nachname, p.vorname';
$stmt = $pdo->prepare($searchSql);
$stmt->execute($params);
$searchPatients = $stmt->fetchAll();

$groupedPatients = [];
foreach ($searchPatients as $patient) {
    $key = (string) $patient['id'];
    $groupedPatients[$key] = [
        'vorname' => $patient['vorname'],
        'nachname' => $patient['nachname'],
        'arzt_name' => '',
        'arzt_id' => 0,
        'all_done' => true,
        'patient_ids' => [(int) $patient['id']],
        'open_patient_ids' => [(int) $patient['id']],
    ];
}

$patientDetailsByGroup = [];
if ($groupedPatients) {
    $allIds = [];
    foreach ($groupedPatients as $group) {
        $allIds = array_merge($allIds, $group['patient_ids']);
    }
    $allIds = array_values(array_unique($allIds));
    if ($allIds) {
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $sql = "SELECT pl.patient_id, pl.arzt_id, pl.erledigt, l.bezeichnung, l.preis, pl.kostentraeger, pl.datum, a.name AS arzt_name
                FROM patient_leistung pl
                JOIN leistung l ON l.id = pl.leistung_id
                JOIN arzt a ON a.id = pl.arzt_id
                WHERE pl.patient_id IN ($placeholders)
                ORDER BY pl.datum DESC";
        $detailStmt = $pdo->prepare($sql);
        $detailStmt->execute($allIds);
        $allDetails = $detailStmt->fetchAll();

        foreach ($groupedPatients as $key => $group) {
            $idLookup = array_flip($group['patient_ids']);
            $leistungen = array_values(array_filter(
                $allDetails,
                static fn(array $detail): bool => isset($idLookup[(int) $detail['patient_id']])
            ));
            $patientDetailsByGroup[$key] = $leistungen;

            $allDone = true;
            $doctorNames = [];
            $firstOpenDoctorId = 0;
            foreach ($leistungen as $leistung) {
                $doctorNames[] = $leistung['arzt_name'];
                if ((int) ($leistung['erledigt'] ?? 0) === 0) {
                    $allDone = false;
                }
            }
            $doctorNames = array_values(array_unique(array_filter($doctorNames)));
            if (count($doctorNames) === 1) {
                $groupedPatients[$key]['arzt_name'] = $doctorNames[0];
            } elseif (count($doctorNames) > 1) {
                $groupedPatients[$key]['arzt_name'] = 'Mehrere Ärzte';
            } else {
                $groupedPatients[$key]['arzt_name'] = 'Unbekannt';
            }

            foreach ($leistungen as $leistung) {
                if ((int) ($leistung['erledigt'] ?? 0) === 0) {
                    $firstOpenDoctorId = (int) $leistung['arzt_id'];
                    break;
                }
            }

            $groupedPatients[$key]['arzt_id'] = $firstOpenDoctorId;
            $groupedPatients[$key]['all_done'] = $allDone;
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Praxis Dashboard</title>
    <link rel="stylesheet" href="styles.css">
    <script defer src="app.js"></script>
</head>
<body>
<header class="topbar">
    <div>
        <h1>Dashboard</h1>
        <small>Angemeldet als <?= htmlspecialchars($user['name']) ?></small>
    </div>
    <a class="logout" href="logout.php">Logout</a>
</header>

<main class="container">
    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $message): ?>
        <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endforeach; ?>

    <nav class="tabs">
        <a class="tab <?= $activeTab === 'offen' ? 'active' : '' ?>" href="?tab=offen">Offene Patienten</a>
        <a class="tab <?= $activeTab === 'patient-hinzufuegen' ? 'active' : '' ?>" href="?tab=patient-hinzufuegen">Patient hinzufügen</a>
        <a class="tab <?= $activeTab === 'suche' ? 'active' : '' ?>" href="?tab=suche">Suche & Transfer</a>
    </nav>

    <section class="panel <?= $activeTab === 'offen' ? 'show' : '' ?>">
        <h2>Offene Patienten</h2>
        <?php if (!$offenePatienten): ?>
            <p>Keine offenen Patienten vorhanden.</p>
        <?php endif; ?>
        <?php foreach ($offenePatienten as $patient): ?>
            <article class="patient-card">
                <div>
                    <strong><?= htmlspecialchars($patient['vorname'] . ' ' . $patient['nachname']) ?></strong>
                    <p><?= htmlspecialchars($patient['strasse'] . ' ' . $patient['hausnummer']) ?>, <?= htmlspecialchars($patient['plz'] . ' ' . $patient['ort']) ?></p>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="mark_done">
                    <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                    <button type="submit">Erledigt abhaken</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel <?= $activeTab === 'patient-hinzufuegen' ? 'show' : '' ?>">
        <h2>Patient hinzufügen</h2>
        <form method="post" class="form-grid two-cols">
            <input type="hidden" name="action" value="add_patient">
            <label>Vorname<input name="vorname" required></label>
            <label>Nachname<input name="nachname" required></label>
            <label>Straße<input name="strasse" required></label>
            <label>Hausnummer<input name="hausnummer" required></label>
            <label>PLZ<input name="plz" required></label>
            <label>Ort<input name="ort" required></label>

            <label>Leistung
                <select name="leistung_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?= (int) $service['id'] ?>"><?= htmlspecialchars($service['bezeichnung']) ?> (<?= number_format((float) $service['preis'], 2, ',', '.') ?>€)</option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Kostenträger
                <select name="kostentraeger" required>
                    <option value="krankenkasse">Krankenkasse</option>
                    <option value="selbstzahler">Patient selbst</option>
                </select>
            </label>
            <button type="submit">Patient speichern</button>
        </form>

        <h3>Leistung hinzufügen</h3>
        <form method="post" class="form-grid service-form">
            <input type="hidden" name="action" value="add_service">
            <label>Bezeichnung<input name="bezeichnung" required></label>
            <label>Preis (€)<input type="number" min="0" step="0.01" name="preis" required></label>
            <button type="submit">Leistung speichern</button>
        </form>
    </section>

    <section class="panel <?= $activeTab === 'suche' ? 'show' : '' ?>">
        <h2>Patienten suchen, ansehen & verschieben</h2>
        <form method="get" class="search-row">
            <input type="hidden" name="tab" value="suche">
            <input type="text" name="q" placeholder="Name suchen..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Suchen</button>
        </form>

        <?php foreach ($groupedPatients as $groupKey => $patient): ?>
            <article class="patient-card stacked">
                <div>
                    <strong><?= htmlspecialchars($patient['vorname'] . ' ' . $patient['nachname']) ?></strong>
                    <span class="badge <?= $patient['all_done'] ? 'done' : 'open' ?>">
                        <?= $patient['all_done'] ? 'Erledigt' : 'Offen' ?>
                    </span>
                    <p>Aktueller Arzt: <?= htmlspecialchars($patient['arzt_name']) ?></p>
                </div>
                <details>
                    <summary>Leistungen anzeigen</summary>
                    <ul>
                        <?php $leistungen = $patientDetailsByGroup[$groupKey] ?? []; ?>
                        <?php foreach ($leistungen as $leistung): ?>
                            <li>
                                <?= htmlspecialchars($leistung['datum']) ?> - <?= htmlspecialchars($leistung['bezeichnung']) ?>
                                (<?= number_format((float) $leistung['preis'], 2, ',', '.') ?>€, <?= htmlspecialchars($leistung['kostentraeger']) ?>, Arzt: <?= htmlspecialchars($leistung['arzt_name']) ?>, Status: <?= ((int) ($leistung['erledigt'] ?? 0) === 1) ? 'Erledigt' : 'Offen' ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>

                <?php if (!$patient['all_done']): ?>
                    <form method="post" class="transfer-form">
                        <input type="hidden" name="action" value="transfer_group">
                        <input type="hidden" name="patient_ids" value="<?= htmlspecialchars(implode(',', $patient['open_patient_ids'])) ?>">
                        <select name="new_arzt_id" required>
                            <?php foreach ($doctors as $doctor): ?>
                                <option value="<?= (int) $doctor['id'] ?>" <?= ((int) $doctor['id'] === (int) $patient['arzt_id'] && $patient['arzt_name'] !== 'Mehrere Ärzte') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($doctor['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Zu anderem Arzt schieben</button>
                    </form>
                <?php else: ?>
                    <small>Erledigte Patienten können nicht mehr verschoben werden.</small>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
