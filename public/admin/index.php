<?php
require_once __DIR__ . '/../../app/bootstrap.php';

// Setup erlaubt, wenn kein User existiert UND allow_setup vorhanden ist
$setupFlag = __DIR__ . '/../../storage/allow_setup';
$setupAllowed = is_file($setupFlag);

$pdo = db();

// Gibt es bereits User?
$usersExist = false;
try {
    $usersExist = ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0);
} catch (Throwable $e) {
    $usersExist = false;
}

if ($usersExist) {
    requireAdmin();
} else {
    // Keine User vorhanden -> Setup nur mit allow_setup Flag
    if (!$setupAllowed) {
        view('admin', [
            'guilds'       => [],
            'users'        => [],
            'editId'       => 0,
            'usersExist'   => false,
            'setupAllowed' => false,
            'setupFlag'    => $setupFlag,
            'msg_err'      => 'Noch kein Admin-User vorhanden. Setup ist gesperrt.',
        ]);
        exit;
    }
}

$msg_ok  = flash_get('ok');
$msg_err = flash_get('err');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        // =========================
        // FIRST ADMIN SETUP
        // =========================
        // Erstes Setup (bei leerer DB). Unterstützt auch alte Form-Namen.
        if ($action === 'create_first_user' || ($action === 'create_user' && !$usersExist)) {
            if ($usersExist || !$setupAllowed) {
                flash_set('err', 'Setup nicht erlaubt.');
                redirect(url('/admin/'));
            }

            $username = trim((string)($_POST['username'] ?? ''));
            $pass1    = (string)($_POST['password'] ?? '');
            $pass2    = (string)($_POST['password2'] ?? '');
            if ($pass2 === '') { $pass2 = $pass1; } // erlaubt 1-Passwort-Feld (ältere Form)

            if ($username === '' || $pass1 === '') {
                flash_set('err', 'Username/Passwort fehlt.');
                redirect(url('/admin/'));
            }
            if ($pass1 !== $pass2) {
                flash_set('err', 'Passwörter stimmen nicht überein.');
                redirect(url('/admin/'));
            }

            $hash = password_hash($pass1, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:u, :h)');
            $stmt->execute([':u' => $username, ':h' => $hash]);

            // Setup-Flag kann bleiben – ist egal, weil usersExist dann true ist
            flash_set('ok', 'Admin-User angelegt. Bitte einloggen.');
            redirect(url('/admin/login.php'));
        }

        // Ab hier: normaler Adminbetrieb
        if (!$usersExist) {
            flash_set('err', 'Kein Admin-User vorhanden.');
            redirect(url('/admin/'));
        }

        // =========================
        // USER: ADD
        // =========================
        // USER: ADD (unterstützt auch alte Form-Namen)
        if ($action === 'add_user' || ($action === 'create_user' && $usersExist)) {
            $username = trim((string)($_POST['username'] ?? ''));
            $pass1    = (string)($_POST['password'] ?? '');
            $pass2    = (string)($_POST['password2'] ?? '');
            if ($pass2 === '') { $pass2 = $pass1; } // erlaubt 1-Passwort-Feld (ältere Form)

            if ($username === '' || $pass1 === '') {
                flash_set('err', 'Username/Passwort fehlt.');
                redirect(url('/admin/'));
            }
            if ($pass1 !== $pass2) {
                flash_set('err', 'Passwörter stimmen nicht überein.');
                redirect(url('/admin/'));
            }

            $hash = password_hash($pass1, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:u, :h)');
            $stmt->execute([':u' => $username, ':h' => $hash]);

            flash_set('ok', 'User angelegt.');
            redirect(url('/admin/'));
        }

        // =========================
        // USER: DELETE
        // =========================
        if ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                flash_set('err', 'Ungültige ID.');
                redirect(url('/admin/'));
            }

            // Schutz: nicht sich selbst löschen (sonst Lock-out)
            $currentId = (int)($_SESSION['admin_user_id'] ?? 0);
            if ($currentId > 0 && $id === $currentId) {
                flash_set('err', 'Du kannst dich nicht selbst löschen.');
                redirect(url('/admin/'));
            }

            // Schutz: nicht den letzten User löschen
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($cnt <= 1) {
                flash_set('err', 'Der letzte User kann nicht gelöscht werden.');
                redirect(url('/admin/'));
            }

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);

            flash_set('ok', 'User gelöscht.');
            redirect(url('/admin/'));
        }

        // =========================
        // USER: PASSWORD CHANGE
        // =========================
        if ($action === 'change_user_password') {
            $id = (int)($_POST['id'] ?? 0);
            $pass1 = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');

            if ($id <= 0) {
                flash_set('err', 'Ungültige User-ID.');
                redirect(url('/admin/'));
            }
            if ($pass1 === '') {
                flash_set('err', 'Passwort fehlt.');
                redirect(url('/admin/'));
            }
            if ($pass1 !== $pass2) {
                flash_set('err', 'Passwörter stimmen nicht überein.');
                redirect(url('/admin/'));
            }

            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $stmt->execute([':h' => $hash, ':id' => $id]);

            flash_set('ok', 'Passwort geändert.');
            redirect(url('/admin/'));
        }

        // =========================
        // GUILD: ADD
        // =========================
        // GUILD: ADD (unterstützt auch alte Form-Namen)
        if ($action === 'add_guild' || $action === 'create_guild') {
            $name   = trim((string)($_POST['name'] ?? ''));
            $server = trim((string)($_POST['server'] ?? ''));
            $tag    = trim((string)($_POST['tag'] ?? ''));
            $notes  = trim((string)($_POST['notes'] ?? ''));

            if ($name === '' || $server === '') {
                flash_set('err', 'Name und Server sind Pflicht.');
                redirect(url('/admin/'));
            }

            $stmt = $pdo->prepare('INSERT INTO guilds (name, server, tag, notes, updated_at) VALUES (:n, :s, :t, :no, datetime(\'now\'))');
            $stmt->execute([
                ':n'  => $name,
                ':s'  => $server,
                ':t'  => ($tag !== '' ? $tag : null),
                ':no' => ($notes !== '' ? $notes : null),
            ]);

            flash_set('ok', 'Gilde angelegt.');
            redirect(url('/admin/'));
        }

        // =========================
        // GUILD: UPDATE
        // =========================
        if ($action === 'update_guild') {
            $id     = (int)($_POST['id'] ?? 0);
            $name   = trim((string)($_POST['name'] ?? ''));
            $server = trim((string)($_POST['server'] ?? ''));
            $tag    = trim((string)($_POST['tag'] ?? ''));
            $notes  = trim((string)($_POST['notes'] ?? ''));

            if ($id <= 0 || $name === '' || $server === '') {
                flash_set('err', 'Ungültige Daten.');
                redirect(url('/admin/'));
            }

            $stmt = $pdo->prepare(
                'UPDATE guilds
                 SET name = :n, server = :s, tag = :t, notes = :no, updated_at = datetime(\'now\')
                 WHERE id = :id'
            );
            $stmt->execute([
                ':n'  => $name,
                ':s'  => $server,
                ':t'  => ($tag !== '' ? $tag : null),
                ':no' => ($notes !== '' ? $notes : null),
                ':id' => $id,
            ]);

            flash_set('ok', 'Gilde gespeichert.');
            redirect(url('/admin/'));
        }

        // =========================
        // GUILD: DELETE (inkl. Members + Wappen löschen)
        // =========================
        if ($action === 'delete_guild') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                flash_set('err', 'Ungültige ID.');
                redirect(url('/admin/'));
            }

            // Wappen-Dateiname vor dem Löschen auslesen (falls vorhanden)
            $stmt = $pdo->prepare('SELECT crest_file FROM guilds WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $crestFile = trim((string)($stmt->fetchColumn() ?: ''));

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('DELETE FROM members WHERE guild_id = :id');
            $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare('DELETE FROM guilds WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $pdo->commit();

            // Nach erfolgreichem DB-Delete: Wappen-Datei löschen (sicher nur innerhalb /uploads/crests)
            if ($crestFile !== '') {
                $baseDir = realpath(__DIR__ . '/../uploads/crests'); // public/uploads/crests
                if ($baseDir !== false) {
                    $target = $baseDir . DIRECTORY_SEPARATOR . basename($crestFile);
                    if (is_file($target)) {
                        @unlink($target);
                    }
                }
            }

            flash_set('ok', 'Gilde (inkl. Mitglieder) gelöscht.');
            redirect(url('/admin/'));
        }

        flash_set('err', 'Unbekannte Aktion.');
        redirect(url('/admin/'));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('err', 'Fehler: ' . $e->getMessage());
        redirect(url('/admin/'));
    }
}

$editId = (int)($_GET['edit'] ?? 0);

// Daten laden
$guilds = [];
$users  = [];

if ($usersExist) {
    $guilds = $pdo->query(
        "SELECT * FROM guilds ORDER BY server COLLATE NOCASE, name COLLATE NOCASE"
    )->fetchAll();

    $users = $pdo->query(
        "SELECT id, username, created_at FROM users ORDER BY id ASC, username COLLATE NOCASE ASC"
    )->fetchAll();
}

view('admin', [
    'guilds'       => $guilds,
    'users'        => $users,
    'editId'       => $editId,
    'usersExist'   => $usersExist,
    'setupAllowed' => $setupAllowed,
    'setupFlag'    => $setupFlag,
    'msg_ok'       => $msg_ok,
    'msg_err'      => $msg_err,
]);
