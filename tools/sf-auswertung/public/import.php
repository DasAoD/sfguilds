<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/parser.php';
require_once __DIR__ . '/_layout.php';

$pdo = db();

$msg = '';
$err = '';
$summary = null;

$defaultDate = date('Y-m-d');
$defaultTime = date('H:i');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');
    $raw  = trim($_POST['raw'] ?? '');

    if ($date === '' || $time === '' || $raw === '') {
        $err = "Bitte Datum, Uhrzeit und Text angeben.";
    } else {
        try {
            $occurredAt = $date . ' ' . $time;

            $parsed = parse_mail($raw);
            $hash = build_battle_hash(
                $occurredAt,
                $parsed['category'],
                $parsed['opponent_guild'],
                $parsed['participated'],
                $parsed['not_participated']
            );

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO battles (occurred_at, category, opponent_guild, raw_text, content_hash)
                    VALUES (:dt, :cat, :opp, :raw, :hash)
                ");
                $stmt->execute([
                    ':dt' => $occurredAt,
                    ':cat' => $parsed['category'],
                    ':opp' => $parsed['opponent_guild'],
                    ':raw' => $raw,
                    ':hash' => $hash,
                ]);
                $duplicate = false;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $duplicate = true;
                } else {
                    throw $e;
                }
            }

            $battleId = (int)$pdo->query("SELECT id FROM battles WHERE content_hash=" . $pdo->quote($hash))->fetchColumn();
            if ($battleId <= 0) {
                throw new RuntimeException("Battle konnte nicht gespeichert/gefunden werden.");
            }

            $summary = [
                'category' => $parsed['category'],
                'opponent' => $parsed['opponent_guild'],
                'in' => count($parsed['participated']),
                'out' => count($parsed['not_participated']),
            ];

            if ($duplicate) {
                $pdo->rollBack();
                $msg = "Schon vorhanden (Duplikat): {$parsed['category']} gegen '{$parsed['opponent_guild']}' um $occurredAt.";
            } else {
                $upPlayer = $pdo->prepare("
                    INSERT INTO players (name, world, last_level)
                    VALUES (:name, :world, :lvl)
                    ON CONFLICT(name, world) DO UPDATE SET last_level = excluded.last_level
                ");
                $selPlayerId = $pdo->prepare("SELECT id FROM players WHERE name=:name AND world=:world");

                $upAttend = $pdo->prepare("
                    INSERT OR REPLACE INTO attendance (battle_id, player_id, status, level)
                    VALUES (:bid, :pid, :status, :lvl)
                ");

                foreach ($parsed['not_participated'] as $p) {
                    $upPlayer->execute([':name' => $p['name'], ':world' => $p['world'], ':lvl' => $p['level']]);
                    $selPlayerId->execute([':name' => $p['name'], ':world' => $p['world']]);
                    $pid = (int)$selPlayerId->fetchColumn();

                    $upAttend->execute([':bid' => $battleId, ':pid' => $pid, ':status' => 'out', ':lvl' => $p['level']]);
                }

                foreach ($parsed['participated'] as $p) {
                    $upPlayer->execute([':name' => $p['name'], ':world' => $p['world'], ':lvl' => $p['level']]);
                    $selPlayerId->execute([':name' => $p['name'], ':world' => $p['world']]);
                    $pid = (int)$selPlayerId->fetchColumn();

                    $upAttend->execute([':bid' => $battleId, ':pid' => $pid, ':status' => 'in', ':lvl' => $p['level']]);
                }

                $pdo->commit();
                $msg = "Import ok: {$parsed['category']} gegen '{$parsed['opponent_guild']}' ($occurredAt) gespeichert.";
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = "Fehler: " . $e->getMessage();
        }
    }
}

function label_category(string $cat): string {
    return $cat === 'attack' ? 'Angriff' : ($cat === 'defense' ? 'Verteidigung' : $cat);
}

ob_start();
?>
  <?php if ($msg): ?><div class="sf-card sf-msg-ok"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="sf-card sf-msg-err"><?=h($err)?></div><?php endif; ?>

  <div class="sf-card">
    <form method="post">
      <div class="sf-row">
        <div class="sf-field">
          <label>Datum</label>
          <input class="sf-input" type="date" name="date" value="<?=h($_POST['date'] ?? $defaultDate)?>">
        </div>
        <div class="sf-field">
          <label>Uhrzeit</label>
          <input class="sf-input" type="time" name="time" value="<?=h($_POST['time'] ?? $defaultTime)?>">
        </div>
      </div>

      <div class="sf-field" style="margin-top:12px;">
        <label>Post-Text</label>
        <textarea class="sf-textarea" name="raw"><?=h($_POST['raw'] ?? '')?></textarea>
      </div>

      <div class="sf-actions">
        <button class="sf-btn primary" type="submit">Importieren</button>
        <a class="sf-btn" href="report.php">Report ansehen</a>
      </div>
    </form>

    <?php if ($summary): ?>
      <div class="sf-kv">
        <div><strong>Kategorie</strong></div><div><?=h(label_category($summary['category']))?></div>
        <div><strong>Gegnergilde</strong></div><div><?=h($summary['opponent'])?></div>
        <div><strong>Teilgenommen</strong></div><div><?= (int)$summary['in'] ?></div>
        <div><strong>Nicht teilgenommen</strong></div><div><?= (int)$summary['out'] ?></div>
      </div>
    <?php endif; ?>
  </div>
<?php
$content = ob_get_clean();
render_page('Import', 'import', $content);
