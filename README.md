# LiveChat

![tests](../../actions/workflows/tests.yml/badge.svg)

A live chat you run on your own website. Visitors click a chat bubble, you answer from your browser, and the
conversations stay on your own server: no monthly fee, no third-party service.

> **Status: early (0.x).** It works end to end, but read the known limits below before relying on it.

## Install it on your website

1. Download **`livechat-<version>.zip`** from the [Releases](../../releases) page and unzip it.
2. Upload the `livechat` folder to your website with FTP or your host's File Manager.
3. Open `https://yourwebsite.com/livechat/install.php` and fill in the form.

The folder contains `README.txt` with the full step-by-step guide, written for people who don't code.
You need PHP 8.1 or newer. SQLite is the default, so no database setup is needed.

## What's in it

- **A chat widget**: one `<script>` line on your pages, in its own shadow DOM so your site's CSS can't break it.
- **An agent dashboard**: live conversation list, unread markers, sound and desktop alerts, replies, closing chats.
- **A settings page**: copy-and-paste embed code with your colors, change your password, add teammates.
- **Email**: you're emailed when someone writes, and visitors can leave a message when nobody is online.
- **Safety basics**: hashed passwords, login lockout, CSRF protection, spam rate limits, and messages are always
  shown as plain text, never as HTML.

## Known limits

- Use it on the same website where it's installed (no cross-domain embedding yet).
- Not supported yet behind Cloudflare or another proxy (spam protection would treat all visitors as one).
- No "forgot password" email, no automatic upgrades, no file uploads, no typing indicator.
- Messages arrive within about 3 seconds (polling), not instantly.

## For developers

The project is built in layers so the same chat logic can run in a plain-PHP install today and in Laravel later:

```
core/        Framework-free chat logic: rules, the JSON API, and the interfaces it needs from the outside.
standalone/  The upload-and-install version: PDO storage (SQLite/MySQL), login, email, installer.
widget/      The embeddable chat bubble (one JavaScript file).
dashboard/   The agent dashboard and settings page (plain HTML files).
bin/         build-release.php, which assembles the folder you upload.
docs/        The JSON API contract and the install guide that ships with releases.
laravel/     (planned) A Composer package around the same core.
```

Try it on your own machine, from the project folder:

```bash
php -S localhost:8081
# installer:  http://localhost:8081/standalone/install.php   (choose "Write to a log file" for email)
# dashboard:  http://localhost:8081/dashboard/
# demo page:  http://localhost:8081/widget/demo.html
```

Run the tests (they need PHP 8.1+ with `pdo_sqlite`; the MySQL one also needs a database, see its header):

```bash
php core/tests/smoke.php
php standalone/tests/pdo_smoke.php
php standalone/tests/installer_smoke.php
php standalone/tests/team_smoke.php
php standalone/tests/release_smoke.php
```

Build the upload folder:

```bash
php bin/build-release.php --version=0.1.0 --zip     # writes dist/livechat/ and dist/livechat-0.1.0.zip
```

Publish a release: tag a commit with a version and push the tag. GitHub then runs the tests, builds the zip and
attaches it to a new release automatically.

```bash
git tag v0.1.0 && git push origin v0.1.0
```

(In GitHub Desktop: History tab, right-click the commit, **Create Tag**, then **Push origin**.)

## Roadmap

- [x] Framework-free core and JSON API
- [x] SQLite and MySQL storage, web installer
- [x] Chat widget, agent dashboard, settings page, teammates
- [x] One-folder release, built and published automatically
- [ ] Testing on real shared hosting
- [ ] Cross-domain embedding, proxy/Cloudflare support, password recovery
- [ ] Laravel package
- [ ] Optional instant delivery (WebSockets) and browser push notifications

## License

MIT, see [LICENSE](LICENSE).
