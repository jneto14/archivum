# Archivum

**Open-source document management for physical archives.**

Archivum is a searchable database for documents you keep on paper — in covers, folders, binders, cabinets or drawers — and for their scans.

> Logical organization and physical organization are separate concepts.

A document is categorised, tagged and searched independently of where its paper copy sits. You can reorganise the archive without breaking how documents are found, and find a document without knowing which drawer it is in.

Uploaded scans have their text extracted in the background — the embedded text layer where a PDF has one, OCR where it does not — so a document is findable by what is written on the page, not only by its title.

- **Source:** https://github.com/jneto14/archivum
- **Documentation:** https://github.com/jneto14/archivum/tree/{{REF}}/docs
- **Deployment guide:** https://github.com/jneto14/archivum/blob/{{REF}}/docs/deployment.md

---

## Tags

| Tag | |
| --- | --- |
| `latest` | The newest release. What the stack runs unless you say otherwise |
| `{{VERSION}}` | An exact release, for an upgrade you would rather ask for |
| `{{MINOR}}` | The latest patch of that minor |

Built for **linux/amd64** and **linux/arm64**.

The same image is also on GHCR as `ghcr.io/jneto14/archivum`, which is worth using where Docker Hub's anonymous pull limit is a problem.

---

## Quick start

Archivum needs MySQL and Redis alongside it, so it is run as a compose stack rather than a single container.

```bash
curl -O https://raw.githubusercontent.com/jneto14/archivum/{{REF}}/compose.prod.yaml

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

Change `APP_URL`, both passwords and `ADMIN_EMAIL`. The rest is correct as it stands.

Migrations run from the entrypoint on start, so there is no separate step for them. **The seed is what creates your first administrator and the default workspace** — without it there is no account to log in with. Leave `ADMIN_PASSWORD` out and the seeder generates one and prints it once.

`--env-file` is not optional. Compose reads variables from two places: `env_file:` passes them into the containers, while `${...}`in the compose file is interpolated from the project `.env` or from `--env-file`. `APP_PORT`, the `DB_*` pair that creates the database, and `ARCHIVUM_*` are all the second kind, so without the flag they silently fall back to their defaults.

---

## What the stack runs

```text
app        FrankenPHP, serving the application     :80
worker     queue:work                              same image
scheduler  schedule:work                           same image, one replica
mysql
redis
```

The three application services **differ only by their command**. That is deliberate: text extraction runs on the queue, so a worker built without Tesseract, Poppler and Ghostscript would fail every extraction while the web container passed its health check. All three binaries are in this image.

The scheduler runs one replica — `schedule:work` keeps its own clock, so a second instance would prune exports and clean the activity log twice.

---

## Configuration

Everything is environment variables, and none of them are baked into the image. The same `jneto14/archivum` image runs anywhere.

| Variable | |
| --- | --- |
| `APP_KEY` | 32 random bytes in base64. Changing it later makes existing sessions and encrypted values unreadable |
| `APP_PORT` | Host port for the web container. Defaults to 80 |
| `ARCHIVUM_IMAGE` | Switch registries, e.g. `ghcr.io/jneto14/archivum` |
| `ARCHIVUM_VERSION` | The tag to run. `latest` when unset, which is what an installation normally wants; a release holds it there until you change it |
| `ARCHIVUM_ENV_FILE` | Point the stack at a different env file |
| `ARCHIVUM_MIGRATE` | `false` to skip migrations on start and run them yourself |
| `OCR_ENABLED` | `false` to switch text extraction off |
| `OCR_LANGUAGES` | Tesseract language codes, e.g. `por+eng` |

### Applying a change

```bash
docker compose --env-file .env -f compose.prod.yaml up -d
```

**Not `restart`.** That restarts the same containers with the environment they were created with, so an edited `.env` has no effect at all. The config cache is built by the entrypoint when the container starts, which is what makes one image environment-agnostic.

---

## Data

| | |
| --- | --- |
| `archivum-attachments` | Mounted at `/app/storage/app`. **This is your documents' files** |
| `archivum-mysql` | The database |
| `archivum-redis` | Cache and sessions |

Only `/app/storage/app` is shared between the application containers. The rest of `storage/` is each container's own cache and logs.

The queue deliberately stays on the database rather than Redis: it carries real work, and a Redis without persistence loses queued jobs on restart, which would strand an uploaded attachment as "queued" for good. Cache and sessions are safe to lose; jobs are not.

---

## Upgrading

```bash
docker compose --env-file .env -f compose.prod.yaml pull
docker compose --env-file .env -f compose.prod.yaml up -d
```

On an installation that pins `ARCHIVUM_VERSION`, change the pin first — otherwise `pull` re-fetches the tag it already has.

Replacing the containers is what applies the new code, and replacing the worker **is** `queue:restart` — a running worker holds the old classes in memory. Migrations run on start.

Read the [changelog](https://github.com/jneto14/archivum/blob/{{REF}}/CHANGELOG.md) first. While the major version is 0, the schema, the environment variables and the image's behaviour may change in a minor release.

---

## License

[Elastic License 2.0](https://github.com/jneto14/archivum/blob/{{REF}}/LICENSE) (ELv2).

Free to use, modify and self-host, including in production. The one restriction is that you may not offer Archivum to third parties as a hosted or managed service.