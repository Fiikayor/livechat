# LiveChat: self-hosted chat for any site

![tests](../../actions/workflows/tests.yml/badge.svg)

> **Status: early development.** The framework-agnostic core and API contract are done and tested.
> Widget, dashboard, standalone installer and Laravel package are in progress. See the roadmap below.

A self-hosted live-chat system you own: no monthly fees, no third-party servers, customer conversations stay in
your database. Runs on cheap shared PHP hosting (standalone) or drops into a Laravel app (Composer package).

```
livechat/
├── core/                 PURE PHP 8.1+. No framework, no DB code. This is the product.
│   ├── src/Contracts/    The 5 seams: Storage, Notifier, Transport, AgentAuthenticator, RateLimiter
│   ├── src/Domain/       Conversation, Message, Agent (plain value objects)
│   ├── src/Service/      ChatService (all business rules) + PollingTransport
│   ├── src/Http/         Request / Response / ApiRouter: the API, written once, framework-agnostic
│   ├── src/Support/      Token, Str (mbstring-free), ChatException
│   └── tests/smoke.php   End-to-end flow against in-memory fakes:  php core/tests/smoke.php
├── docs/api.md           The JSON contract the widget + dashboard are built against
├── widget/               (todo) vanilla-JS embed script, prebuilt output committed
├── dashboard/            (todo) prebuilt agent UI, prebuilt output committed
├── standalone/           (todo) installer, PdoStorage, MailNotifier, bootstrap.php. See api.php.example
└── laravel/              (todo) service provider, migrations, EloquentStorage. See LiveChatController.php.example
```

## The rule that keeps this portable
`core/` may only `use` things inside `core/` and PHP built-ins. If you ever type `Illuminate\`, `PDO` or `mail(` inside
`core/src`, move it behind an interface instead. A wrapper is "done" when it only does two jobs:
(1) implement the 5 interfaces, (2) turn its native request into `LiveChat\Core\Http\Request`.

## What each wrapper must implement
| Interface | Standalone | Laravel |
|---|---|---|
| StorageInterface | `PdoStorage` (SQLite default, MySQL optional) | `EloquentStorage` |
| NotifierInterface | `mail()` / PHPMailer | Mail + Notification (queued) |
| TransportInterface | `PollingTransport` | Polling by default; `ReverbTransport` optional |
| AgentAuthenticatorInterface | own login + session | `Auth::user()` + a gate |
| RateLimiterInterface | DB/file counter | `RateLimiter` facade |

## Suggested next steps
1. `standalone/PdoStorage.php` + `schema.sql` (conversations, messages, agents, rate_limits). Run it against the same
   expectations as `smoke.php` so it's proven equivalent to the in-memory fake.
2. Widget (polling loop using `GET /messages?after=`).
3. Dashboard + email notifications.
4. Installer wizard.
5. Laravel wrapper (should take about a day if the core held its line).

## Roadmap
- [x] Core: domain, interfaces, ChatService, API router
- [x] API contract (`docs/api.md`)
- [ ] `standalone/` PdoStorage + SQLite schema
- [ ] Widget (polling)
- [ ] Agent dashboard + email notifications
- [ ] Web installer
- [ ] Laravel package
- [ ] Optional WebSocket/Reverb transport, Web Push

## Contributing
Run the tests with `php core/tests/smoke.php`. Keep `core/` free of framework and database code (see above).
