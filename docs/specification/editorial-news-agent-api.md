# Editorial news agent API

Separate from the project-updates API. Backoffice superadmin issues a 24-hour scoped token for existing system account dev under Контент → Доступ агента · Публикация новостей. Publication is opt-in; default token can only create/read drafts. Revoke on the same page. Tokens are environment-specific; never put them in chat, git or logs.

Base route: /api/v1/internal/editorial-news. JSON Accept header and Bearer authentication are required.

- POST /: multipart/form-data with alias (unique lower-case slug), title, summary, content (Markdown), optional cover (JPEG/PNG/WebP, max 5 MiB, 6000×6000). Creates draft of type material. Cover uses existing ContentCoverManager; stored as WebP. Requires editorial-news:write. Repeating alias returns 422; GET returns existing owned material to recover after a lost response.
- GET /{alias}: requires editorial-news:write. Returns status, cover presence, title and public URL when published.
- POST /{alias}/publish: requires editorial-news:publish, publishes an owned material draft to site feed only. Repeated publish preserves original publication time. No Telegram publication.
- No access to system updates, FAQ, other authors' materials or archived content; no editing or deletion via API.

First editorial article: publish on production only, alias winter-basketball-indoors-2026, heading Холода наступают. Перебираемся в залы! Link /venues in Markdown. Upload supplied original image as cover (retain creator attribution). Verify public URL and cover; do not publish without supplied image or appropriate permission.

The existing project-update draft API and verification rules remain unchanged. No secret or content image is committed to the repository.
