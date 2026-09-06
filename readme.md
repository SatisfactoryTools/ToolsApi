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
- `GET|POST|PUT|DELETE /v1/mods` and `.../{uuid}/versions...`
- `GET|PUT /v1/settings` - per-user settings blob
- `POST /v1/shares` - freeze a folder/plan subtree into a read-only, point-in-time share;
  `GET /v1/shares/{uuid}` - load a share (public, no auth)
- `GET /v1/shares/visited`, `PUT|DELETE /v1/shares/visited/{uuid}` - the user's
  visited-shares list (capped at 20 entries; see docs/shared-plans-api.md)

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
