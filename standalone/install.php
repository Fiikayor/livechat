<?php
declare(strict_types=1);

// The setup page. Open it once in your browser, fill in the form, done.
// After that it refuses to run again.
require __DIR__ . '/autoload.php';

use LiveChat\Standalone\{Installer, Session};

$installer = new Installer(__DIR__, __DIR__ . '/schema');

$h = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = fn (string $key, string $default = ''): string => $h($_POST[$key] ?? $default);

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

// where is this folder on the web? used for links in emails
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
    $host = 'localhost';
}
$folder = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl = ($https ? 'https' : 'http') . '://' . $host . $folder;

// one-time token, so another website can't submit this form for you
Session::open();
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['install_csrf'];
session_write_close();

$errors = [];
$done = false;

if ($installer->isInstalled()) {
    http_response_code(403);
    $state = 'locked';
} else {
    $state = 'form';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            $errors[] = 'The form expired. Reload the page and try again.';
        } else {
            $errors = $installer->install($_POST, ['base_url' => $baseUrl, 'host' => $host]);
            if (!$errors) {
                $state = 'done';
            }
        }
    }
}

$req = $installer->requirements();
$driver = $_POST['driver'] ?? ($req['sqlite']['ok'] ? 'sqlite' : 'mysql');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LiveChat setup</title>
<style>
    :root { color-scheme: light dark; --line: #8884; --accent: #2563eb; }
    body { font: 16px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 2rem auto; padding: 0 1rem; }
    h1 { margin-bottom: .25rem; }
    h2 { font-size: 1.05rem; margin: 2rem 0 .5rem; }
    label { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
    label.choice { font-weight: 400; margin: .25rem 0; }
    small { display: block; opacity: .7; font-weight: 400; }
    input[type=text], input[type=email], input[type=password], input[type=number], select {
        width: 100%; padding: .55rem; font: inherit; border: 1px solid var(--line); border-radius: 6px; box-sizing: border-box; }
    button { margin-top: 1.5rem; padding: .7rem 1.4rem; font: inherit; font-weight: 600; color: #fff;
        background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
    button:disabled { opacity: .4; cursor: not-allowed; }
    .box { border: 1px solid var(--line); border-radius: 8px; padding: .75rem 1rem; margin: 1rem 0; }
    .error { border-color: #dc2626; background: #dc262622; }
    .good { color: #16a34a; } .bad { color: #dc2626; }
    ul { margin: .25rem 0; padding-left: 1.2rem; }
    code { background: #8882; padding: .1rem .3rem; border-radius: 4px; }
</style>
</head>
<body>
<h1>LiveChat setup</h1>

<?php if ($state === 'locked'): ?>
    <div class="box">
        <strong>LiveChat is already installed.</strong>
        <p>This page is switched off so nobody can run setup again. To start over, delete
        <code>storage/config.php</code> and the database file inside <code>storage/</code>.</p>
    </div>

<?php elseif ($state === 'done'): ?>
    <div class="box">
        <strong>All set.</strong>
        <p>Your account is ready. You can log in with <strong><?= $old('admin_email') ?></strong> once the dashboard is in place.</p>
        <p>Check that the API is alive:
            <a href="<?= $h($baseUrl) ?>/api.php/config"><?= $h($baseUrl) ?>/api.php/config</a>
            should show a little JSON.</p>
        <?php if (($_POST['mail_driver'] ?? '') === 'log'): ?>
            <p>Email is set to <em>log to a file</em>, so notifications go to <code>storage/mail.log</code> instead of being sent.</p>
        <?php endif; ?>
        <p>You can delete <code>install.php</code> now. It no longer does anything.</p>
    </div>

<?php else: ?>
    <p>Two minutes, one form.</p>

    <div class="box">
        <strong>Server check</strong>
        <ul>
        <?php foreach ($req as $r): ?>
            <li class="<?= $r['ok'] ? 'good' : 'bad' ?>"><?= $r['ok'] ? '&#10003;' : '&#10007;' ?> <?= $h($r['text']) ?></li>
        <?php endforeach; ?>
        </ul>
        <?php if (!$installer->canInstall()): ?>
            <p class="bad">Fix the red items first, then reload this page.</p>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="box error">
            <strong>Please fix this:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">

        <h2>1. Database</h2>
        <label class="choice">
            <input type="radio" name="driver" value="sqlite" <?= $driver === 'sqlite' ? 'checked' : '' ?> <?= $req['sqlite']['ok'] ? '' : 'disabled' ?>>
            SQLite <small>Recommended. Nothing to set up, it's just a file.</small>
        </label>
        <label class="choice">
            <input type="radio" name="driver" value="mysql" <?= $driver === 'mysql' ? 'checked' : '' ?> <?= $req['mysql']['ok'] ? '' : 'disabled' ?>>
            MySQL / MariaDB <small>Fill in the details below. Use an empty database.</small>
        </label>

        <label for="mysql_host">MySQL host</label>
        <input type="text" id="mysql_host" name="mysql_host" value="<?= $old('mysql_host', 'localhost') ?>">
        <label for="mysql_port">MySQL port</label>
        <input type="number" id="mysql_port" name="mysql_port" value="<?= $old('mysql_port', '3306') ?>">
        <label for="mysql_name">Database name</label>
        <input type="text" id="mysql_name" name="mysql_name" value="<?= $old('mysql_name') ?>">
        <label for="mysql_user">Database user</label>
        <input type="text" id="mysql_user" name="mysql_user" value="<?= $old('mysql_user') ?>">
        <label for="mysql_pass">Database password</label>
        <input type="password" id="mysql_pass" name="mysql_pass">

        <h2>2. Your account</h2>
        <label for="admin_name">Your name</label>
        <input type="text" id="admin_name" name="admin_name" value="<?= $old('admin_name') ?>" required>
        <label for="admin_email">Email <small>You'll log in with this.</small></label>
        <input type="email" id="admin_email" name="admin_email" value="<?= $old('admin_email') ?>" required>
        <label for="admin_password">Password <small>At least 10 characters.</small></label>
        <input type="password" id="admin_password" name="admin_password" minlength="10" required>
        <label for="admin_password2">Repeat password</label>
        <input type="password" id="admin_password2" name="admin_password2" minlength="10" required>

        <h2>3. Your site</h2>
        <label for="site_name">Site name <small>Shown in notification emails.</small></label>
        <input type="text" id="site_name" name="site_name" value="<?= $old('site_name', 'Live chat') ?>">
        <label for="notify_email">Send notifications to <small>Leave empty to use your login email.</small></label>
        <input type="email" id="notify_email" name="notify_email" value="<?= $old('notify_email') ?>">
        <label for="mail_driver">Email delivery</label>
        <select id="mail_driver" name="mail_driver">
            <option value="mail" <?= ($_POST['mail_driver'] ?? 'mail') === 'mail' ? 'selected' : '' ?>>Send real emails (use this on a live site)</option>
            <option value="log" <?= ($_POST['mail_driver'] ?? '') === 'log' ? 'selected' : '' ?>>Write to a log file (for testing on your own computer)</option>
        </select>

        <button type="submit" <?= $installer->canInstall() ? '' : 'disabled' ?>>Install</button>
    </form>
<?php endif; ?>
</body>
</html>
