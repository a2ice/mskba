# Manager RBAC and staff specializations

New target hierarchy: superadmin > admin > manager > user. System accounts are separate.

System roles determine seniority; a manager may have zero, one, or multiple specializations. The reusable moderator/editor/advertiser templates are seeded, and custom names/profiles are managed by superadmin. Effective permissions are the union of role defaults, assigned specialties, and individual grants, bounded by the hard ceiling of the underlying system role. Baseline grants cannot be revoked individually. Ordinary users cannot access Filament, even with legacy snapshots.

An admin may delegate only permissions they currently hold to a lower-ranked manager. Marketing outbound and ordinary operator conversations are distinct permissions and still depend on the existing consent/business checks. Staff profiles do not confer domain-scoped owner privileges to teams, venues, tournaments, or events.

Migration: old editor/moderator accounts become managers plus matching specialization; individual rows are retained. The PostgreSQL users_system_role_check gains manager. On downgrade from manager, specializations are detached to prevent stale privileges. Rolling back a database with manager accounts requires reviewed reverse mapping; automatic destructive reversal is blocked.

This implementation remains a draft until CI and functional verification. Audit old domain role comparisons and moderation capability, review legacy tests expecting user Filament access, test real dev migrations with backup, and ensure existing marketing consent checks remain effective. Do not merge unfinished RBAC.