# resume-ai — AI Job Automation Platform

Upload your resume once. The platform discovers jobs across multiple sources,
scores each one against your profile with an LLM, tailors a LaTeX resume (and
cover letter when the posting asks for one), and tracks every application in
one dashboard.

## Repository layout

This repo holds two independent projects that talk to each other only over
HTTP/JSON. Each has its own dependencies, dev server, build, and test suite,
and each can be deployed on its own.

| Folder | What it is | Setup instructions |
| --- | --- | --- |
| [`backend/`](backend) | Laravel JSON API (`/api/*`), Sanctum bearer-token auth, queued pipeline jobs | [`backend/README.md`](backend/README.md) |
| [`frontend/`](frontend) | Standalone React + TypeScript app (Vite), its own `package.json` and dev server | [`frontend/README.md`](frontend/README.md) |

`backend/` carries no frontend-framework dependencies, and `frontend/` will
carry no PHP/Composer dependencies. There is no shared `node_modules` or
`vendor` directory between them.

## Getting started

Start with the backend — it runs standalone:

```bash
cd backend
# follow backend/README.md
```

Then the frontend, from its own folder with its own dev server (port 5173):

```bash
cd frontend
npm install && npm run dev
```

The backend's CORS allow-list is driven by `FRONTEND_URL` in `backend/.env`.
The frontend points at the backend via `VITE_API_BASE_URL`, which `frontend/.env`
defaults to `http://localhost:8000/api` — no setup needed for local dev. To
override it (different port, staging, production), copy
`frontend/.env.example` to `frontend/.env.local`; see
[`frontend/README.md`](frontend/README.md#configuration).

A Docker Compose setup for the backend plus MySQL lives at the repo root:

```bash
docker compose up --build
```

## Status

Work is tracked in the
[job-automation-platform spec](.kiro/specs/job-automation-platform/tasks.md).
The API, the move into `backend/`, and the `frontend/` scaffold are done;
routing, the API client, and the first screens are next. Authenticated Blade
views still exist in the backend and are removed as each frontend equivalent
lands.

## Further documentation

- [`docs/`](docs) — architecture, services (Ollama, database, external APIs),
  crawler, and deployment reference
- [`PROJECT_DOCUMENTATION.md`](PROJECT_DOCUMENTATION.md) — legacy overview of
  the original single-repo Laravel/Blade app
- [`.kiro/specs/`](.kiro/specs) — requirements, design, and task plans

## Author

Built by [Dvip Patel](https://github.com/dvip-ai).
