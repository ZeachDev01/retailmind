# Retire Emergency Access in favor of active workspace switching

The Super Administrator steps into Store administration, inventory execution, or point-of-sale work by switching into the corresponding active workspace rather than activating a separate Emergency Access session; the `super_admin` workspace remains scoped to platform governance and read-only oversight. In-person register supervisor prompts accept active `admin` or `super_admin` credentials directly without a pre-activated emergency session. We chose this direction, superseding the Emergency Access provision of ADR-0001, because workspace switching already provides explicit, audited role entry with proper navigation and workspace guardrails, whereas keeping Emergency Access alongside workspace switching left most operational pages bypassing it while four backend checks acted as hidden traps.

## Consequences

- Emergency Access UI, attention signals, and `EmergencyAccessService` capability elevation are removed from the application.
- Signed-in Store, inventory, and Legacy Reversal mutations require the corresponding active workspace (`admin` or `inventory_manager`), while in-person register supervisor prompts accept active `admin` or `super_admin` credentials directly.
- The development storage cleanup on 2026-10-25 removes the retired `emergency_access_sessions` table and duration setting. This replaces the earlier choice to retain them for historical and migration compatibility; pre-cleanup backups require the pre-cleanup schema to restore.

