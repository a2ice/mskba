# Telegram login fallback rollout

The official Telegram Login Widget is exposed as the primary web Telegram login fallback while the production VDS has selective connectivity failures to Telegram networks.

The existing bot-based challenge flow remains available as a secondary option and is not removed. Once provider routing is confirmed healthy, the product can decide whether to keep both entry points or return to bot-first UX.
