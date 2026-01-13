<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);

require $ROOT . '/app/bootstrap.php';
require $ROOT . '/app/tools/sf-auswertung/module.php';

// Import handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = sf_auswertung_import_battle($_POST['date'] ?? '', $_POST['time'] ?? '', $_POST['text'] ?? '');

    if ($res['ok']) {
        $_SESSION['sf_auswertung_flash'] = [
            'ok' => true,
            'msg' => 'Import erfolgreich: ' . ($res['battle']['type'] === 'attack' ? 'Angriff' : 'Verteidigung') .
                     ' gegen "' . $res['battle']['opponent'] . '" (' . $res['battle']['players_participated'] . '/' . $res['battle']['players_total'] . ' angemeldet).'
        ];
    } else {
        $_SESSION['sf_auswertung_flash'] = ['ok' => false, 'msg' => $res['error']];
    }

    header('Location: /sf-auswertung/import.php');
    exit;
}

// Render via sfguilds layout
$title = 'SF Auswertung – Import';
$view  = 'sf-auswertung/import.php';
$viewFile = $ROOT . '/app/views/sf-auswertung/import.php';

require $ROOT . '/app/views/layout.php';
