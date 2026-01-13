<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);

require $ROOT . '/app/bootstrap.php';
require $ROOT . '/app/tools/sf-auswertung/module.php';

$report = sf_auswertung_report([
    'type' => $_GET['type'] ?? 'both',
    'from' => $_GET['from'] ?? '',
    'to'   => $_GET['to'] ?? '',
]);

$title = 'SF Auswertung – Report';
$view  = 'sf-auswertung/report.php';
$viewFile = $ROOT . '/app/views/sf-auswertung/report.php';

require $ROOT . '/app/views/layout.php';
