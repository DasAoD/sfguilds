<h2>Administration</h2>

<?php if (empty($GLOBALS["__flash_rendered"]) && !empty($msg_ok)): ?>
<p class="flash flash-ok"><?= e($msg_ok) ?></p>
<?php endif; ?>

<?php if (empty($GLOBALS["__flash_rendered"]) && !empty($msg_err)): ?>
<p class="flash flash-err"><?= e($msg_err) ?></p>
<?php endif; ?>

<?php if (empty($usersExist)): ?>
<div class="card" style="padding:12px;">
    <h3>Erster Admin-User</h3>
	
    <?php if (!empty($setupAllowed)): ?>
	<p class="muted" style="margin-top:0;">
        Es ist noch kein Admin-User vorhanden. Lege jetzt den ersten Admin an.
	</p>
	
	<form method="post" action="<?= e(url("/admin/")) ?>" class="form" style="max-width:520px;">
        <input type="hidden" name="action" value="create_first_user">
		
        <label>Benutzername
			<input type="text" name="username" required>
		</label>
		
        <label>Passwort
			<input type="password" name="password" required>
		</label>
		
        <label>Passwort (wiederholen)
			<input type="password" name="password2" required>
		</label>
		
        <button type="submit">Admin-User anlegen</button>
	</form>
    <?php else: ?>
	<p>
        Setup ist gesperrt. Um den ersten Admin-User anlegen zu dürfen, musst du diese Datei anlegen:
	</p>
	<pre class="box" style="white-space:pre-wrap;">touch <?= e(
		(string) ($setupFlag ?? "storage/allow_setup"),
	) ?></pre>
	<p class="muted">Danach /admin/ neu laden.</p>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="admin-grid">
	
    <!-- LINKE SPALTE -->
    <div class="admin-col">
		
		<?php
			$editGuild = null;
			if (!empty($editId)) {
				foreach ($guilds ?? [] as $gg) {
					if ((int) ($gg["id"] ?? 0) === (int) $editId) {
						$editGuild = $gg;
						break;
					}
				}
			}
		?>
		
		<?php if (!empty($editGuild)): ?>
        <div class="card" style="padding:12px;">
			<h3>Gilde bearbeiten</h3>
			<form method="post" action="<?= e(url("/admin/")) ?>" class="guild-form">
				<input type="hidden" name="action" value="update_guild">
				<input type="hidden" name="id" value="<?= (int) $editGuild["id"] ?>">
				
				<div class="grid">
					<div class="field">
						<label>Gildenname</label>
						<input type="text" name="name" required value="<?= e(
							(string) $editGuild["name"],
						) ?>">
					</div>
					
					<div class="field">
						<label>Server</label>
						<input type="text" name="server" required value="<?= e(
							(string) $editGuild["server"],
						) ?>">
					</div>
					
					<div class="field">
						<label>Tag</label>
						<input type="text" name="tag" placeholder="Optional" value="<?= e(
							(string) ($editGuild["tag"] ?? ""),
						) ?>">
					</div>
					
					<div class="field">
						<label>Notiz</label>
						<textarea name="notes" rows="1" placeholder="Optionale Beschreibung"><?= e(
							(string) ($editGuild["notes"] ?? ""),
						) ?></textarea>
					</div>
				</div>
				
				<div class="row" style="margin-top:10px; justify-content:flex-start;">
					<button type="submit">Speichern</button>
					<a class="btn" style="padding:10px;" href="<?= e(url("/admin/")) ?>">Abbrechen</a>
				</div>
			</form>
		</div>
		<?php endif; ?>
		
		<!-- Gilde anlegen -->
		<div class="card" style="padding:12px;">
			<h3>Gilde anlegen</h3>
			<form method="post" action="<?= e(url("/admin/")) ?>" class="guild-form">
				<input type="hidden" name="action" value="add_guild">
				
				<!-- 2-Spalten-Layout: Name/Server oben, Tag/Notiz darunter -->
				<div class="grid">
					<div class="field">
						<label>Gildenname</label>
						<input type="text" name="name" required>
					</div>
					
					<div class="field">
						<label>Server</label>
						<input type="text" name="server" required>
					</div>
					
					<div class="field">
						<label>Tag</label>
						<input type="text" name="tag" placeholder="Optional">
					</div>
					
					<div class="field">
						<label>Notiz</label>
						<textarea name="notes" rows="1" placeholder="Optionale Beschreibung"></textarea>
					</div>
				</div>
				
				<button type="submit" style="margin-top:10px;">Speichern</button>
			</form>
		</div>
		
		<!-- Gildenliste -->
		<div class="card" style="padding:12px;">
			<h3>Gilden</h3>
			
			<?php if (empty($guilds)): ?>
			<p>Es sind keine Gilden vorhanden.</p>
			<?php else: ?>
			<div class="table-wrap" style="max-height:unset;">
				<table class="table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Name</th>
							<th>Server</th>
							<th>Tag</th>
							<th>Notiz</th>
							<th>Aktionen</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($guilds as $g): ?>
						<tr>
							<td><?= (int) $g["id"] ?></td>
							<td><?= e((string) $g["name"]) ?></td>
							<td><?= e((string) $g["server"]) ?></td>
							<td><?= e((string) ($g["tag"] ?? "")) ?></td>
							<td><?= e((string) ($g["notes"] ?? "")) ?></td>
							<td class="actions">
								<a class="btn small" href="<?= e(
									url("/admin/?edit=" . (int) $g["id"]),
								) ?>">Bearbeiten</a>
								<a class="btn small" href="<?= e(
									url("/admin/members.php?guild_id=" . (int) $g["id"]),
								) ?>">Mitglieder</a>
								<form method="post" action="<?= e(url("/admin/")) ?>" style="display:inline;">
									<input type="hidden" name="action" value="delete_guild">
									<input type="hidden" name="id" value="<?= (int) $g["id"] ?>">
									<button class="small danger" type="submit" onclick="return confirm('Gilde wirklich löschen?');">
										Löschen
									</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
	
    <!-- RECHTE SPALTE -->
    <div class="admin-col">
		<div class="card" style="padding:12px;">
			<h3>Admin-User</h3>
			
			<form method="post" action="<?= e(url("/admin/")) ?>" class="form">
				<input type="hidden" name="action" value="add_user">
				
				<label>Benutzername
					<input type="text" name="username" required>
				</label>
				
				<label>Passwort
					<input type="password" name="password" required>
				</label>
				
				<label>Passwort (wiederholen)
					<input type="password" name="password2" required>
				</label>
				
				<button type="submit">User anlegen</button>
			</form>
			
			<?php if (!empty($users)): ?>
			<h4 style="margin-bottom:6px;">Vorhandene Benutzer</h4>
			<div class="table-wrap" style="max-height:unset;">
				<table class="table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Benutzer</th>
							<th>Erstellt am</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($users as $u): ?>
						<tr>
							<td><?= (int) $u["id"] ?></td>
							<td><?= e((string) $u["username"]) ?></td>
							<!-- <td><?= e(formatDateDE($u["created_at"] ?? "")) ?></td> -->
							<td class="muted">
								<?= e(formatDateDE($u["created_at"] ?? "")) ?>
								<details style="display:inline-block; margin-left:10px;">
									<summary class="btn" style="display:inline-block; cursor:pointer;">Passwort ändern</summary>
									
									<form method="post" action="<?= e(
										url("/admin/"),
										) ?>" style="display:inline-block; margin-left:10px;">
										<input type="hidden" name="action" value="change_user_password">
										<input type="hidden" name="id" value="<?= (int) $u["id"] ?>">
										
										<input type="password" name="password" placeholder="Neues Passwort" autocomplete="new-password" required>
										<input type="password" name="password2" placeholder="Wiederholen" autocomplete="new-password" required>
										
										<button class="btn" type="submit">Speichern</button>
									</form>
								</details>
								<?php if ((int) ($u["id"] ?? 0) !== (int) ($_SESSION["admin_user_id"] ?? 0)): ?>
								<form method="post" action="<?= e(url("/admin/")) ?>" style="display:inline;">
									<input type="hidden" name="action" value="delete_user">
									<input type="hidden" name="id" value="<?= (int) $u["id"] ?>">
									<button class="btn danger" type="submit"
									onclick="return confirm('User wirklich löschen?');"
									style="margin-left:10px;">
										Löschen
									</button>
								</form>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php endif; ?>
