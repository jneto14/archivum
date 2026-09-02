# Deployment

`compose.prod.yaml` pulls the published image and runs five services. It is not
`compose.yaml`, which is Sail's development stack.

```text
app        FrankenPHP, serving public/          :80
worker     queue:work                           same image
scheduler  schedule:work                        same image, one replica
mysql
redis
```

## Installing

```bash
curl -O https://raw.githubusercontent.com/jneto14/archivum/main/compose.prod.yaml

cat > .env <<EOF
APP_NAME=Archivum
APP_ENV=production
APP_DEBUG=false
APP_URL=https://archivum.example.com
APP_KEY=base64:$(openssl rand -base64 32)

DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=archivum
DB_USERNAME=archivum
DB_PASSWORD=change-me

REDIS_HOST=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=database

SCOUT_DRIVER=database

ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=change-me-too

ARCHIVUM_VERSION=0.1.0
EOF

docker compose --env-file .env -f compose.prod.yaml up -d
docker compose --env-file .env -f compose.prod.yaml exec app php artisan db:seed --force
```

Change `APP_URL`, both passwords and `ADMIN_EMAIL`, and you are done. The rest
is correct as it stands.

Written out rather than copied from `.env.example`, which is the development
file: it points at `127.0.0.1` and connects as `root`, and MySQL's entrypoint
refuses to start at all with `MYSQL_USER=root`.

### Why each line is there

Every variable above is one whose absence would be silently wrong. The ones
worth understanding before you edit them:

| | |
| --- | --- |
| `APP_KEY` | 32 random bytes in base64 — exactly what `artisan key:generate` produces. Changing it later makes every existing session and encrypted value unreadable |
| `DB_CONNECTION` | Laravel's own default is `sqlite`, so omitting this gives a stack that starts and then reports that a database file called "archivum" does not exist |
| `DB_USERNAME` | Anything but `root`. MySQL's entrypoint refuses to start with `MYSQL_USER=root`, and says so only in its own logs |
| `SCOUT_DRIVER` | Scout defaults to `collection`, which filters in PHP and never touches the full-text index this application builds. Search would return plausible results, produced entirely the wrong way |
| `QUEUE_CONNECTION` | Stays on the database on purpose — see [Environment](#environment) below |
| `ADMIN_PASSWORD` | Leave it out and the seeder generates one and prints it **once** |
| `ARCHIVUM_VERSION` | Pin a release. `latest` moves on its own, so an unpinned stack can change version simply by being restarted |

`InstallRecipeTest` checks this block still names the settings whose framework
defaults are dangerous.

`--env-file` matters. Compose reads variables from two places and they are not
the same: `env_file:` inside the file passes them into the containers, while
`${...}` in the file itself is interpolated by compose from the project `.env`
or from `--env-file`. `APP_PORT`, the `DB_*` pair used to create the database,
and `ARCHIVUM_*` are all the second kind, so without the flag they quietly fall
back to their defaults — a stack that ignores `APP_PORT` and tries to bind 80.

Migrations run from the container's entrypoint, so there is no separate step for
them. The seed is what creates the first administrator and the default
workspace — without it there is no account to log in with.

## Images

Published on every `v*` tag to two registries, holding the same `amd64` and
`arm64` images:

```text
jneto14/archivum:0.1.0            Docker Hub
ghcr.io/jneto14/archivum:0.1.0  GHCR
```

Docker Hub is the default because a short name is only ever resolved there —
`docker pull jneto14/archivum` expands to `docker.io/jneto14/archivum` and Docker
looks nowhere else. Switch registries with `ARCHIVUM_IMAGE`, which is worth
doing where Docker Hub's anonymous pull limit is a problem:

```dotenv
ARCHIVUM_IMAGE=ghcr.io/jneto14/archivum
ARCHIVUM_VERSION=0.1.0
```

To build the image yourself rather than pull it:

```bash
docker build -f docker/production/Dockerfile -t jneto14/archivum:local .
ARCHIVUM_VERSION=local docker compose -f compose.prod.yaml up -d
```

`ARCHIVUM_ENV_FILE` points the stack at a different file, which is what makes it
safe to run beside a development checkout — `.env` there is Sail's, with
`APP_ENV=local` and a `DB_HOST` that means something else entirely:

```bash
docker compose --env-file .env.prod -f compose.prod.yaml -p archivum-prod up -d
```

with `ARCHIVUM_ENV_FILE=.env.prod` set inside that file, so both mechanisms
read it. Give it its own `-p` project name and an `APP_PORT` other than 80 and
the two stacks run side by side.

## Changing configuration

Almost all of it is environment variables, and none of those are in the image.
Edit `.env`, then:

```bash
docker compose --env-file .env -f compose.prod.yaml up -d
```

**Not `restart`.** That restarts the same containers with the environment they
were created with, so an edited `.env` has no effect at all:

```text
.env edited, then compose restart  ->  old value, silently
.env edited, then compose up -d    ->  new value
```

This works because the config cache is built by the entrypoint when the
container starts, not when the image is built. That is what makes one image
environment-agnostic: the same `jneto14/archivum:0.1.0` runs anywhere, and
nothing about your installation is baked into it.

`OCR_JOB_TIMEOUT` shows why the distinction bites. It moves three things at
once — the job's timeout, the queue's `retry_after`, and the worker's
`--timeout`, which compose interpolates into the command when it creates the
container. After a `restart` the job would run longer while the worker still
killed it on the old clock.

What *is* in the image is code: `config/*.php`, `docker/production/php.ini`,
the `Caddyfile`. Changing those means a new image version. A bind mount over
one of them works as an escape hatch, but it is not the route to take twice.

## Upgrading

```bash
# Change ARCHIVUM_VERSION in .env, then:
docker compose --env-file .env -f compose.prod.yaml pull
docker compose --env-file .env -f compose.prod.yaml up -d
```

Replacing the containers is what applies the new code, and replacing the worker
**is** `queue:restart` — a running worker holds the old classes in memory and
will not pick up a new job class on its own. Migrations run on start.

## One image, three roles

The three application services differ only by their command. That is
deliberate: attachment text extraction runs on the queue, so a worker built
without tesseract, poppler and ghostscript would fail every extraction while
the web container passed its health check.

The scheduler runs **one replica**. `schedule:work` keeps its own clock, so a
second instance prunes exports and cleans the activity log a second time.

## What each role needs

| | |
| --- | --- |
| Attachments | The `archivum-attachments` volume, mounted at `/app/storage/app`. The rest of `storage/` is per-container cache and logs and must not be shared |
| Migrations | Run by the `app` container on start. Set `ARCHIVUM_MIGRATE=false` to run them yourself |
| Config cache | Built on start by every role, because it bakes in the environment and the environment only exists at run time |
| Deploys | Replacing the worker container **is** `queue:restart`. A running worker holds the old code in memory, so the container has to go, not just the files |

## Environment

`compose.prod.yaml` reads `.env`. At minimum set `APP_KEY`, `APP_URL` and
`DB_PASSWORD`; everything else has a working default in `.env.example`.

Two things differ from development:

```dotenv
CACHE_STORE=redis      # a database cache store makes a cache hit a query
SESSION_DRIVER=redis
QUEUE_CONNECTION=database
REDIS_HOST=redis       # the service name, like DB_HOST=mysql
```

The queue stays on the database on purpose. It carries the app's real work, and
a Redis without persistence loses it on restart, which strands an attachment as
"queued" for good. Cache and sessions are safe to lose; jobs are not.

Redis runs with no eviction policy for the same reason: sessions live on
database 0 and the cache on database 1, and an `allkeys` policy under memory
pressure would sign people out to make room for cache entries.

## Behind a reverse proxy

The stack publishes a plain HTTP port. Anything in front of it — nginx, Caddy,
Traefik, a tunnel — terminates TLS and forwards a request that no longer looks
like the one the browser made. Three settings put that back.

```dotenv
APP_URL=https://archivum.example.com
TRUSTED_PROXIES=*
```

`APP_URL` is the address the outside world uses, and every generated URL is
built from it rather than from the forwarded request. Without that, a proxy that
terminates TLS makes the application believe it was reached over plain HTTP, so
the redirect after signing in comes back as `http://` and the browser either
refuses it or quietly leaves TLS behind.

`TRUSTED_PROXIES` decides whether the `X-Forwarded-*` headers are believed at
all. Nothing is trusted by default, which costs two things that are easy to miss
because neither produces an error: the session cookie is not marked `Secure`,
and every request appears to come from the proxy, so the login and password
throttles count the whole internet as one client and lock everybody out
together. `*` is the usual answer for a container stack, where the proxy's
address is assigned by the network.

Set it **only** when there is a proxy and the application cannot be reached
around it. On a directly exposed installation, trusting every proxy means
trusting whatever `X-Forwarded-For` a client sends — so anyone can present a
fresh address per attempt and walk straight past the login throttle.

`ReverseProxyTest` covers both.

### Large response headers

The application sends a `Link:` header preloading its JavaScript modules —
around thirty entries, several kilobytes. That is larger than the default
response-header buffer of most proxies, and a proxy that cannot buffer a header
answers **502** rather than saying what happened.

```nginx
proxy_buffer_size 32k;
proxy_buffers 8 32k;
proxy_busy_buffers_size 64k;
```

Caddy and Traefik have no equivalent limit and need nothing.

### Serving under a path

Serving the application at `https://example.com/archivum` rather than on its own
name is **partly supported**. The server side works:

```dotenv
APP_URL=https://example.com/archivum
ASSET_URL=https://example.com/archivum
```

`APP_URL` puts the prefix on every URL the server generates, and `ASSET_URL`
puts it on the built asset paths — at runtime, with no rebuild. The proxy must
**strip the prefix** before forwarding, because the application's routes are
registered without it:

```nginx
location /archivum/ {
    proxy_pass http://127.0.0.1:8080/;   # the trailing slash strips the prefix
}
```

What does not yet work is the client. The interface is a single-page
application, and its URLs are compiled into the JavaScript bundle at build time
as root-relative paths — `/login`, not `/archivum/login`. The published image
carries one build, so it cannot know a prefix an operator chose afterwards.
Server-rendered navigation and the first page load are correct; a link followed
inside the application resolves against the wrong root.

Until that is fixed, serve the application on its own hostname or subdomain.
`https://archivum.example.com` needs none of this.

## Demo mode

An opt-in mode for a public demo: every night a scheduled task drops the
database and deletes every uploaded file, then reseeds a small archive that is
already in use — a filing scheme, documents on shelves, tags, and attachments
whose text is already extracted, so search works on the first query.

**It is off unless two variables are set, and the reset refuses to run
otherwise.**

```dotenv
DEMO_MODE=true
DEMO_RESET_CONFIRM=https://demo.example.com   # must equal this APP_URL
DEMO_RESET_AT=04:00                           # optional, app timezone
DEMO_EMAIL=demo@example.com                   # shown on the login screen
DEMO_PASSWORD=demo1234
```

Two variables rather than one because the accident worth defending against is
not a mistyped boolean — it is a working `.env` copied from the demo onto a real
installation, which is what people do when they want a config that is known to
work. `DEMO_MODE=true` survives that copy intact. `DEMO_RESET_CONFIRM` does not:
it has to repeat the installation's own `APP_URL`, so it stops matching the
moment the URL changes and can only be made to match again by deliberately
retyping the new host.

`php artisan demo:reset` typed by hand refuses on the same two checks, because a
guard that lives only in the scheduler protects only the scheduler. On an
ordinary installation nothing is scheduled at all.

Demo mode also withholds what a visitor could use to leave the demo useless to
whoever arrives next. The credentials are printed on the login screen and the
demo account is both a workspace admin and a platform admin, so everyone arrives
holding the keys. None of this is about damage — the nightly reset repairs
everything. It is about the hours in between:

- **Being locked out.** Changing the password, changing the account's email and
  deleting the account are all refused. Each one makes the credentials the login
  screen advertises wrong until the next reset.
- **Being left nothing to look at.** Deleting the workspace is refused, and so
  is creating new ones — nothing bounds how many a visitor could make, and each
  outlives them in the next visitor's switcher.
- **Losing the ceiling.** The workspace limits are what stop an upload spree
  filling the volume before the reset, and a platform admin can edit them.
  Editing them is refused.

The refusals live on the routes, and the interface leaves the matching buttons
out — a button that always errors reads as a broken demo rather than a demo.
Everything else is the product and is left alone, API tokens included: they
grant nothing the printed credentials do not, and they do not survive the reset.

Mail is forced into the `log` mailer, so invitations and export links never reach
a real inbox. That disables password reset in passing, which is intended.

Run it from the scheduler container: it mounts the same `archivum-attachments`
volume as the app, so it can delete the files as well as the records.

## Timeouts

Three numbers have to stay in order, or a long OCR run is handed to a second
worker and the work happens twice:

```text
queue retry_after  >  worker --timeout  >=  job timeout  >=  max_pages × ocr.timeout
      3000                 2700                2700               2400
```

All of them derive from `OCR_MAX_PAGES` and `OCR_TIMEOUT`, so raising the page
cap moves the whole chain. `QueueTimeoutTest` fails if it stops holding.

## The OCR binaries

Text extraction needs `tesseract-ocr` (plus a language pack per configured
language), `poppler-utils` and `ghostscript`, and it needs them **wherever the
worker runs**. The published image carries them; a custom image must too. See
[ocr.md](ocr.md).
