<?php
/**
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Template\SiteContext $this
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title s:block="title"> <?= $site->title ?? 'Glaze static site generator' ?></title>
	<link rel="preconnect" href="https://fonts.bunny.net">
	<link href="https://fonts.bunny.net/css?family=inter:200,300,400,500,600,700" rel="stylesheet" />
	<link rel="stylesheet" href="<?= ($site->basePath ?? '') . '/styles/docs.css' ?>" />
</head>
<body hx-boost="true">
<?php $siteName = $site->title ?? 'Glaze'; ?>
<header>
	<div class="container">
		<div class="branding">
			<a class="home-link" href="<?= ($site->basePath ?? '') . '/' ?>">
				<img
					class="home-logo"
					src="<?= ($site->basePath ?? '') . '/images/glaze-logo.svg' ?>"
					alt="<?= $siteName ?>"
				/>
				<span class="home-name"><?= $siteName ?></span>
			</a>
		</div>
	</div>
</header>
<div class="docs-layout container">
	<aside class="docs-sidebar" aria-label="Documentation navigation">
		<nav>
			<ul class="docs-menu">
				<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
				<li s:foreach="$this->pages()->by('meta.weight', 'asc') as $menuPage">
					<a
						href="<?= ($site->basePath ?? '') . $menuPage->urlPath ?>"
						s:class="['active' => $this->isCurrent($menuPage->urlPath)]"
					>
						<?= $menuPage->meta['navigationtitle'] ?? $menuPage->title ?>
					</a>
				</li>
			</ul>
		</nav>
	</aside>
	<main s:block="content">
		Default page content
	</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.8/dist/htmx.min.js"></script>
</body>
</html>
