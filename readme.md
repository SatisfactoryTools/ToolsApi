Satisfactory Tools API
======================

Backend REST API for [Satisfactory Tools](https://github.com/greeny/). Built on
[Nette](https://nette.org/), [Apitte](https://contributte.org/packages/contributte/apitte.html)
and [Doctrine ORM](https://www.doctrine-project.org/), it serves game version data and
manages user accounts, custom versions, mods, plans and folders.

Requirements
------------

- PHP >= 8.5 (with `ext-curl`, `ext-mysqli`, `ext-json`)
- MySQL / MariaDB
- Composer
- Apache with `mod_rewrite` (the `www/.htaccess` front controller expects it)

Installation
------------

```sh
composer install
chmod -R 777 log temp
cp config/local.template.neon config/local.neon
```

Then edit `config/local.neon` and set:

- `jwtSecret` - a long random string (>= 32 chars) used to sign access tokens
- `oauthCallbackBaseUrl` - the frontend page that receives provider redirects
  (when the frontend moves to another host, change it and re-register the per-provider
  redirect URIs - `{oauthCallbackBaseUrl}/{provider}` - in each provider's developer
  console, or sign-ins fail with a redirect-URI mismatch)
- the `oauth*ClientId` / `oauth*ClientSecret` pairs for the providers you enable
- optionally `oauthSteamApiKey` (Steam Web API key) so Steam sign-ins record the persona
  name and avatar for display; sign-in works without it
- the database `user` / `password` under `nettrine.dbal.connections.default`

The database name (`sftools`) and connection defaults live in `config/common.neon`.
`config/local.neon` is git-ignored and holds all secrets - never commit it.

Create the database schema:

```sh
php bin/console orm:schema-tool:create
```

The document root is `www/`. Point the Apache virtual host there.

Deployment
----------

Every push to `master` runs `.github/workflows/ci.yml`, which builds the release (PHP 8.5,
`composer install --no-dev --optimize-autoloader`, a syntax lint) and then deploys it over
SSH with rsync. Composer never runs on the server - `vendor/` is built in CI and shipped
with the code. The same workflow can be started by hand from the Actions tab.

Set these repository **secrets**:

| Secret | Meaning |
| --- | --- |
| `DEPLOY_SSH_KEY` | Private key of the deploy user (the public half goes in its `authorized_keys`) |
| `DEPLOY_HOST` | Server hostname |
| `DEPLOY_USER` | SSH user |
| `DEPLOY_PATH` | Project root on the server - the Apache document root is `$DEPLOY_PATH/www` |

and, optionally, these repository **variables**: `DEPLOY_PHP_BIN` (path to the PHP 8.5 CLI
if it is not just `php`) and `DEPLOY_HEALTH_URL` (a public endpoint such as
`https://api.example.com/v1/help`; when set, the deploy fails if it does not answer after
the flag is lifted).

The deploy user is expected to be the account the web server runs as, so it owns everything
it writes; the server needs a PHP 8.5 CLI for the schema update. A deploy goes:

1. `touch $DEPLOY_PATH/maintenance.flag` - the API starts answering 503 (see below)
2. rsync the new version in place
3. clear `temp/cache`
4. `php bin/console orm:schema-tool:update --dump-sql --force` (the SQL it runs is printed
   in the job log)
5. clear `temp/cache` again, dropping whatever the console run warmed up, so the first
   request rebuilds against the schema it just applied
6. `rm $DEPLOY_PATH/maintenance.flag` - the new version is live

The rsync runs with `--delete`, so files on the server that are not in the repository are
removed - except `config/local.neon`, `log/`, `temp/`, `www/data/`, `www/desktop/` and
dot-files in the deploy root, which hold state that only exists on the server. `www/data/`
and `www/desktop/` are synced separately without `--delete`, which refreshes the tracked
`.htaccess` files inside them but leaves the generated data and desktop releases alone.

If a step fails, the flag stays in place and the API keeps returning 503 rather than
serving a half-updated version; clear it by hand once the server is sorted out.

Maintenance mode
----------------

While `maintenance.flag` exists in the project root, `www/index.php` answers every request
with `503` and `Retry-After: 30` before it loads the autoloader - so it holds even while
`vendor/` is being overwritten:

```json
{"status": "error", "code": 503, "message": "The API is temporarily unavailable, a deployment is in progress. Please try again in a moment."}
```

CORS headers are still sent and `OPTIONS` preflights still answer `204`, so browsers see the
503 instead of a CORS error. Static files under `www/data/` are served by Apache directly and
keep working. The CLI (`bin/console`) ignores the flag.

Toggle it by hand with:

```sh
touch maintenance.flag   # take the API down
rm maintenance.flag      # bring it back
```

Authentication
--------------

- **Access token**: HS256 JWT, 1 h TTL, sent as `Authorization: Bearer <token>`.
- **Refresh token**: opaque 64-char string stored in the DB (revocable), 30 day TTL,
  rotated on every use.

`JwtMiddleware` validates the bearer token and exposes the `User` on the request
attribute `user` (or `null` when no token is present); an invalid token returns `401`.

Endpoints (v1)
--------------

- `POST /v1/auth/{register,login,refresh,logout,forgot-password,reset-password}`
- `GET|POST /v1/auth/oauth/{providers,{provider}/start,{provider}/callback,connections}`,
  `DELETE /v1/auth/oauth/{provider}` - third-party sign-in (Steam, Discord, GitHub, Google);
  `POST /v1/auth/oauth/desktop/poll` - the desktop app's poll (see "Desktop sign-in" below)
- `GET|PUT /v1/account` - the signed-in user's profile (resolved greeting name, provider
  nicknames/avatars) and their self-chosen display name (see docs/account-and-plan-counts.md)
- `GET|POST /v1/versions` - list / create custom game versions
- `GET /v1/versions/plan-counts` - per-version plan count + last-edit time for the signed-in user
- `GET /v1/versions/{uuid}` - fetch any version by UUID (public; used to load shared versions)
- `POST /v1/versions/world-data` - preview resource-node counts (by type & purity) for a
  seed/mode/purity, via the external world-data-generator (see world-data.md)
- `GET|POST|PUT|DELETE /v1/versions/{version}/folders...` and `.../plans...`
- `GET /v1/plans/{uuid}` - the live, read-only view of a plan (and its subplans) for
  anyone holding its planner URL; 404 unless the owner left `linkAccess` on
- `GET|POST|PUT|DELETE /v1/mods` and `.../{uuid}/versions...`
- `GET|PUT /v1/settings` - per-user settings blob
- `GET /v1/help`, `GET /v1/help/article/{slug}` - the help manifest and a single help
  article; readers normally load the static snapshot under `data/help/` instead and only
  fall back to these
- `GET|POST|PUT|DELETE /v1/help/editor/{articles,categories,images}...`,
  `POST /v1/help/editor/publish` - help article management (see "Help articles" below)
- `POST /v1/shares` - freeze a folder/plan subtree into a read-only, point-in-time share,
  either by id (the caller's own tree, needs a token) or from a tree sent with the request
  (no account needed; see docs/anonymous-shares.md);
  `GET /v1/shares/{uuid}` - load a share (public, no auth)
- `GET /v1/shares/visited`, `PUT|DELETE /v1/shares/visited/{uuid}` - the user's
  visited-shares list (capped at 20 entries; see docs/shared-plans-api.md)
- `GET /v1/legacy-shares/{slug}` - a production line shared through the old
  satisfactorytools.com (public, no auth; see "Legacy shares" below)
- `POST /v1/legacy-solver` - the old tools' production solver, for calculating imported old
  production lines exactly as the old site did (public, no auth; see "Legacy solver" below)
- `PUT /v1/account/supporter` - a supporter's own choices (list name, share badge, gold bar, name heart,
  `welcomed`); `GET /v1/supporters` - the public supporters list (public, no auth);
  `POST /v1/supporters/webhooks/patreon` - Patreon member webhook (see "Supporters" below)
- `GET /v1/meta?path={frontend path}` - link preview metadata (title, description, image)
  of a frontend page, for the frontend's `index.php` to print into `<head>`. Public, always
  200 (defaults for anything unknown). Codex lookups use a per-version index kept in
  `temp/cache`; image URLs are built from the `publicApiUrl` parameter. Text rules are
  tested by `php tests/MetaTextTest.php`.
- `POST /v1/errors` - an error the frontend caught (`{message, details, app, build}`, public, no auth).
  Stored in `client_error`, one row per message (repeats bump `count` and replace `details`). A new
  message is posted to the Discord channel `errorReportDiscordChannelId` by the supporter bot (needs View
  Channel + Send Messages there), and again at most once a day while it keeps happening. Capped at 200
  new messages and 20 Discord posts an hour.

Supporters
----------

Anyone who ever paid on Patreon or PayPal (once is enough) is marked as supporter for good. Nothing is
behind a paywall; supporters get a heart on the plans they share, a gold top bar, a heart next to their
name, an optional entry on the About page's supporters list and, with Discord linked, a Discord role.

Matching goes by email. `supporter_email` holds SHA-256 hashes of the normalised (trimmed, lowercased)
addresses, kept apart from accounts so people without an account yet (or who supported the old tools)
are matched once they sign in. An account is marked (a `supporter` row) when its account email
(`user.email`) or the email of a linked sign-in method (`oauth_identity.email`) is on the list: on every
sign-in, registration and provider link, and whenever new emails are imported. The account email of a
password account is not verified; that is accepted because the list is not public.
`supporter` rows are never removed by a sync, only by `supporters:revoke`.

Commands:

- `supporters:sync` - daily cron job: reads every Patreon member (anyone with a payment, including
  former patrons), PayPal payments from `--since` (default 3 days back; run once with an older date to
  read the history), then hands out the Discord role. Sources without credentials are skipped.
- `supporters:import --source=legacy|paypal|patreon|manual --file=...` - a plain list of emails, or a
  CSV export (PayPal activity download, Patreon members export); rows without money received are
  skipped when there is an amount column.
- `supporters:add <email|login|id>` - an email goes on the list; a login or id marks that account
  directly.
- `supporters:revoke <login|id> [--forget-emails]` - refunds and chargebacks. A Discord role stays.

Credentials go in `local.neon` (see `local.template.neon`):

- **Patreon**: register a client at https://www.patreon.com/portal/registration/register-clients and use
  its *creator's* access and refresh token (they can read members' emails). Refreshed tokens are kept in
  `temp/state/patreon-token.json`; pasting a new access token into `local.neon` overrides that file.
  For instant updates add a webhook to `{api}/v1/supporters/webhooks/patreon` with the `members:create`,
  `members:update` and `members:pledge:*` triggers and put its secret in `supporterPatreonWebhookSecret`.
- **PayPal**: a live REST app with "Transaction search" ticked. PayPal lists payments up to 3 hours late.
- **Discord**: a bot invited with Manage Roles, its role above the supporter role; the bot token, server
  id and role id. A supporter who is not in the server yet gets the role on a later sync.

Desktop sign-in
---------------

The desktop (Tauri) app cannot receive a provider's redirect - it lands on the web
frontend's `{oauthCallbackBaseUrl}/{provider}` page in the user's system browser - so it
signs in by polling:

1. The app calls `POST /v1/auth/oauth/{provider}/start` with `{"desktop": true}` (plus its
   Bearer token to link a provider). The response carries a `pollToken` next to
   `authorizationUrl` and `state`; the app opens `authorizationUrl` in the system browser.
2. The browser finishes on the web frontend, which posts `/callback` as usual. For a
   desktop state the API does not hand out tokens there: it keeps the response body it
   would have sent (tokens + `provider`, or the linked payload; `{"error": ...}` when the
   exchange failed) on the `oauth_state` row and answers the browser with
   `{"desktop": true, "provider": "...", "linked": false}` so the page can tell the user to
   go back to the app. A failed exchange still answers `400` there. The state is
   single-use: a second callback gets `Invalid or expired OAuth state`.
3. Meanwhile the app polls `POST /v1/auth/oauth/desktop/poll` with
   `{"state": "...", "pollToken": "..."}` (no auth): `202 {"pending": true}` until the
   browser is done, then the stored body once - `200`, or `400 {"error": ...}` - and the
   row is deleted. An unknown, expired or non-desktop state or a wrong token gets
   `404 {"error": "Unknown or expired sign-in"}`.

The state lives for `oauth.stateTtl` seconds from `/start` (`config/common.neon`); a result
that lands near the end stays pollable for at least another 60 seconds.

Desktop releases
----------------

The frontend repository's CI publishes the desktop app into `www/desktop/` on this server
(this repository only tracks `www/desktop/.htaccess`; deploys never delete anything else
there):

```
www/desktop/latest.json               updater manifest   https://{api host}/desktop/latest.json
www/desktop/{version}/{file}          installers, update bundles and their .sig files
```

Apache serves them as static files (the front controller only takes paths that are not
existing files), with `Access-Control-Allow-Origin: *`, no directory listings, installers as
`application/octet-stream` and `Cache-Control: no-cache` on `latest.json`, so clients always
revalidate the manifest. Stale versions are not pruned automatically.

Help articles
-------------

Tutorials shown in the app's help panel live in `help_articles` / `help_categories` and are
written through `/v1/help/editor/...`, so they can be changed without deploying anything.
Bodies are Markdown (never HTML) and screenshots are uploaded base64-encoded in a JSON
body, capped at 4 MB and sniffed for a real image type.

Every write rebuilds a static snapshot the readers use:

```
www/data/help/index.json              manifest: categories + published article metadata
www/data/help/articles/{slug}.json    one article, body included
www/data/help/images/{uuid}.{ext}     uploaded screenshots
```

`POST /v1/help/editor/publish` rebuilds it by hand, after editing the database directly.

Editing is limited to accounts with the `help_editor` flag; there is no API that grants it:

```sql
UPDATE user SET help_editor = 1 WHERE login = 'someone';
```

Legacy shares
-------------

The old Satisfactory Tools API (api.satisfactorytools.com) stored shared production lines
in its `shared` table and served them at `GET /v2/share/{slug}/`. They are carried over
into `legacy_share` so the old links keep working after that API is retired:
`GET /v1/legacy-shares/{slug}` answers with the stored document as is - the object
`{ "metadata": {...}, "request": {...} }` at the top level, not wrapped in an envelope -
plus `Cache-Control: public, max-age=86400` (shares never change), or `404` for an unknown
slug.

Until the table has been imported, the old API fills in: when the slug is not stored - or
the `legacy_share` table does not exist at all - and `legacyShareSourceUrl` is set, the
controller fetches `{legacyShareSourceUrl}{slug}/` from the old API (10 s timeout), unwraps
its `{"code": 200, "data": {...}}` envelope and answers with the `data` object in the same
shape as a stored share. The old API's `404` is a `404` here; any other failure there (down,
timeout, unexpected body) is a `502` with `{"error": ..., "detail": ...}`. A document fetched
this way is also stored as a `legacy_share` row when the table exists (a failure to store is
logged and does not fail the response), so the next request is served locally and the
eventual import finds the row unchanged. The parameter lives in `config/common.neon`:

```neon
parameters:
	legacyShareSourceUrl: 'https://api.satisfactorytools.com/v2/share/'   # '' disables the fallback
```

Override it in `config/local.neon`; set it to `''` once the old API is gone (or to serve only
what the table holds). So the table is optional until the import runs - the deploy's schema
update creates it empty, and the fallback fills it on demand. The import command then adds
the rest:

```sh
# straight from the old database
php bin/console legacy-shares:import --dsn="mysql:host=127.0.0.1;dbname=satisfactorytools" --user=root --password=secret

# or from a JSON Lines dump made on the old server
mysql -N -B -r -e "SELECT JSON_OBJECT('slug', slug, 'data', data) FROM shared" satisfactorytools > shared.jsonl
php bin/console legacy-shares:import --file=/path/to/shared.jsonl
```

The dump holds one `{"slug": "...", "data": "..."}` object per line, `data` being the raw
JSON string exactly as stored. Both modes stream the rows in batches (`--batch-size`,
default 500) and upsert by slug, so re-running is safe: rows whose data already matches are
skipped, changed rows are updated, and rows whose slug is not 1-64 alphanumeric characters
or whose data is not a JSON object are counted as invalid and left out. The command prints
progress per batch and a final imported / updated / unchanged / invalid summary. The
`legacy_share` table is created by the usual `orm:schema-tool:create` / `update`.

Legacy solver
-------------

Production lines imported from the old tools (shares, `.sft` files, the old site's local
storage) were calculated by the old API's solver, whose weights and maximise rules differ
from this app's. `POST /v1/legacy-solver` is that solver, ported from the old API
(`app/Model/Legacy/`, the `ProductionSolver` + `LPSolver` pair behind the old
`POST /v2/solver`) with its game data, so an imported line can be shown exactly as the old
site showed it once the old API is retired. Public, no auth.

Request body:

```json
{
  "gameVersion": "1.0.0",
  "request": {
    "production": [{"item": "Desc_IronPlateReinforced_C", "type": "perMinute", "amount": 10, "ratio": 100}],
    "input": [{"item": "Desc_IronIngot_C", "amount": 30}],
    "resourceMax": {"Desc_OreIron_C": 92100},
    "resourceWeight": {"Desc_OreIron_C": 1},
    "blockedResources": [], "blockedRecipes": [], "blockedMachines": [],
    "allowedAlternateRecipes": [], "sinkableResources": []
  }
}
```

`gameVersion` is one of `0.8.0`, `1.0.0`, `1.0.0-ficsmas` (anything else is a `400`);
`request` is the old `IProductionDataRequest` as the old site stored it, read leniently:
missing lists and maps are empty, unknown fields are ignored, production/input rows without
an item are skipped. Two things the old API left to its client happen here instead:
`blockedMachines` is folded into the recipe lists the way the old client did before calling
the API (allowed alternates made in a blocked machine are dropped, base recipes made in a
blocked machine are blocked), and resources missing from `resourceMax` / `resourceWeight`
get the old client's defaults (`LegacyResourceDefaults` - the old API crashed on a missing
key, and with every limit unbounded lp_solve cannot solve a `max` request).

Response: `{"result": {"<column>": <number>, ...}}` with the old solver's column names -
`Recipe_X_C@100#Desc_Machine_C` (machines), `Desc_X_C#Mine`, `Desc_X_C#Product`,
`Desc_X_C#Byproduct`, `Desc_X_C#Input` and the pseudo columns `special__*` /
`Maximise#Product`, which clients skip - as floats (the old API returned strings). An
infeasible or empty solve is `{"result": {}}` with `200`, as on the old API.

The game data is the old frontend's `data/*.json` copied to `data/legacy/{gameVersion}.json`
(outside `www/`, so it is never served; the release tarball includes `data/`). The solver
needs `lp_solve` (lp_solve 5.5, `apt install lp-solve`) - its path and the time limit are
the `legacySolver.lpSolveBin` (default `/usr/bin/lp_solve`) and `legacySolver.timeout`
(default 30 s) parameters in `config/common.neon`; a run over the limit is killed and
answered with `504`, any other lp_solve failure with `500`.

Importing game builds
----------------------

The extractor/publish pipeline writes data files into `www/data/`; register them as
official versions with:

```sh
php bin/console publish --buildId=<steamBuildId> --branch=stable|experimental [--game-version=1.2.3.1] [--skip-world-data]
    [--attach=<slug>]... [--new-version[=default|ficsmas]] [--summary-file=<path>]
```

Unless `--skip-world-data` is given, it also runs the world-data-generator (vanilla settings) and
records the resource-node counts under `metadata.world` in each build's data file (with the
flag, an already stamped `metadata.world` is kept). Files keep the extractor's pretty (4-space)
formatting.

One official version can cover several game builds. Each variant (default / FICSMAS) is
fingerprinted over the data the planner uses - everything except cosmetic fields such as
icons, names, descriptions and stack sizes (`PlannerFingerprint::COSMETIC_FIELDS`). When the
fingerprint matches the newest official version of the same branch and variant, the build is
attached to it: the version keeps its slug, plans, folders and shares, while its `dataPath`
and name move to the new build. Otherwise a new version `<branch>-<buildId>[-ficsmas]` is
created and the output lists the planner changes that caused it. Every build is recorded in
`version_build`; `GET /v1/versions` returns the covered game versions as `gameVersions`
(oldest first). Old data files stay on disk.

Overriding the decision (re-running for the same build is safe):

- `--attach=<slug>` puts the variant matching that version onto it even though its planner data
  differs. If the build had already been created as its own version, that version is deleted -
  but only while no plans, folders, custom versions or shares reference it.
- `--new-version` (both variants) or `--new-version=default|ficsmas` splits the build off into
  its own version; the version it leaves falls back to its previous build.

`--summary-file` writes the per-variant decision as JSON; the extractor uses it for its
notifications.

License
-------

MIT
