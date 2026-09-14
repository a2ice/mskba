# Task 177 implementation checklist

- [x] Chosen cardinality documented: SportsSection M:N Team.
- [x] Pivot migration added with forward and reverse indexes.
- [x] Eloquent relations added on both models.
- [x] Link requires section management and team settings access.
- [x] Temporary event teams are rejected.
- [x] Unlink does not delete either aggregate.
- [x] Account management page added.
- [x] Feature tests cover relation cardinality, permissions, IDOR and permanent-team gate.
- [x] Task 170 documentation updated.
- [x] Full CI green (CI #186).
- [x] Merged to main via PR #189.
