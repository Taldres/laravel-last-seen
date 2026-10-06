# AGENTS.md

Rules for AI coding agents working in this repository.

## Branches

Branch names follow the [Conventional Branch](https://conventional-branch.github.io/) format and use the
Conventional Commits types as prefixes:

```
<type>/<short-description>
```

- `type` is one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore` or `revert`.
- `short-description` is short, lowercase kebab-case and uses only `a-z`, `0-9` and `-`.
- Never commit directly to `main`. Create a branch first.

Examples: `feat/laravel-14-support`, `fix/middleware-generic-user`, `docs/upgrade-guide`.

## Commits

Commit messages follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

- Use the same types as for branches.
- The scope is optional and names the affected part, e.g. `trait`, `middleware`, `migration`, `config`, `deps`.
- Write the description in the imperative mood, starting lowercase and without a trailing period. Keep the
  header under 72 characters.
- Use the body to explain what changed and why, wrapped at 72 characters.
- Mark breaking changes with `!` after the type or scope, and add a `BREAKING CHANGE:` footer that explains
  what users have to change.
- Keep each commit to one logical change.

Examples:

```
fix(middleware): resolve the user after the request is handled
```

```
feat(trait)!: stop adding last_seen_at to $fillable

BREAKING CHANGE: last_seen_at can no longer be mass-assigned. Add it to
the model's $fillable or use forceFill() if you need to set it.
```

## Attribution

- Do not add `Co-authored-by:` trailers to commits.
- Do not add any other AI attribution, such as "Generated with ..." lines, to commit messages or pull
  request descriptions.
- Commits are authored by the configured git user only.
