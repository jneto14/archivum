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
jneto14/archivum:latest            Docker Hub
ghcr.io/jneto14/archivum:latest  GHCR
```

Each release also publishes its own `x.y.z` and `x.y` tags.

Docker Hub is the default because a short name is only ever resolved there —
`docker pull jneto14/archivum` expands to `docker.io/jneto14/archivum` and Docker
looks nowhere else. Switch registries with `ARCHIVUM_IMAGE`, which is worth
doing where Docker Hub's anonymous pull limit is a problem:

```dotenv
ARCHIVUM_IMAGE=ghcr.io/jneto14/archivum
```

`ARCHIVUM_VERSION` chooses the tag, and is `latest` when unset, which is what
an installation should normally run. Setting it to a release from the
[changelog](../CHANGELOG.md) holds the stack on that version until you change
it — an upgrade you ask for, at the cost of every fix waiting for you to ask.

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
environment-agnostic: the same `jneto14/archivum` image runs anywhere, and
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
docker compose --env-file .env -f compose.prod.yaml pull
docker compose --env-file .env -f compose.prod.yaml up -d
```

`pull` fetches whatever `ARCHIVUM_VERSION` names, so on a default installation
it fetches the current release. On one that pins a version, change the pin
first or `pull` re-fetches the tag it already has.

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

### Large requests

The other direction, and the one that bites as soon as somebody scans a page
with their phone. nginx defaults `client_max_body_size` to **1M** and answers
**413** on anything larger, before the application sees the request at all — so
the upload fails with nothing in the logs to say why. A photograph from a phone
clears 1M easily.

```nginx
client_max_body_size 64m;
```

64M is what the image's PHP is configured for; the application itself refuses a
single file over 50MB. Setting the proxy higher than PHP only moves where the
rejection happens.

### Serving under a path

An installation can be served at `https://example.com/archivum` rather than on
a name of its own. One setting says so:

```dotenv
APP_URL=https://example.com/archivum
```

The prefix is read back out of `APP_URL`, so there is one statement of where
the installation lives. No rebuild and no `ASSET_URL` — the published image
carries one build and learns the prefix at runtime.

The proxy must **strip the prefix** before forwarding, which is what a
`proxy_pass` with a trailing slash does. Routes are registered without it, so
the application never sees the prefix on the way in; it only puts it back on
everything it hands out.

```nginx
location /archivum/ {
    proxy_pass http://127.0.0.1:8080/;   # the trailing slash strips the prefix
}
```

#### What had to happen for this to work

Worth knowing, because most of it is not the server.

**Generated URLs.** Redirects, server-rendered links and asset paths are built
from `APP_URL` by `ForceApplicationUrl`. Inertia is told the same prefix for the
URL it reports as the current page, or the first navigation would rewrite the
address bar to the wrong root and a reload would land on nothing.

**Where that forcing lives is load-bearing.** `URL::forceRootUrl()` makes the
generator produce absolute URLs for *everything*, including
`wayfinder:generate`, which runs on the command line during the asset build and
writes every route URL into the JavaScript bundle. Done from a service provider,
the build bakes its own `APP_URL` into the bundle — and the image is built from
`.env.example`, so every installation would ship a front end posting to
`http://localhost`. It is middleware for that reason, and
`ReverseProxyTest` fails if it moves back.

**Chunk loading.** `laravel-vite-plugin` sets Vite's base from `ASSET_URL` at
build time, which bakes `/build/assets/...` into every chunk-to-chunk import. A
prefixed installation would ask the domain root for them and never boot, and
setting `ASSET_URL` afterwards cannot reach inside the JavaScript. `vite.config.ts`
sets `base: './'` instead, so each import resolves against the module that
asked for it — already under whatever prefix the entry came from.

**Route URLs in the bundle.** Wayfinder compiles them at build time as
root-relative literals. What it does give is a single seam: every route reads
its path back out of its own definition when called, and every other shape
(`.get()`, `.post()`, `.form()`) goes through that. So the bundle rewrites those
definitions once, before anything renders, against a prefix the server puts in a
`<meta name="app-path-prefix">` tag. Every URL moves at the same moment — a
`Link`'s `href`, a `Form`'s `action`, what the router is handed. See
`resources/js/lib/path-prefix.ts`.

A build **does** bake the path of its own `APP_URL` into those literals, so an
image someone built for their own prefixed installation already carries it. The
rewrite skips a URL that is already under the prefix, which makes both kinds of
build correct.

**Fonts.** The font stylesheet is inlined into the page, where its URLs are
resolved against the document rather than the stylesheet. They go through
`asset()` instead — see `App\Support\FontStyles`.

**The manifest and the service worker.** Both are routes rather than files in
`public/`, so their URLs are built at runtime like every other one. See
[Installing as an app](#installing-as-an-app).

The cost is that the route modules are loaded eagerly rather than split per
page: about 12 KB gzipped on the first load, paid whether or not a prefix is
set. That is the price of one image working under any prefix.

## Installing as an app

Archivum can be installed to a home screen or a desktop and run in its own
window, without the browser's address bar and tabs. Nothing has to be enabled:
a browser offers it once the page is served over HTTPS.

Two routes make that work, and both are routes rather than static files in
`public/` for the same reason the JavaScript route URLs are rewritten in the
browser — one build, any address.

| Route | Serves |
| --- | --- |
| `GET /manifest.webmanifest` | Name, icons, colours, and the app's `start_url` and `scope` |
| `GET /sw.js` | The service worker |

Both are outside `auth`. A manifest behind the login redirects, the browser
reads HTML where it expected JSON, and the install option silently never
appears.

**Under a path prefix this is not cosmetic.** A manifest naming `scope: "/"`
tells the browser the app is the whole domain, and a service worker may only
claim the directory it was served from — so where these two come from decides
what an installed app covers and where it launches. Serving them from the root
of the installation, with every URL inside them absolute, is what keeps two
installations on one host from swallowing each other.

### What the service worker caches

Almost nothing, on purpose.

**Pages are never cached.** An archive is not public, and nothing behind the
login may end up in a cache that outlives the session that fetched it. Every
navigation goes to the network; Inertia's own requests are XHR, so they are not
even seen. The one thing offline changes is what a failed navigation shows: a
small offline page carried inside the worker itself, rather than the browser's
error page — which, in a window with no address bar, is a dead end.

**Built assets are cached and reused.** Everything under the build directory
carries a content hash in its filename, so a cached copy cannot turn out to be
the wrong version of itself.

**A deploy is picked up.** The cache is named after the asset manifest's hash,
which the worker carries as a literal. A new build is therefore a different
worker: the browser installs it, and its `activate` handler deletes the previous
build's cache on the way through. Since no page is ever cached, there is no way
for a stale document to meet a fresh asset version.

The worker is registered in every environment, not only production builds. It
caches nothing the Vite dev server serves — those assets are on another origin —
so leaving it registered costs nothing, and a browser that installed it once
keeps being handed the current script instead of holding on to whichever build
it happened to get.

Icons live in `public/` (`icon-192.png`, `icon-512.png` and a maskable
`icon-maskable-512.png`, whose artwork sits inside the safe circle Android
crops to). iOS reads none of the manifest's display settings and is still
configured through the `apple-*` meta tags and `apple-touch-icon.png`.

### Screenshots

`public/screenshot-*.webp` is what Chrome shows in its install dialog: the
document list, the physical storage tree and a document, each captured twice —
once on a desktop (`form_factor: wide`) and once on a phone. Without one for the
form factor doing the installing, that dialog falls back to a one-line prompt,
and Chrome says so in the console.

Their dimensions and media type are read from the files, so a replacement can be
captured at whatever size and in whatever format is convenient. Chrome's own
limits still apply, and a file that breaks one is dropped as if it were not
there:

- between 320px and 3840px on each side;
- the longer side no more than 2.3x the shorter;
- screenshots sharing a form factor share an aspect ratio — a mismatch drops
  every screenshot of that form factor, not just the odd one.

`PwaTest` holds all three, so a replacement that would have been silently
ignored fails the suite instead.

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

### A public demo, start to finish

The whole thing, for a demo served under a path behind nginx. This is the
configuration the project's own demo runs.

```dotenv
APP_NAME=Archivum
APP_ENV=production
APP_DEBUG=false
APP_URL=https://demo.example.com/archivum
APP_KEY=base64:generate-your-own

# Bind to loopback, so the proxy is the only way in. That is what makes
# TRUSTED_PROXIES=* safe: nothing can reach the application to forge a header.
APP_PORT=127.0.0.1:9001
TRUSTED_PROXIES=*

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

# Without these it is an ordinary installation that happens to be public: no
# banner, no credentials on the login screen, and nothing is ever reset.
DEMO_MODE=true
DEMO_RESET_CONFIRM=https://demo.example.com/archivum
DEMO_RESET_AT=04:00
DEMO_EMAIL=demo@example.com
DEMO_PASSWORD=demo1234
```

Two things that are easy to get wrong here.

**`DEMO_RESET_CONFIRM` must repeat `APP_URL`**, or `demo:reset` refuses and the
demo quietly never resets. A trailing slash on either is fine; they are compared
without one.

**`ASSET_URL` is not needed.** It was, before the image learned to work under a
path at runtime. Setting it does no harm, but `APP_URL` is the only statement of
where the installation lives, and one statement is better than two that can
drift.

#### Seed it with `demo:reset`, not `db:seed`

```bash
docker compose --env-file .env -f compose.prod.yaml up -d
docker compose --env-file .env -f compose.prod.yaml exec app php artisan demo:reset
```

`db:seed` creates an ordinary administrator and an empty workspace. The demo's
dataset — the filing scheme, the documents on shelves, the attachments with
their text already extracted — comes from `demo:reset`, which is also what the
scheduler runs every night. Seeding the ordinary way leaves a demo with nothing
in it until 04:00, and then destroys the account you just made.

#### The proxy

```nginx
location /archivum/ {
    # The trailing slash is what strips the prefix. Without it the application
    # sees /archivum/login, which matches no route, and every page is a 404.
    proxy_pass http://127.0.0.1:9001/;

    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # The Link header preloads around thirty modules and does not fit the
    # default buffer. Too small and nginx answers 502 without saying why.
    proxy_buffer_size       32k;
    proxy_buffers         8 32k;
    proxy_busy_buffers_size 64k;

    # Scans and phone photographs go past nginx's 1M default, which rejects
    # them with a 413 the application never sees.
    client_max_body_size 64m;
}
```

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
