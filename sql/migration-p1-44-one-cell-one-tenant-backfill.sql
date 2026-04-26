-- P1-44: Backfill 1 cell / 1 tenant planned registry rows
-- Purpose:
--   Production policy is 1 customer tenant per dedicated cell. Existing
--   tenants created before the cell architecture decision are recorded as
--   ready_for_cell and get a planned posla_cell_registry row.

SET NAMES utf8mb4;

INSERT INTO posla_tenant_onboarding_requests
  (request_id, request_source, status,
   tenant_id, tenant_slug, tenant_name,
   store_id, store_slug, store_name,
   owner_user_id, owner_username, owner_email, owner_display_name,
   requested_store_count, hq_menu_broadcast, cell_id,
   notes)
SELECT
  UUID(),
  'manual',
  'ready_for_cell',
  t.id,
  t.slug,
  t.name,
  (SELECT s.id FROM stores s WHERE s.tenant_id = t.id ORDER BY s.created_at ASC, s.id ASC LIMIT 1),
  (SELECT s.slug FROM stores s WHERE s.tenant_id = t.id ORDER BY s.created_at ASC, s.id ASC LIMIT 1),
  (SELECT s.name FROM stores s WHERE s.tenant_id = t.id ORDER BY s.created_at ASC, s.id ASC LIMIT 1),
  (SELECT u.id FROM users u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
  (SELECT u.username FROM users u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
  (SELECT u.email FROM users u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
  (SELECT u.display_name FROM users u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.created_at ASC, u.id ASC LIMIT 1),
  GREATEST(1, (SELECT COUNT(*) FROM stores s WHERE s.tenant_id = t.id)),
  COALESCE(t.hq_menu_broadcast, 0),
  t.slug,
  'Backfilled for 1 cell / 1 tenant production policy.'
FROM tenants t
WHERE NOT EXISTS (
  SELECT 1
  FROM posla_tenant_onboarding_requests r
  WHERE r.tenant_id = t.id
);

INSERT INTO posla_cell_registry
  (cell_id, tenant_id, tenant_slug, tenant_name, environment, status, cron_enabled, notes)
SELECT
  r.cell_id,
  r.tenant_id,
  r.tenant_slug,
  r.tenant_name,
  'pseudo-prod',
  CASE
    WHEN r.status = 'cell_provisioning' THEN 'provisioning'
    WHEN r.status = 'active' THEN 'active'
    WHEN r.status = 'failed' THEN 'failed'
    WHEN r.status = 'canceled' THEN 'retired'
    ELSE 'planned'
  END,
  0,
  'Dedicated cell planned from onboarding ledger.'
FROM posla_tenant_onboarding_requests r
WHERE r.cell_id IS NOT NULL
  AND r.cell_id <> ''
ON DUPLICATE KEY UPDATE
  tenant_id = VALUES(tenant_id),
  tenant_slug = VALUES(tenant_slug),
  tenant_name = VALUES(tenant_name),
  environment = VALUES(environment),
  status = CASE WHEN posla_cell_registry.status = 'active' THEN posla_cell_registry.status ELSE VALUES(status) END,
  notes = COALESCE(VALUES(notes), posla_cell_registry.notes),
  updated_at = CURRENT_TIMESTAMP;
