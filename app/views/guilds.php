<div class="card guilds-card">
	<h2 class="guilds-title">Gilden</h2>
	<?php
		$showTag = false;
		foreach ($guilds as $gg) {
			if (!empty($gg["tag"])) {
				$showTag = true;
				break;
			}
		}
	?>
	<?php if (empty($guilds)): ?>
	<p>Noch keine Gilden drin. Geh kurz in den Admin und leg eine an.</p>
	<?php // oder 'd.m.Y H:i' wenn du Uhrzeit willst
		// Fallback, falls Format mal komisch ist
		// oder 'd.m.Y H:i' wenn du Uhrzeit willst
		// Fallback, falls Format mal komisch ist
		// oder 'd.m.Y H:i' wenn du Uhrzeit willst
		// Fallback, falls Format mal komisch ist
		// oder 'd.m.Y H:i' wenn du Uhrzeit willst
		// Fallback, falls Format mal komisch ist
	else: ?>
	<?php $stats = $stats ?? ($counts ?? null); ?>
	<div class="table-wrap">
		<table class="table guilds-table">
			<thead>
				<tr>
					<th class="crest-col"></th>
					<th>Server</th>
					<th>Gilde</th>
					<?php if ($showTag): ?><th>Tag</th><?php endif; ?>
					<th>Aktiv</th>
					<th>Datenstand</th>
				</tr>
			</thead>
			
			<tbody>
				<?php foreach ($guilds as $g): ?>
				<tr>
					<td class="crest-col">
						<?php if (!empty($g["crest_file"])): ?>
						<a href="<?= e(url("/guild/" . (int) $g["id"])) ?>" title="Zur Gilde">
							<img
							src="<?= e(url("/uploads/crests/" . (string) $g["crest_file"])) ?>"
							alt=""
							class="crest-mini"
							loading="lazy"
							>
						</a>
						<?php endif; ?>
					</td>
					<td><?= e((string) $g["server"]) ?></td>
					<td>
						<a class="btn" href="<?= e(url("/guild/" . (int) $g["id"])) ?>">
							<?= e((string) $g["name"]) ?>
						</a>
					</td>
					<?php if ($showTag): ?><td><?= e((string) ($g["tag"] ?? "")) ?></td><?php endif; ?>
					<?php
						$lastImport = (string) ($g["last_import_at"] ?? "");
						$lastImportText = "—";
						if ($lastImport !== "") {
							try {
								$dt = new DateTime($lastImport);
								$dt->setTimezone(new DateTimeZone("Europe/Berlin"));
								$lastImportText = $dt->format("d.m.Y");
								} catch (Throwable $e) {
								$lastImportText = $lastImport;
							}
						}
					?>
					<td><?= (int) ($g["members_active"] ?? 0) ?></td>
					<td><?= e($lastImportText) ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
