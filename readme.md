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
removed - except `config/local.neon`, `log/`, `temp/`, `www/data/` and dot-files in the
deploy root, which hold state that only exists on the server. `www/data/` is synced
separately without `--delete`, which refreshes the tracked `.htaccess` files inside it but
leaves the generated data alone.

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
  `DELETE /v1/auth/oauth/{provider}` - third-party sign-in (Steam, Discord, GitHub, Google)
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

Importing game builds
----------------------

The extractor/publish pipeline writes data files into `www/data/`; register them as
official versions with:

```sh
php bin/console publish --buildId=<steamBuildId> --branch=stable|experimental [--game-version=1.2.3.1] [--skip-world-data]
```

Re-running for the same build updates the versions in place (idempotent). Unless
`--skip-world-data` is given, it also runs the world-data-generator (vanilla settings) and
records the resource-node counts under `metadata.world` in each build's data file. Files
keep the extractor's pretty (4-space) formatting.

License
-------

MIT
