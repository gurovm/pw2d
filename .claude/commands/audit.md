---
description: Run a full audit (review + security + performance) on recent changes using three parallel agents
---

Run a comprehensive audit of the current uncommitted changes (or the files/feature the user specifies). Launch **three agents in parallel** in a single message:

1. **reviewer** agent — code quality, multi-tenancy compliance, Laravel conventions, N+1 queries, dynamic branding
2. **security** agent — tenant isolation, API auth, injection risks, mass assignment, image download safety
3. **performance** agent — query efficiency, Livewire scoring, caching opportunities, index recommendations

## Instructions

1. First, run `git diff --name-only` to identify all changed files. If the user provided a specific feature or file list, use that instead.

2. Launch all three agents **in parallel** (single message, three Agent tool calls), each with `run_in_background: true`. Pass each agent:
   - The list of changed files to audit
   - A reminder to read `docs/project_context.md` first
   - The specific audit scope from their agent definition

3. Each agent writes its report to:
   - `docs/reviews/audit-{date}-review.md`
   - `docs/security/audit-{date}-security.md`
   - `docs/performance/audit-{date}-performance.md`

4. After all three agents complete, summarize the combined findings:
   - Total critical/high/medium issues found across all three reports
   - List the critical items that need immediate attention
   - Update `docs/tasks/todo.md` with any critical or high-priority fix tasks

Use today's date in YYYY-MM-DD format for the report filenames.
