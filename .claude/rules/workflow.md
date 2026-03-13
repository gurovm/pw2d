---
description: Enforces planning before coding and self-review after execution.
globs: "**/*"
---
# AI Agent Workflow Rules

Whenever you are tasked with building a new feature, creating a component, or refactoring code, you MUST follow this two-step workflow:

## 1. Plan First (No blind coding)
Before generating any actual code for a new task, you must first output a concise architectural plan. 
The plan should include:
- Which files you intend to create or modify.
- Any database schema or migration changes.
- Which Laravel patterns (Services, Actions, Jobs) you will use.
**STOP** after presenting the plan and wait for the user to explicitly approve or adjust it before you write the code.

## 2. Self Code-Review
After writing the code and before considering the task complete, you must perform a self-review of the code you just generated. 
Silently ask yourself:
- Did I introduce any N+1 query problems?
- Is there any logic in the Controller that belongs in a Service/Action?
- Did I miss validation or security checks?
If you find any issues during this self-review, refactor and fix your own code immediately before presenting the final result.
