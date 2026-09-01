# API

**There is essentially no API yet.** This document exists to say so precisely,
because the alternative is someone assuming there is one.

## What exists

One route:

```text
GET /api/user     returns the authenticated user
```

authenticated with a Sanctum token, and rate-limited.

Users can create and revoke personal access tokens from
**Settings → API tokens**. Tokens are shown once, on creation, and stored
hashed.

## What does not

There are no endpoints for documents, attachments, workspaces, organization
nodes or search. Everything the application does is driven through Inertia
pages, which are HTML responses to a session-authenticated browser — not a
consumable interface.

`app/Http/Resources/` holds resources such as `DocumentResource`, but they
serialize props for Inertia pages, not for an API. Treating them as an API
contract would freeze a shape that exists to serve a React component.

## If an API is added

Two things are already decided:

- **Sanctum, not Passport.** OAuth2 is not needed for first-party tokens, and
  Passport is a lot of moving parts to maintain for a self-hosted application.
- **Versioned, and separate from the Inertia layer.** The session flow and the
  token flow stay independent, and an API response shape is a commitment in a
  way that a page prop is not.

Until then, automation against an Archivum installation means driving the
application's own routes with a session, which is not supported and will break.
