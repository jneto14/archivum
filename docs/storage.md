# Storage

Files live outside the relational database, on a Laravel filesystem disk. The
database keeps only what it needs to find and describe them:

```text
disk
path
filename
mime_type
size
checksum
```

Because it goes through Laravel's filesystem abstraction, any supported disk
works — the local filesystem, S3, MinIO, or anything else with a driver. The
default is local, so a minimal installation needs no object store.

```dotenv
FILESYSTEM_DISK=local
```

## Layout

Attachments are stored under a path derived from their document, and the
original filename is kept as metadata rather than as the path. Two people
uploading `scan.pdf` do not collide, and a filename with awkward characters
never becomes a path.

Downloads are served through the application, not by linking at the disk: the
controller authorizes the request first. There are two routes — one that streams
the file inline for previewing in the browser, and one that sends it as a
download under the name it was uploaded with.

## Limits

Attachments count against two of the workspace's [limits](workspace.md):
`attachments` and `storage_bytes`. Both are checked before anything is written.

A multi-file upload is validated as a batch: if the whole set would cross a
limit, none of it is stored. Filling to the ceiling and failing on the remainder
would leave the user to work out which files landed.

The storage total is a `SUM(size)` over the workspace's attachments, and it runs
on the dashboard and the Usage page. It is served from the
`(document_id, size)` index rather than by reading rows — see
[database.md](database.md).

## In production

Attachments need a volume that outlives the container:

```text
archivum-attachments  →  /app/storage/app
```

**Only that path.** The rest of `storage/` is per-container cache, compiled
views and logs; sharing it between the web, worker and scheduler containers
would have them overwrite each other's caches.

## Exports

A workspace export writes a CSV to the same disk under `exports/`, and the
resulting file is downloadable from the Tasks page through a signed, expiring
link. A scheduled command deletes exports older than
`EXPORT_RETENTION_DAYS`, so they do not accumulate.
