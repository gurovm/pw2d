---
description: Enforces Laravel best practices for Reusability, Security, and Performance.
globs: "**/*.php"
---
# Laravel Coding Standards & Best Practices

Whenever you generate or modify PHP/Laravel code, you MUST adhere to the following principles:

## 1. Reusability (DRY Principle)
- **Do not write "Fat Controllers".** Controllers should only handle HTTP requests and responses.
- **Extract Business Logic:** Move complex logic into reusable `Services` or single-responsibility `Actions`.
- **Extract Shared Logic:** Use `Traits` for methods shared across multiple Models.
- **Search First:** Before creating a new helper function or generic logic, check if a similar Laravel built-in function or an existing project Service already exists.

## 2. Security
- **Validation is Mandatory:** Never trust user input. Always use Form Requests (`php artisan make:request`) or inline `$request->validate()` before processing data.
- **Mass Assignment:** Strictly define `$fillable` arrays in all Models to prevent mass assignment vulnerabilities.
- **Authorization:** Check user permissions using Laravel Policies or Gates before executing actions (e.g., `Gate::authorize('update', $campaign)`).
- **SQL Injection:** Always use Eloquent ORM or Query Builder. Never write raw SQL queries with concatenated variables.

## 3. Performance & Optimization
- **N+1 Problem:** Always use Eager Loading (`with()`) when retrieving relations inside loops to prevent N+1 query performance issues.
- **Caching:** For heavy queries or data that doesn't change often (e.g., system settings, large reports), implement Redis caching using Laravel's `Cache::remember()`.
- **Database Indexing:** When creating Migrations, always add indexes (`->index()`) to foreign keys and columns frequently used in `WHERE` clauses.
- **Chunking:** When processing large datasets, use `chunk()` or `cursor()` instead of loading everything into memory via `get()`.

## 4.Documentation & Comments Standards

1. **Self-Documenting Code First:** Prefer expressive variable and method names over inline comments. 
2. **No Redundant Comments:** Do NOT write comments that just repeat what the code does (e.g., avoid `// find user by id` above `User::find($id)`).
3. **Comment the "Why":** Use inline comments ONLY to explain complex business logic, edge cases.
4. **PHPDoc Blocks:** - Add proper PHPDoc blocks (`/** ... */`) to classes and complex methods.
   - Always include `@param` and `@return` types if they are not explicitly clear from PHP 8.3 strong typing.
