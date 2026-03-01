<h1 align="center">🍬 Glaze — static site generator for modern PHP</h1>

<div align="center" style="margin-bottom: 2rem;">
	<img src="docs/static/glaze-logo.svg" alt="Sugar cube" width="200" />
</div>

<div align="center">
	<a href="https://github.com/josbeir/glaze/actions">
		<img src="https://github.com/josbeir/glaze/actions/workflows/ci.yml/badge.svg" alt="Build Status" />
	</a>
	<a href="https://github.com/josbeir/glaze">
		<img src="https://img.shields.io/badge/PHPStan-level%2010-brightgreen" alt="PHPStan Level 10" />
	</a>
	<a href="https://opensource.org/licenses/MIT">
		<img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT" />
	</a>
	<a href="https://www.php.net/releases/8.2/en.php">
		<img src="https://img.shields.io/badge/php-8.2%2B-blue.svg" alt="PHP Version" />
	</a>
    <a href="https://codecov.io/gh/josbeir/glaze" >
        <img src="https://codecov.io/gh/josbeir/glaze/branch/main/graph/badge.svg?token=WH6IX5EWK9"/>
    </a>
	<a href="https://packagist.org/packages/josbeir/glaze">
		<img src="https://img.shields.io/packagist/dt/josbeir/glaze" alt="Packagist Downloads" />
	</a>
</div>

---

Glaze is a fast, modern static site generator for PHP developers who want content-first authoring without a heavy framework in the way.

> 📚 **Documentation:** https://josbeir.github.io/glaze/

[![Glaze command](docs/static/terminal_dark.png)](https://josbeir.github.io/sugar)

### Quick workflow

- write content in Djot
- shape output with Sugar templates
- generate static files for reliable, simple deployment

Glaze stays lightweight while still giving you practical features like frontmatter, taxonomy-aware discovery, template context helpers, and an ergonomic CLI (`glaze init`, `glaze build`, `glaze serve`).

Glaze is built around:

- [Djot](https://djot.net/) content (via [php-collective/djot-php](https://github.com/php-collective/djot-php))
- [Sugar](https://josbeir.github.io/sugar/) templates
- [NEON](https://github.com/nette/neon/) configuration for both page frontmatter and project/site config

If you like clean files, modern templating, and quick iteration, Glaze is made for you.

## Documentation

Start here: [https://josbeir.github.io/glaze/](https://josbeir.github.io/glaze/)

## License

Glaze is licensed under the MIT License. See [LICENSE](LICENSE.md).

## Contributing

Contributions are welcome. Please read [Contributing](https://josbeir.github.io/sugar) for setup steps and guidelines.
