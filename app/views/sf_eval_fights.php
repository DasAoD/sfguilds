<?php
// erwartet u.a.:
// $guilds, $allGuilds
// $monthTitle, $monthKey, $prevMonth, $nextMonth
// $monthStart, $daysInMonth, $firstWeekday
// $countsByGuild, $monthTotalsByGuild
// $selectedGuildId, $selectedDate, $detailsRows
// $filterGuildId
// $isAdminUser, $csrfToken
?>
<style>
	.guild-grid-wrap { max-width: 1400px; margin: 0 auto; }
	.guild-grid {
		display: grid;
		gap: 18px;
		grid-template-columns: repeat(auto-fit, minmax(520px, 1fr));
		align-items: start;
	}
	@media (max-width: 1150px) {
		.guild-grid { grid-template-columns: 1fr; }
	}
	.guild-card {
		padding: 14px;
		border: 1px solid rgba(255,255,255,0.10);
		border-radius: 14px;
		background: rgba(255,255,255,0.03);
	}
	.guild-card h3 { margin: 0; }
	.guild-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

	.guild-crest-mini{
		width:30px;
		height:30px;
		border-radius:8px;
		object-fit:cover;
		border:1px solid rgba(255,255,255,0.10);
		background: rgba(255,255,255,0.02);
		flex:0 0 auto;
	}
	.guild-crest-mini--empty{
		display:inline-block;
		background: rgba(255,255,255,0.05);
	}
</style>

<h2>Kämpfe – Monatsübersicht</h2>

<div style="margin: 10px 0 16px; text-align:center;">
	<div style="display:inline-flex; gap:10px; align-items:center; justify-content:center; flex-wrap:wrap;">
		<a class="btn" href="?m=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?><?= !empty($filterGuildId) ? '&g='.(int)$filterGuildId : '' ?>">‹</a>

		<strong style="font-size:1.1em;"><?= htmlspecialchars($monthTitle, ENT_QUOTES, 'UTF-8') ?></strong>

		<a class="btn" href="?m=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?><?= !empty($filterGuildId) ? '&g='.(int)$filterGuildId : '' ?>">›</a>

		<?php if (!empty($filterGuildId)): ?>
			<a class="btn" href="?m=<?= htmlspecialchars($monthKey, ENT_QUOTES, 'UTF-8') ?>">Alle Gilden</a>
		<?php endif; ?>
	</div>

	<div class="muted" style="margin-top:6px;">
		Tipp: Klick auf einen Tag mit Kämpfen → Details erscheinen darunter.
	</div>
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

<div class="guild-grid-wrap">
	<div class="guild-grid">

		<?php foreach ($guilds as $g): ?>
			<?php
				$gid = (int)$g['id'];
				$name = (string)$g['name'];
				$crestUrl = (string)($g['crest_url'] ?? '');

				$tot = $monthTotalsByGuild[$gid] ?? ['a'=>0,'d'=>0,'t'=>0];

				$day  = 1;
				$cell = 1;
			?>

			<section id="g<?= $gid ?>" class="guild-card">

				<div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px; flex-wrap:wrap;">
					<div class="guild-actions">
						<h3>
							<a href="?m=<?= htmlspecialchars($monthKey, ENT_QUOTES, 'UTF-8') ?>&g=<?= (int)$gid ?>#g<?= (int)$gid ?>"
	  						 style="text-decoration:none; display:inline-flex; align-items:center; gap:10px;">
								<?php if ($crestUrl !== ''): ?>
									<img src="<?= $e($crestUrl) ?>" alt="Wappen" class="guild-crest-mini">
								<?php else: ?>
									<span class="guild-crest-mini guild-crest-mini--empty" aria-hidden="true"></span>
								<?php endif; ?>
								<span><?= $e($name) ?></span>
							</a>
						</h3>

						<?php if (empty($filterGuildId)): ?>
							<a class="btn" style="padding:2px 10px; font-size:0.9em;"
							   href="?m=<?= htmlspecialchars($monthKey, ENT_QUOTES, 'UTF-8') ?>&g=<?= (int)$gid ?>#g<?= (int)$gid ?>">
								Einzeln
							</a>
						<?php endif; ?>
					</div>

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

									$base = '?m=' . rawurlencode($monthKey);
									if (!empty($filterGuildId)) $base .= '&g=' . (int)$filterGuildId;

									$link  = $base . '&gid=' . $gid . '&d=' . rawurlencode($date) . '#g' . $gid;
									$clear = $base . '#g' . $gid;
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
										<?php if (!empty($isAdminUser)): ?>
											<th style="text-align:left;">Aktionen</th>
										<?php endif; ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($detailsRows as $r): ?>
										<tr>
											<td><?= $e((string)$r['time']) ?></td>
											<td><?= $e((string)$r['kind']) ?></td>
											<td><?= $e((string)$r['opp']) ?></td>

											<?php if (!empty($isAdminUser)): ?>
												<td>
													<form method="post" action="" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
														<input type="hidden" name="csrf_token" value="<?= $e((string)$csrfToken) ?>">
														<input type="hidden" name="m" value="<?= $e((string)$monthKey) ?>">
														<input type="hidden" name="g" value="<?= (int)$filterGuildId ?>">
														<input type="hidden" name="gid" value="<?= (int)$selectedGuildId ?>">
														<input type="hidden" name="d" value="<?= $e((string)$selectedDate) ?>">
														<input type="hidden" name="battle_id" value="<?= (int)$r['id'] ?>">

														<button class="btn" name="action" value="delete_battle"
														        onclick="return confirm('Diesen Kampf wirklich löschen?');">
															Löschen
														</button>

														<select name="new_guild_id" style="padding:6px;">
															<?php foreach (($allGuilds ?? []) as $gg): ?>
																<option value="<?= (int)$gg['id'] ?>" <?= ((int)$gg['id'] === (int)$selectedGuildId) ? 'selected' : '' ?>>
																	<?= $e((string)$gg['name']) ?>
																</option>
															<?php endforeach; ?>
														</select>

														<button class="btn" name="action" value="move_battle">
															Verschieben
														</button>
													</form>
												</td>
											<?php endif; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				<?php endif; ?>

			</section>
		<?php endforeach; ?>

	</div>
</div>
