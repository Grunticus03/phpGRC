# phpGRC Style Guide

- Markdown: 80–120 col soft wrap; headings start at H1 per file.
- YAML/JSON: 2-space indent; LF line endings.
- Commit messages: conventional commits (feat, fix, docs, chore, refactor, ci, build, perf, test, style).

## IDs and Slugs
- IDs visible in UI or APIs must be human-readable slugs.
- Role IDs: `role_<slug>` using lowercase ASCII, `_` separator.
- Collision rule: append `_<N>` where `N` starts at 1 and increments.
- Use ULIDs/integers only for opaque IDs not shown to users.
