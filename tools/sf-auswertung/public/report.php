<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/_layout.php';

$pdo = db();

$category = trim($_GET['category'] ?? 'all');
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to'] ?? '');
$opponent = trim($_GET['opponent'] ?? '');

$where = [];
$params = [];

if ($category === 'attack' || $category === 'defense') {
    $where[] = "b.category = :cat";
    $params[':cat'] = $category;
}

if ($from !== '') {
    $where[] = "b.occurred_at >= :from";
    $params[':from'] = $from . " 00:00";
}
if ($to !== '') {
    $where[] = "b.occurred_at <= :to";
    $params[':to'] = $to . " 23:59";
}

if ($opponent !== '') {
    $where[] = "b.opponent_guild LIKE :opp";
    $params[':opp'] = '%' . $opponent . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
SELECT
  p.name,
  p.world,

  SUM(CASE WHEN b.category='attack' THEN 1 ELSE 0 END) AS total_attack,
  SUM(CASE WHEN b.category='attack' AND a.status='in' THEN 1 ELSE 0 END) AS in_attack,

  SUM(CASE WHEN b.category='defense' THEN 1 ELSE 0 END) AS total_defense,
  SUM(CASE WHEN b.category='defense' AND a.status='in' THEN 1 ELSE 0 END) AS in_defense,

  COUNT(*) AS total_all,
  SUM(CASE WHEN a.status='in' THEN 1 ELSE 0 END) AS in_all

FROM attendance a
JOIN battles b ON b.id = a.battle_id
JOIN players p ON p.id = a.player_id
$whereSql
GROUP BY p.id
ORDER BY (CAST(in_all AS REAL) / NULLIF(total_all,0)) ASC, total_all DESC, p.name COLLATE NOCASE
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function pct(int $in, int $total): string {
    if ($total <= 0) return '—';
    return number_format(($in / $total) * 100, 1, ',', '.') . ' %';
}

ob_start();
?>
  <div class="sf-card">
    <form method="get" class="sf-row">
      <div class="sf-field">
        <label>Kategorie</label>
        <select class="sf-select" name="category">
          <option value="all" <?= $category==='all'?'selected':'' ?>>Alle</option>
          <option value="attack" <?= $category==='attack'?'selected':'' ?>>Angriff</option>
          <option value="defense" <?= $category==='defense'?'selected':'' ?>>Verteidigung</option>
        </select>
      </div>

      <div class="sf-field">
        <label>Von</label>
        <input class="sf-input" type="date" name="from" value="<?=h($from)?>">
      </div>

      <div class="sf-field">
        <label>Bis</label>
        <input class="sf-input" type="date" name="to" value="<?=h($to)?>">
      </div>

      <div class="sf-field" style="min-width:260px;">
        <label>Gegnergilde enthält</label>
        <input class="sf-input" name="opponent" value="<?=h($opponent)?>" placeholder="z.B. Bromelanten">
      </div>

      <div class="sf-field">
        <label>&nbsp;</label>
        <button class="sf-btn primary" type="submit">Filter anwenden</button>
      </div>
    </form>
  </div>

  <div class="sf-card">
    <table class="sf-table">
      <thead>
        <tr>
          <th>Spieler</th>
          <th class="sf-num">Angriff IN</th>
          <th class="sf-num">Angriff Gesamt</th>
          <th class="sf-num">Angriff %</th>
          <th class="sf-num">Verteid. IN</th>
          <th class="sf-num">Verteid. Gesamt</th>
          <th class="sf-num">Verteid. %</th>
          <th class="sf-num">Gesamt IN</th>
          <th class="sf-num">Gesamt</th>
          <th class="sf-num">Gesamt %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $player = $r['name'] . ($r['world'] ? " ({$r['world']})" : '');
        ?>
        <tr>
          <td><?=h($player)?></td>

          <td class="sf-num"><?= (int)$r['in_attack'] ?></td>
          <td class="sf-num"><?= (int)$r['total_attack'] ?></td>
          <td class="sf-num"><?= pct((int)$r['in_attack'], (int)$r['total_attack']) ?></td>

          <td class="sf-num"><?= (int)$r['in_defense'] ?></td>
          <td class="sf-num"><?= (int)$r['total_defense'] ?></td>
          <td class="sf-num"><?= pct((int)$r['in_defense'], (int)$r['total_defense']) ?></td>

          <td class="sf-num"><?= (int)$r['in_all'] ?></td>
          <td class
