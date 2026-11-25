# 1047 — Mobile REST API: account confirmation

GitHub #1047. Agreed dev-first for this task. Sync PR #1048 merged at ab0cc437c52be5e62aa7925e5a893e3ecda5871b; owner confirmed CI #3314 and checkpoints #84. Eight endpoints as described in [mobile API](../../api/mobile-account-confirmation.md). Reuse domain handlers and shared profile update, preserve provider trust and permissions. New token abilities require re-login. No database migration. This change is awaiting CI, dev deploy and mobile developer acceptance. Release main only after review of fresh diff and approval.
