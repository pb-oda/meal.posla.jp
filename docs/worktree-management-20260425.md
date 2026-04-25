# Worktree Management Note - 2026-04-25

This note records how the pseudo-production `meal.posla.jp` worktree is being
made manageable in Git without committing local credentials.

## Current Position

- Environment: pseudo-production Docker environment for `meal.posla.jp`.
- Git remote for this environment: `meal.posla.jp`
  (`https://github.com/pb-oda/meal.posla.jp.git`).
- Cell architecture baseline already committed and pushed:
  `507ff64 meal.posla.jp: add cell deployment architecture`.
- Remaining worktree changes are being split into smaller commits so that app
  code, documentation source, generated documentation, and non-deploy reference
  material can be reviewed independently.

## Do Not Commit

The following local-only materials must remain outside Git:

- `id_ecdsa.pem`
- `api/.htaccess`
- `api/config/database.php`
- `docker/env/*.env`
- `擬似本番アカウント.txt`
- `*no_deploy/docs_sensitive/`
- `*no_deploy/root_misc/sshkey.txt`
- Any credential, secret, login, account, or PEM material under `*no_deploy/`

These are protected by `.gitignore`. If a future change intentionally needs a
template, create a sanitized `.example` file instead.

## Commit Plan

1. Guardrails: `.gitignore` and this management note.
2. Runtime/source snapshot: API, public app assets, SQL migrations, Docker
   support files, privacy/terms, and operational scripts.
3. Documentation source snapshot: `docs/` markdown and manual source updates.
4. Generated documentation snapshot: `public/docs-internal/` and
   `public/docs-tenant/` build output.
5. Non-deploy archive snapshot: safe parts of `*no_deploy/` only, excluding
   sensitive local material.

## Operating Rules

- Keep pseudo-production and demo environment trees independent.
- Do not use `rsync --delete` between the environments.
- Keep generated docs in a separate commit from source docs.
- Review staged files before committing when credentials or account notes could
  be nearby.
- Prefer branch-based review and push to `meal.posla.jp` before merging into a
  long-lived branch.
