<h1 align="center">🍬 Glaze — static site generator for modern PHP</h1>

<div align="center">
	<a href="https://github.com/josbeir/glaze/actions" style="text-decoration: none;">
		<img src="https://github.com/josbeir/glaze/actions/workflows/ci.yml/badge.svg" alt="Build Status" />
	</a>
	<a href="https://github.com/josbeir/glaze" style="text-decoration: none;">
		<img src="https://img.shields.io/badge/PHPStan-level%2010-brightgreen" alt="PHPStan Level 10" />
	</a>
	<a href="https://opensource.org/licenses/MIT" style="text-decoration: none;">
		<img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT" />
	</a>
	<a href="https://www.php.net/releases/8.2/en.php" style="text-decoration: none;">
		<img src="https://img.shields.io/badge/php-8.2%2B-blue.svg" alt="PHP Version" />
	</a>
	<a href="https://codecov.io/github/josbeir/glaze" style="text-decoration: none;">
		<img src="https://codecov.io/github/josbeir/glaze/graph/badge.svg" alt="codecov" />
	</a>
	<a href="https://packagist.org/packages/josbeir/glaze" style="text-decoration: none;">
		<img src="https://img.shields.io/packagist/dt/josbeir/glaze" alt="Packagist Downloads" />
	</a>
</div>

---

Glaze is a fast, modern static site generator for PHP developers who want content-first authoring without a heavy framework in the way.

It gives you a clean workflow:

- write content in Djot
- shape output with Sugar templates
- generate static files for reliable, simple deployment

Under the hood, Glaze stays intentionally lightweight while still giving you practical features like frontmatter, taxonomy-aware content discovery, template context helpers, and an ergonomic CLI (`glaze init`, `glaze build`, `glaze serve`).

Glaze is built around:

- [Djot](https://djot.net/) content (via [php-collective/djot-php](https://github.com/php-collective/djot-php))
- [Sugar](https://josbeir.github.io/sugar/) templates
- [NEON](https://github.com/nette/neon/) configuration for both page frontmatter and project/site config

If you like clean files, modern templating, and quick iteration, Glaze is made for you.

## Install

### Global Composer binary

```bash
composer global require josbeir/glaze
glaze --help
```

## Scaffold a new site

```bash
glaze init my-site
cd my-site
```

For local installs:

```bash
vendor/bin/glaze init my-site
cd my-site
```

## Build and serve

```bash
glaze build
glaze serve
```

Static preview mode:

```bash
glaze serve --static --build
```

## Documentation

Documentation is currently work in progress.

For now, see the docs source in the [docs](docs) folder.

## Development

```bash
composer install
composer test
composer phpstan
composer cs-check
composer rector-check
```

## License

Glaze is licensed under the MIT License. See [LICENSE](LICENSE.md).
