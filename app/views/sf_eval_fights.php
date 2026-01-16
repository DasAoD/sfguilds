<?php
// erwartet: $guilds (array), $rowsByGuild (array), $perGuildLimit (int)
?>
<h2>Importierte Kämpfe</h2>
<p class="muted">Pro Gilde die letzten <?= (int)$perGuildLimit ?> Einträge (Datum/Uhrzeit + Angriff/Verteidigung).</p>

<?php foreach ($guilds as $g): ?>
	<?php
		$gid = (int)$g['id'];
		$rows = $rowsByGuild[$gid] ?? [];
	?>
	<details open style="margin: 14px 0;">
		<summary style="cursor: pointer;">
			<strong><?= function_exists('e') ? e($g['name']) : htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
			<span class="muted"> (<?= count($rows) ?>)</span>
		</summary>

		<?php if (!$rows): ?>
			<p class="muted" style="margin-top: 8px;">Keine Kämpfe importiert.</p>
		<?php else: ?>
			<table class="table" style="margin-top: 10px; width: 100%;">
				<thead>
					<tr>
						<th style="text-align:left;">Datum / Uhrzeit</th>
						<th style="text-align:left;">Typ</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $r): ?>
						<tr>
							<td><?= function_exists('e') ? e($r['when']) : htmlspecialchars((string)($r['when'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= function_exists('e') ? e($r['kind']) : htmlspecialchars((string)($r['kind'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</details>
<?php endforeach; ?>
