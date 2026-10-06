# frontend — resume-ai React app

Standalone React + TypeScript app built with Vite. It is fully independent of
`backend/`: its own `package.json`, its own lockfile, its own `node_modules`,
its own dev server and build. The only coupling to the backend is the HTTP API
base URL it is configured with, and the backend's CORS allow-list.

## Requirements

- Node.js 20.19+ or 22.12+ (Vite 6 requirement)
- npm 10+

## Setup

```bash
cd frontend
npm install
```

## Dev server

```bash
npm run dev
```

Serves on <http://localhost:5173> by default. That origin is what
`backend/.env`'s `FRONTEND_URL` (the CORS allow-list) expects — if you change
the port, update the backend too.

Run the backend separately in its own terminal (see
[`backend/README.md`](../backend/README.md)); it listens on
<http://localhost:8000> by default.

## Other commands

| Command | What it does |
| --- | --- |
| `npm run build` | Type-checks with `tsc -b`, then builds to `dist/` |
| `npm run preview` | Serves the production build locally |
| `npm run lint` | Runs ESLint |

## Configuration

The backend URL is never hard-coded in source. `src/api/client.ts` reads it
from `import.meta.env.VITE_API_BASE_URL`.

| Variable | Default (in `.env`) | Purpose |
| --- | --- | --- |
| `VITE_API_BASE_URL` | `http://localhost:8000/api` | Base URL of the Laravel API, including the `/api` prefix. A trailing slash is stripped by the client. |

Two env files are committed:

- `.env` — non-secret defaults that work against a local `php artisan serve`
  backend. Cloning and running `npm run dev` works with no extra setup.
- `.env.example` — template documenting each variable, for copying when you
  need overrides.

Only `VITE_`-prefixed variables are exposed to client code, and Vite inlines
them into the bundle at build time. Treat every value as public — no secrets.

### Overriding per environment

Vite loads env files in this order, later files winning:

```
.env  ->  .env.[mode]  ->  .env.local  ->  .env.[mode].local
```

`[mode]` is `development` for `npm run dev` and `production` for
`npm run build`; use `npm run build -- --mode staging` for anything else.

- **Machine-specific override** (different backend port, a remote API): copy
  the template and edit it. `.env.local` is gitignored, so it never leaks into
  the repo.

  ```bash
  cp .env.example .env.local
  ```

- **Per-environment builds**: commit a `.env.production` / `.env.staging` with
  that environment's API URL, or inject the variable from the host's build
  config (Vercel/Netlify/CI env vars take precedence over env files).

  ```bash
  VITE_API_BASE_URL=https://api.example.com/api npm run build
  ```

Restart the dev server after changing an env file — Vite reads them at startup.
Whichever URL you point at, its origin must be in the backend's CORS
allow-list (`FRONTEND_URL` in `backend/.env`).

## Authentication

The Sanctum token is **not** stored in JavaScript. `POST /api/auth/login` (and
`/api/auth/register`) set it in a backend-issued httpOnly cookie, so:

- `src/api/client.ts` sends every request with `credentials: 'include'` and the
  `X-Requested-With: XMLHttpRequest` header the backend requires before it will
  read that cookie (its CSRF mitigation, since the cookie is sent
  automatically).
- `src/api/tokenStorage.ts` holds no persisted token — only the credentials
  mode plus an optional in-memory bearer override for non-browser flows.
- `src/auth/AuthProvider.tsx` derives `authenticated` / `unauthenticated` /
  `loading` from `GET /api/me`, not from the presence of a token, and exposes
  `login`, `register`, `logout`, `refresh` via `useAuth()`.
- Any 401 clears cached auth state and redirects to `/login`
  (Requirement 1.6). `/login` is a placeholder until task 5.5 builds the real
  screens.

The backend must run with `supports_credentials => true` in `config/cors.php`
and an explicit origin allow-list (no wildcard). For a cross-site production
deployment, set `AUTH_COOKIE_SAME_SITE=none` and `AUTH_COOKIE_SECURE=true` in
`backend/.env`; locally, `lax` over http works because Vite and the API are
both on `localhost`.
