<?php if (!$guild): ?>
<h2>Gilde nicht gefunden</h2>
<p><a href="/guilds">← zurück zur Gildenliste</a></p>
<?php return; ?>
<?php endif; ?>

<h2><?= e((string) $guild["server"]) ?> – <?= e((string) $guild["name"]) ?></h2>
<?php
	$lastImport = (string) ($guild["last_import_at"] ?? "");
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
<p class="muted" style="margin: 0 0 .75rem 0;">
	Letzte Aktualisierung: <strong><?= e($lastImportText) ?></strong>
</p>
<?php if (!empty($guild["crest_file"])): ?>
<p style="margin: .5rem 0 .75rem 0;">
    <img
	src="<?= e(url("/uploads/crests/" . (string) $guild["crest_file"])) ?>"
	alt="Wappen"
	style="height:300px; width:300px; object-fit:cover; border-radius:12px;"
    >
</p>
<?php endif; ?>

<?php if (isAdmin()): ?>
<p style="margin-top:0; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="<?= e(
        url("/admin/members.php?guild_id=" . (int) $guild["id"] . "#members"),
    ) ?>">Bearbeiten</a>

    <a class="btn" href="<?= e(
        url("/sf-auswertung/report.php?guild_id=" . (int) $guild["id"]),
    ) ?>">Report</a>
</p>
<?php endif; ?>

<div class="box" style="margin: .75rem 0;">
	<strong>Aktiv:</strong> <?= (int) ($guild["members_active"] ?? 0) ?>
</div>
<?php if (empty($members)): ?>
<p>Noch keine Mitglieder drin.</p>
<?php else: ?>
<div class="table-wrap">
    <table class="table members-table">
		<thead>
			<tr>
				<th class="rownum"></th>
				<th>Name</th>
				<th>Level</th>
				<?php if (!empty($isAdmin)): ?>
				<th>Zul. Online</th>
				<th>Gildenbeitritt</th>
				<?php endif; ?>
				<th>Goldschatz</th>
				<th>Lehrmeister</th>
				<th>Ritterhalle</th>
				<th>Gildenpet</th>
				<?php if (!empty($isAdmin)): ?>
				<th>Tage offline</th>
				<th>Entlassen</th>
				<th>Verlassen</th>
				<th>Sonstige Notizen</th>
				<?php endif; ?>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($members as $i => $m): ?>
			<?php $days = memberDaysOffline($m); ?>
			<tr class="<?= e(memberRowClass($m)) ?>">
				<td class="rownum"><?= (int) ($i + 1) ?></td>
				<td><?= e((string) ($m["name"] ?? "")) ?></td>
				<td><?= e((string) ($m["level"] ?? "")) ?></td>
				<?php if (!empty($isAdmin)): ?>
				<td><?= e((string) ($m["last_online"] ?? "")) ?></td>
				<td><?= e((string) ($m["joined_at"] ?? "")) ?></td>
				<?php endif; ?>
				<td><?= e((string) ($m["gold"] ?? "")) ?></td>
				<td><?= e((string) ($m["mentor"] ?? "")) ?></td>
				<td><?= e((string) ($m["knight_hall"] ?? "")) ?></td>
				<td><?= e((string) ($m["guild_pet"] ?? "")) ?></td>
				<?php if (!empty($isAdmin)): ?>
				<td><?= e($days === null ? "" : (string) $days) ?></td>
				<td><?= e(formatDateDE($m["fired_at"] ?? "")) ?></td>
				<td><?= e(formatDateDE($m["left_at"] ?? "")) ?></td>
				<td><?= nl2br(e((string) ($m["notes"] ?? ""))) ?></td>
				<?php endif; ?>
				<td></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>
