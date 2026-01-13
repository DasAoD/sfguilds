<?php
// /var/www/sfguilds/app/views/sf-auswertung/report.php

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$filter = $report['filter'] ?? ['type' => 'both', 'from' => '', 'to' => ''];
$totals = $report['totals'] ?? ['attack' => 0, 'defense' => 0];
$rows   = $report['rows'] ?? [];

function pct(int $yes, int $total): string {
    if ($total <= 0) return '–';
    return number_format(($yes / $total) * 100, 1, ',', '.') . ' %';
}
?>
<div class="container">
    <h1>SF Auswertung</h1>

    <div class="card">
        <h2>Filter</h2>
        <form method="get" action="/sf-auswertung/report.php">
            <div class="form-row">
                <label>Typ</label>
                <select name="type">
                    <option value="both" <?= ($filter['type']==='both'?'selected':'') ?>>Angriff + Verteidigung</option>
                    <option value="attack" <?= ($filter['type']==='attack'?'selected':'') ?>>Nur Angriff</option>
                    <option value="defense" <?= ($filter['type']==='defense'?'selected':'') ?>>Nur Verteidigung</option>
                </select>
            </div>

            <div class="form-row">
                <label>Von (YYYY-MM-DD)</label>
                <input type="text" name="from" value="<?= h($filter['from']) ?>">
            </div>

            <div class="form-row">
                <label>Bis (YYYY-MM-DD)</label>
                <input type="text" name="to" value="<?= h($filter['to']) ?>">
            </div>

            <div class="form-row">
                <button type="submit">Anwenden</button>
                <a class="btn" href="/sf-auswertung/import.php">Zum Import</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Übersicht</h2>
        <p>
            Kämpfe im Filter: Angriff <b><?= (int)$totals['attack'] ?></b> |
            Verteidigung <b><?= (int)$totals['defense'] ?></b>
        </p>
    </div>

    <div class="card">
        <h2>Teilnahme pro Spieler</h2>

        <?php if (!$rows): ?>
            <p>Noch keine Daten. Importier zuerst ein paar Berichte.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Spieler</th>
                        <th>Angriff</th>
                        <th>Angriff %</th>
                        <th>Verteidigung</th>
                        <th>Verteidigung %</th>
                        <th>Gesamt</th>
                        <th>Gesamt %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $aT = (int)$r['attack_total'];
                        $aY = (int)$r['attack_yes'];
                        $dT = (int)$r['defense_total'];
                        $dY = (int)$r['defense_yes'];
                        $gT = $aT + $dT;
                        $gY = $aY + $dY;
                    ?>
                        <tr>
                            <td><?= h($r['name']) ?></td>
                            <td><?= $aY ?> / <?= $aT ?></td>
                            <td><?= h(pct($aY, $aT)) ?></td>
                            <td><?= $dY ?> / <?= $dT ?></td>
                            <td><?= h(pct($dY, $dT)) ?></td>
                            <td><?= $gY ?> / <?= $gT ?></td>
                            <td><?= h(pct($gY, $gT)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
