<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Engineering Standards

- Follow Laravel's documented best practices and the installed package versions. Prefer framework conventions over custom abstractions.
- Apply SOLID principles pragmatically: keep responsibilities focused, depend on contracts where useful, and avoid speculative abstractions.
- Use test-driven development for behavior changes: write or update the failing PHPUnit test first, implement the smallest change that makes it pass, then refactor.
- Every new feature must include appropriate automated coverage. Prefer feature tests for application behavior and unit tests for isolated logic.
- All database operations must happen on the backend. Frontend code must never connect directly to the database, execute queries, contain database credentials, or implement database mutations. Use authenticated Laravel routes, controllers, jobs, services, and validated requests as the boundary between the frontend and database.
- Database queries must be efficient and intentional. Prevent N+1 queries, load relationships using the appropriate strategy for the use case, select only required columns, paginate large result sets, use suitable indexes and constraints, and verify query behavior with tests or profiling when performance matters. Lazy loading may be appropriate in some cases, but it is not the default or sole optimization strategy.

## Research And Provider Eligibility

- Before planning or implementing a feature, read the current checkout's `AGENTS.md`, applicable rules/skills, issue description, acceptance criteria, and approved decisions. Identify the current commit and inspect relevant code, tests, configuration, and installed versions. Do not assume knowledge of an original/legacy application or treat referenced classes, routes, and commands as implemented without checking.
- For unfamiliar integrations, authentication flows, or version-sensitive behavior, research the official framework/package/provider documentation before choosing an implementation. Start with official sources already specified in the issue; reuse sufficient verified findings instead of repeating unbounded research. Copy-only changes do not require irrelevant package research.
- For subscription authentication, OAuth, hosted integrations, or credential sharing, also check the provider's current official terms and permitted-use guidance. Distinguish identity login, inference permission, consumer subscriptions, API billing, native-client authentication, and third-party application eligibility. A working unofficial example does not establish support or permission.
- Report the official URLs/sections, date checked, relevant package/API versions, deployment/account assumptions, and resulting implementation decision in the issue, PR, or conversation. Separate documented facts, verified repository behavior, assumptions, and unresolved questions. Do not create extra documentation files unless requested.
- Never invent endpoints, scopes, client identities, token semantics, model availability, callback topology, or provider permissions. If authoritative sources conflict or required evidence is unavailable, state the precise conflict/blocker and request the smallest necessary decision. Do not fill gaps with arbitrary tutorials, scraped sessions, borrowed native-client credentials, or disabled security checks.
- Missing implementation is work to perform under the approved scope, not automatically an external feasibility blocker. Continue independent approved work and fake-backed tests where possible. Account eligibility, unavailable credentials, forbidden provider use, and unapproved live effects must remain explicit blockers to the affected checks.

## Acceptance Evidence And Completion

- Preserve the issue's acceptance criteria and approval gates. A generated plan, partial PR, or implementation shortcut must not weaken, omit, or redefine them. Obtain explicit maintainer approval for scope changes and link any remaining work without claiming it passed.
- Do not silently substitute a different outcome: API-key access is not subscription OAuth; demo fixtures are not live provider data; an account-count echo is not AI inference; DTOs/interfaces are not an end-to-end feature; importing a shadcn primitive is not proof of design consistency. Any proposed alternative needs an explicit decision.
- Before claiming completion, map EVERY applicable acceptance criterion to code and actual verification evidence. Use the statuses `Verified`, `Implemented / Not Verified`, `Blocked`, or `Not Started`; for each non-verified item, state what is missing and the next action or owner. Include this mapping in the PR/final handoff, not only a private plan.
- Evidence must match the claim: record exact test commands/results and relevant file/test references; distinguish fake-backed tests, actual browser checks, live model inference, live provider reads, and external side effects. Fixtures must follow verified provider contracts. Static rendering tests do not prove interactions, and fake success does not prove provider interoperability or eligibility.
- Keep automated tests isolated from live model/provider calls and real credentials. Live sign-in, paid requests, posting, ad creation/spending, database resets, and deployments require their separate explicit account/environment/operation approval. Missing live approval is a verification blocker, never permission to bypass safeguards.
- When a feature requires an operator walkthrough, deliver the requested tracked artifact using the actual implemented controls/commands, exact approved inputs, expected outcomes, failure/stop conditions, and cleanup limits. Walkthrough creation/review and actual execution are separate gates; do not mark manual behavior verified until observed.
- Do not call a task complete, check off unmet criteria, or use `Closes`/`Fixes` references while required criteria remain unverified. A partial implementation may be reported or submitted as partial, but selected blockers must stay visible/open unless the maintainer explicitly accepts a scope revision. Report test failures honestly and separate new failures from pre-existing or unrelated work without reverting others' changes.

## Frontend Component Architecture

- Do not enforce Atomic Design as a rigid folder taxonomy. Use a simpler layered approach:
  - `resources/js/components/ui/` for reusable shadcn primitives.
  - `resources/js/components/` for reusable shared components composed from primitives.
  - Feature or page folders for components that belong to one bounded feature.
  - `resources/js/layouts/` for page and authentication layouts.
- Search for an existing component before creating a new one. Extend or compose an existing component when the behavior is substantially the same.
- Keep components focused and reusable through explicit props. Do not duplicate markup, validation, loading states, or interaction patterns across pages.
- Promote a feature component to the shared component layer only when it has a clear reuse case; avoid premature generic components.
- Keep business rules and API calls out of presentational components. Put reusable behavior in hooks, services, or server-side application code according to the existing project convention.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
