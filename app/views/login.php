<div class="login-card card">
<h2>Admin Login</h2>

<?php if (empty($GLOBALS['__flash_rendered']) && !empty($error)): ?>
  <div class="flash flash-err"><?= e((string)$error) ?></div>
<?php endif; ?>

<form method="post" class="login-form">
  <input type="hidden" name="next" value="<?= e($next ?? '/') ?>">
  <div class="row">
    <label>Benutzername
      <input type="text" name="username" required>
    </label>
  </div>

  <div class="row">
    <label>Passwort
      <input type="password" name="password" required>
    </label>
  </div>

  <button type="submit">Einloggen</button>
</form>
</div>
