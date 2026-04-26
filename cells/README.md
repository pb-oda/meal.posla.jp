# POSLA cell configuration

This directory stores per-cell runtime configuration.

Actual cell files are ignored by git:

- `cells/<cell-id>/app.env`
- `cells/<cell-id>/db.env`
- `cells/<cell-id>/cell.env`
- `cells/<cell-id>/uploads/`

New cell DBs are initialized by `docker/db/cell-init/`, which loads schema and
schema migrations only. Local demo seed data from `docker/db/init/03-seed.sql`
and data migrations that insert demo tenants/users are not loaded into cell DBs.

Cell app images are built with `docker/php/Dockerfile.cell`. App code is baked
into the image artifact; only `cells/<cell-id>/uploads/` is mounted at runtime.
This prevents a host-side code edit from becoming visible to every running cell.

Create a cell from the examples:

```bash
scripts/cell/cell.sh init cell-a tenant-a cell-a.<production-domain> 18081 13306
scripts/cell/cell.sh registry
```

Then edit the generated env files and run:

```bash
scripts/cell/cell.sh cell-a config
scripts/cell/cell.sh cell-a build
scripts/cell/cell.sh cell-a deploy
scripts/cell/cell.sh cell-a ps
```

Set the same `POSLA_OPS_READ_SECRET` in the control app env and every cell app
env. The control app uses it as a read-only header when it fetches
`/api/monitor/cell-snapshot.php` from each cell for tenant health and onboarding
insights. Keep it separate from each cell's `POSLA_CRON_SECRET`.

`deploy` creates a pre-deploy backup under `cells/<cell-id>/backups/` and records
deployment history when `posla_cell_deployments` exists in the cell DB.

Run smoke checks after deploy:

```bash
scripts/cell/cell.sh cell-a smoke
POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh cell-a smoke
```

Use strict mode after `schema_migrations`, `posla_cell_registry`, and
`posla_cell_deployments` are installed in the cell DB.

For a cell that already has a built or pulled image, update only that cell:

```bash
scripts/cell/cell.sh cell-a up
scripts/cell/cell.sh cell-a ping
```

Create a backup without deploying:

```bash
scripts/cell/cell.sh cell-a backup
scripts/cell/cell.sh cell-a backups
```

Apply a migration to one cell only:

```bash
scripts/cell/cell.sh cell-a migrate sql/migration-p1-40-cell-migration-ledger.sql
scripts/cell/cell.sh cell-a migrate sql/migration-p1-41-cell-registry.sql
scripts/cell/cell.sh cell-a register-db
```

Create the first tenant / store / owner / manager / staff / device accounts in
one cell:

```bash
POSLA_OWNER_PASSWORD='replace-with-initial-password' \
  scripts/cell/cell.sh cell-a onboard-tenant tenant-a 'Tenant A' 'Tenant A Main Store' owner 'Owner Name' owner@example.com

scripts/cell/cell.sh cell-a smoke
```

`onboard-tenant` creates active `tenants`, `stores`, `users`, and `user_stores`
records in the target cell DB only. The initial role set mirrors POSLA admin
tenant creation: `owner`, `manager`, `staff`, and `device`. It also writes tenant
metadata into `posla_cell_registry` when the registry table exists.

If a pre-existing cell was created with only an owner account, repair it before
release readiness checks:

```bash
POSLA_OPS_USER_PASSWORD='replace-with-temporary-password' \
  scripts/cell/cell.sh cell-a ensure-ops-users tenant-a
```

Rollback from a backup:

```bash
scripts/cell/cell.sh cell-a rollback-plan latest
POSLA_CELL_RESTORE_CONFIRM=cell-a scripts/cell/cell.sh cell-a restore-env latest
POSLA_CELL_RESTORE_CONFIRM=cell-a scripts/cell/cell.sh cell-a restore-db latest
scripts/cell/cell.sh cell-a deploy
```

All-in-one rollback:

```bash
POSLA_CELL_RESTORE_CONFIRM=cell-a scripts/cell/cell.sh cell-a rollback latest
```

Do not place production secrets in this repository.
