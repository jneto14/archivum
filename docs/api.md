# API

A token-authenticated HTTP API under `/api/v1`, covering what the interface
covers. Anything you can do in the browser you can do with a token, bar a short
list of things that are browser-only by nature — see [Not on the
API](#not-on-the-api).

## Authentication

Bearer tokens, issued from **Settings → API tokens** and sent as a header:

```text
Authorization: Bearer <token>
Accept: application/json
```

A token is the user's. It reaches every workspace that user belongs to and
nothing else: the same policies that decide what they can do in the browser
decide it here, against the same workspace role. **A token never widens what
its user can reach.** Remove somebody from a workspace and their token loses it
on the next request.

Sessions do not authenticate against `/api`. The guard list in
`config/sanctum.php` is deliberately empty, because `/api` carries no CSRF
middleware — it was never meant to be reached with a cookie, and a session that
authenticated here would be a cross-site request away from writing.

### Token lifetime

Tokens are issued with an expiry: 30, 60, 90 or 365 days, or never. Ninety days
is the default. Never is available and has to be chosen — a token pasted into a
script's configuration is a key to the archive, and one with no end date is the
setting nobody picks deliberately and everybody used to end up with.

An expired token is refused with 401. Expired rows are pruned daily, but the
pruning is housekeeping: the token stops working the moment it expires, not
when the row goes.

Minting tokens is deliberately **not** on the API. A token that can mint tokens
survives its own revocation and outlives its own expiry.

## Shape

Every response is wrapped:

```json
{ "data": { "id": "01a0…", "title": "Rental agreement" } }
```

A listing adds `links` and `meta` from Laravel's paginator, and some carry
extra `meta` of their own — the trash reports `retention_days`, the review
queue reports its `counts` per filter.

Errors are JSON, never HTML:

| Status | When |
| --- | --- |
| 401 | No token, or an expired one |
| 403 | A token whose user may not do this |
| 404 | Not there, or not this user's to see |
| 413 | Upload larger than the server accepts |
| 422 | Validation failed — `{"message": …, "errors": {"field": […]}}` |
| 429 | Rate limited |

404 rather than 403 is used wherever a permission error would itself be an
answer: an id belonging to another workspace is reported as missing, not as
forbidden.

## Pagination

`?per_page=` on any paginated listing, from 1 to 100, defaulting to 15. Values
outside that are clamped rather than rejected, and `meta.per_page` reports what
was actually used. `?page=` selects the page.

## Rate limiting

Sixty requests a minute per user by default — per user, not per token, so
minting a second token does not buy a second budget. Set `API_RATE_LIMIT` to
change it, or to `0` to lift it entirely, which is reasonable for a self-hosted
installation running a bulk import against its own machine.

## Endpoints

`{workspace}`, `{document}` and the rest are UUIDs. Everything is under
`/api/v1`.

### Identity and workspaces

| | |
| --- | --- |
| `GET /user` | Who this token belongs to |
| `GET /workspaces` | The workspaces this token reaches — **start here**, since every route below names one |
| `POST /workspaces` | Create one |
| `GET /workspaces/{workspace}` | Read one |
| `PATCH /workspaces/{workspace}` | Rename it |
| `DELETE /workspaces/{workspace}` | Delete it, and everything in it |
| `GET /workspaces/{workspace}/usage` | Storage, users, documents and attachments used, against their limits |
| `PATCH /workspaces/{workspace}/limits` | Set those limits (platform admins) |
| `GET /workspaces/{workspace}/users` | Members and their roles |
| `POST /workspaces/{workspace}/users` | Add somebody, inviting them by email if they have no account |
| `PATCH /workspaces/{workspace}/users/{user}` | Change a role |
| `DELETE /workspaces/{workspace}/users/{user}` | Remove a member |
| `GET /workspaces/{workspace}/activity` | The audit trail; filter with `event` and `log_name` |

A missing limit is reported as `null`, which is not the same as `0`.

### Documents

| | |
| --- | --- |
| `GET /workspaces/{workspace}/documents` | List and search |
| `POST /workspaces/{workspace}/documents` | Register one |
| `GET /documents/{document}` | Read one |
| `PATCH /documents/{document}` | Update it |
| `DELETE /documents/{document}` | Move it to the trash |
| `POST /documents/{document}/move` | File it at a node, or let the scheme decide |

The listing **is** the search. `q` and `mode` are filters on it rather than a
separate endpoint:

```text
?q=rental&mode=all|any|phrase|title
?document_type_id=…&tag_ids[]=…&node_id=…&from=2026-01-01&to=2026-12-31
?sort=title|document_date|type|created_at&direction=asc|desc
```

`POST /documents/{document}/move` takes either `node_id`, or `scheme_id` with
optional `criteria`, in which case the scheme's rules decide where the document
belongs. The second form is the one worth having: a client filing a batch does
not know the archive's shelves.

### Vocabulary

| | |
| --- | --- |
| `GET`/`POST /workspaces/{workspace}/document-types` | |
| `PATCH`/`DELETE /document-types/{type}` | |
| `GET`/`POST /workspaces/{workspace}/tags` | |
| `PATCH`/`DELETE /tags/{tag}` | |

Neither listing is paginated. A type still carrying documents refuses to be
deleted; a tag applied to documents does not, and takes its labels with it.

### Attachments

| | |
| --- | --- |
| `GET /documents/{document}/attachments` | |
| `POST /documents/{document}/attachments` | Multipart, `files[]`, always a list |
| `GET /attachments/{attachment}` | Metadata, including the extracted text |
| `GET /attachments/{attachment}/file` | Download |
| `GET /attachments/{attachment}/preview` | Inline, where the type is safe to render |
| `POST /attachments/{attachment}/file` | Replace the file, keeping the old one |
| `DELETE /attachments/{attachment}` | Move to the trash |
| `DELETE /attachments/{attachment}/duplicate` | Dismiss the duplicate warning |
| `POST /attachments/{attachment}/extraction` | Read the file again |
| `POST`/`DELETE /attachments/{attachment}/reading` | Keep, or throw away, what OCR made of it |
| `GET /attachments/{attachment}/versions` | The files it used to hold |
| `GET /attachment-versions/{version}/file` | Download one of them |
| `POST /attachment-versions/{version}/restore` | Make one current again |

Note that `GET /attachments/{attachment}` is the **metadata**; the bytes are at
`/file`. The web route of the same shape is the download, because a browser
following a link wants the file.

`ocr_text` travels with a single attachment and not with a listing — a document
with fifty scans would otherwise turn its listing into a transfer.

A batch that fails validation, or would cross a workspace limit, fails whole.

### Trash

| | |
| --- | --- |
| `GET /workspaces/{workspace}/trash/documents` | |
| `GET /workspaces/{workspace}/trash/attachments` | |
| `POST`/`DELETE /workspaces/{workspace}/trash/documents/{document}` | Restore, or destroy |
| `POST`/`DELETE /workspaces/{workspace}/trash/attachments/{attachment}` | Restore, or destroy |
| `DELETE /workspaces/{workspace}/trash` | Empty it |

Destroying is narrower than trashing: trashing is reversible and this is not,
so it is the workspace's administrators who carry it.

### The physical archive

| | |
| --- | --- |
| `GET`/`POST /workspaces/{workspace}/organization/schemes` | |
| `GET`/`PATCH /organization/schemes/{scheme}` | |
| `POST /organization/schemes/{scheme}/levels` | |
| `PATCH`/`DELETE /organization/schemes/{scheme}/levels/{level}` | |
| `GET`/`POST /organization/schemes/{scheme}/nodes` | Browse a tier with `?parent_id=`, or create |
| `DELETE /organization/schemes/{scheme}/nodes/{node}` | |
| `GET /organization/nodes/{node}/documents` | What is filed there now |
| `POST /organization/nodes/{node}/migrate` | Move everything to another node — **202**, queued |
| `POST /organization/schemes/{scheme}/rules` | |
| `PATCH`/`DELETE /organization/schemes/{scheme}/rules/{rule}` | |
| `GET /organization/schemes/{scheme}/labels` | Labelled nodes, and the URL their code points at |

Reading a scheme gives the levels and rules a client needs to file
automatically. Labels report a URL rather than a rendered image: a client
making labels has its own idea of size, margins and error correction.

### Intake and capture

| | |
| --- | --- |
| `GET /workspaces/{workspace}/review` | The queue; `?filter=all\|suggestions\|readings\|duplicates` |
| `POST /workspaces/{workspace}/review` | Answer for many at once |
| `GET /documents/{document}/metadata-suggestions` | What the archive would suggest, applying nothing |
| `POST /documents/{document}/metadata-suggestions` | Accept some of it, by `kinds[]` |
| `POST /documents/{document}/capture-sessions` | Pair a phone; `attachment` aims it at replacing one |
| `GET /documents/{document}/capture-sessions/{session}` | Has the phone finished? |
| `POST /documents/{document}/capture-sessions/{session}/cancel` | |

A capture session hands back `pairing_url` — the signed link the phone loads
and uploads through. The interface renders it as a QR code; you are given the
URL the code was only ever carrying.

Answering in bulk carries the `filter` it was made against, so "dismiss
everything" means everything in the queue you were looking at rather than
everything that qualifies by the time the request lands.

### Background work

| | |
| --- | --- |
| `GET /workspaces/{workspace}/tasks` | Filter with `status` and `type` |
| `POST /workspaces/{workspace}/tasks` | Start an export — **202** |
| `POST /workspaces/{workspace}/tasks/reextract` | Read every attachment again — **202** |
| `GET /workspaces/{workspace}/tasks/{task}` | Poll it |
| `POST /workspaces/{workspace}/tasks/{task}/retry` | |
| `GET /workspaces/{workspace}/tasks/{task}/download` | Fetch a finished export |

Anything slow answers 202 with a task, and you read the task back until it
finishes. A finished export reports `result_available`; where the file sits on
disk is this application's business.

## Not on the API

Each of these is browser-only by nature, not an oversight:

- **Minting API tokens.** See above.
- **Switching workspace.** A session write. Here you name the workspace in the
  URL, so there is no current one to switch.
- **The phone's half of capture.** Already an unauthenticated signed URL by
  design — a phone scanning a code has no session and never will.
- **Signing in**, password reset, email verification, two-factor, passkeys.
- **Profile, account deletion, appearance**, and accepting an invitation.
- **The PWA manifest and service worker.**

## Compatibility

`/api/v1` is versioned so its shapes can be relied on. `/api/user`, which
predates it, still answers and is unversioned; `/api/v1/user` is the one to
build against.

The API does **not** reuse `app/Http/Resources/` — those serialize props for
React components, and a page prop may change with the page that reads it. The
API's own resources live in `app/Http/Resources/Api/V1/`.
