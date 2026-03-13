---
description: Enforces test creation for every new Laravel component.
globs: "**/*.php"
---
# Testing Requirements

Whenever you create or modify a Controller, Model, Action, or Service in this Laravel application, you MUST automatically adhere to the following testing rules:

1. **Always Write a Test:** Do not consider the task complete, and do not end the response, until a corresponding test file is created or updated.
2. **Framework:** Use Laravel's default testing framework (Pest PHP is preferred for Laravel 11, otherwise PHPUnit).
3. **Coverage:** You must test:
   - The "Happy Path" (successful execution/HTTP 200).
   - Validation failures (HTTP 422).
   - Edge cases or unauthorized access (HTTP 403/401).
4. **Data Generation:** Always use Laravel Model Factories (e.g., `Client::factory()->create()`) to seed database states for tests. Do not use raw DB inserts.
5. **No Mocks Unless Necessary:** Prefer hitting the actual test database (using the `RefreshDatabase` trait) over mocking internal repositories.
