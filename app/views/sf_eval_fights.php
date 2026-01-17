<?php
// erwartet u.a.:
// $guilds, $monthTitle, $monthKey, $prevMonth, $nextMonth
// $monthStart, $daysInMonth, $firstWeekday
// $countsByGuild, $monthTotalsByGuild
// $selectedGuildId, $selectedDate, $detailsRows
?>
<h2>Kämpfe – Monatsübersicht</h2>

<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin: 10px 0 16px;">
	<a class="btn" href="?m=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">‹</a>
	<strong style="font-size:1.1em;"><?= htmlspecialchars($monthTitle, ENT_QUOTES, 'UTF-8') ?></strong>
	<a class="btn" href="?m=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">›</a>

	<span class="muted" style="margin-left:10px;">
		Tipp: Klick auf einen Tag mit Kämpfen → Details erscheinen darunter.
	</span>
</div>

<?php
$wd = ['Mo','Di','Mi','Do','Fr','Sa','So'];

$e = static function(string $s): string {
	return function_exists('e') ? e($s) : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$fmtDateDe = static function(string $ymd): string {
	$dt = DateTime::createFromFormat('Y-m-d', $ymd);
	return $dt ? $dt->format('d.m.Y') : $ymd;
};
?>

<?php foreach ($guilds as $g): ?>
	<?php
		$gid = (int)$g['id'];
		$name = (string)$g['name'];

		$tot = $monthTotalsByGuild[$gid] ?? ['a'=>0,'d'=>0,'t'=>0];

		// Kalender-Start: zuerst leere Zellen bis zum 1. Wochentag
		$day = 1;
		$cell = 1;
	?>
	<section id="g<?= $gid ?>" style="margin: 18px 0 24px;">
		<div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; flex-wrap:wrap;">
			<h3 style="margin: 0;"><?= $e($name) ?></h3>
			<div class="muted">
				Monat: <strong><?= (int)$tot['t'] ?></strong> —
				A: <strong><?= (int)$tot['a'] ?></strong> /
				V: <strong><?= (int)$tot['d'] ?></strong>
			</div>
		</div>

		<table class="table" style="margin-top:10px; table-layout:fixed; width:100%;">
			<thead>
				<tr>
					<?php foreach ($wd as $n): ?>
						<th style="text-align:center; width:14.285%;"><?= $e($n) ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php
				// Anzahl Zellen: mindestens 5 Wochen, ggf. 6
				// wir rendern dynamisch Zeilen, bis alle Tage ausgegeben sind
				$rendered = false;
				while (!$rendered):
				?>
				<tr>
					<?php for ($i=1; $i<=7; $i++, $cell++): ?>
						<?php
							$isBeforeStart = ($cell < $firstWeekday);
							$isAfterEnd = ($day > $daysInMonth);

							$style = "vertical-align:top; padding:8px; height:74px; text-align:left;";
							if ($isBeforeStart || $isAfterEnd) {
								echo "<td style=\"$style\"></td>";
								continue;
							}

							$counts = $countsByGuild[$gid][$day] ?? ['a'=>0,'d'=>0,'t'=>0];

							$date = sprintf('%s-%02d', $monthKey, $day);
							$isSelected = ($gid === (int)$selectedGuildId && $selectedDate === $date);

							$cellStyle = $style;
							if ($isSelected) {
								$cellStyle .= " outline: 2px solid rgba(255,255,255,0.35); border-radius: 8px;";
							}

							$hasAny = ((int)$counts['t'] > 0);
							$link = '?m=' . rawurlencode($monthKey) . '&gid=' . $gid . '&d=' . rawurlencode($date) . '#g' . $gid;
							$clear = '?m=' . rawurlencode($monthKey) . '#g' . $gid;
						?>
						<td style="<?= $cellStyle ?>">
							<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
								<div style="font-weight:700;"><?= (int)$day ?></div>
								<?php if ($isSelected): ?>
									<a class="btn" style="padding:2px 8px; font-size:0.85em;" href="<?= $e($clear) ?>">x</a>
								<?php endif; ?>
							</div>

							<?php if ($hasAny): ?>
								<div style="margin-top:6px; font-size:0.95em;">
									<a href="<?= $e($link) ?>" style="text-decoration:none;">
										<strong><?= (int)$counts['t'] ?></strong>
										<span class="muted" style="margin-left:6px;">A: <?= (int)$counts['a'] ?> / V: <?= (int)$counts['d'] ?></span>
									</a>
								</div>
							<?php else: ?>
								<div class="muted" style="margin-top:6px; font-size:0.9em;">–</div>
							<?php endif; ?>
						</td>
						<?php $day++; ?>
					<?php endfor; ?>
				</tr>
				<?php
					if ($day > $daysInMonth) $rendered = true;
				endwhile;
				?>
			</tbody>
		</table>

		<?php if ($gid === (int)$selectedGuildId && $selectedDate !== ''): ?>
			<div style="margin-top:12px;">
				<h4 style="margin: 10px 0 8px;">Details für <?= $e($fmtDateDe($selectedDate)) ?></h4>

				<?php if (!$detailsRows): ?>
					<p class="muted">Keine Kämpfe an diesem Tag.</p>
				<?php else: ?>
					<table class="table" style="width:100%;">
						<thead>
							<tr>
								<th style="text-align:left;">Uhrzeit</th>
								<th style="text-align:left;">Typ</th>
								<th style="text-align:left;">Gegner</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($detailsRows as $r): ?>
								<tr>
									<td><?= $e((string)$r['time']) ?></td>
									<td><?= $e((string)$r['kind']) ?></td>
									<td><?= $e((string)$r['opp']) ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
