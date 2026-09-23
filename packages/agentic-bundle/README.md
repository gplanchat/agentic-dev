# gplanchat/agentic-bundle

Symfony integration of [`gplanchat/agentic`](https://github.com/gplanchat/agentic): the `agentic`
TUI application (`help`, `chat`) and its web version.

```bash
composer require gplanchat/agentic-bundle
```

Requires PHP ≥ 8.4.1 and Symfony 8.2 (`symfony/tui`). Symfony 8.2 and `gplanchat/durable-bundle`
have no stable release yet, so the application installing this bundle needs
`"minimum-stability": "dev"` (with `"prefer-stable": true`).

## Developing

In the monorepo, `composer.json` declares the path repository `../agentic{,}`, so the bundle is
tested against the `agentic` package next to it rather than the published one. The `{,}` glob makes
that repository optional: when the directory is missing (a clone of this split alone), Composer
skips it and takes `gplanchat/agentic` from Packagist. A plain `../agentic` would make
`composer install` fail there.

The mutation tests run through `tools/infection/mutate-changed`, which only exists in the monorepo.

This repository is a read-only split of the
[`gplanchat/agentic-dev`](https://github.com/gplanchat/agentic-dev) monorepo
(`packages/agentic-bundle`). Issues and pull requests go there.

MIT license.
