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
    <title>
		<s-ifblock name="title">
			<s-template s:block="title" /> |
		</s-ifblock>
		<?= $site->title ?? 'Glaze static site generator' ?>
	</title>
	<link rel="preconnect" href="https://fonts.bunny.net">
	<link href="https://fonts.bunny.net/css?family=inter:200,300,400,500,600,700" rel="stylesheet" />
	<s-vite src="[
		'assets/css/docs.css',
		'assets/js/docs.js',
	]" />
</head>
<body hx-boost="true">

<s-template s:include="../partials/header" />

<div class="docs-layout container">
	<aside class="docs-sidebar" aria-label="Documentation navigation" s:block="sidebar">
		<s-template s:include="../partials/sidebar" />
	</aside>
	<main s:block="content">
		Default page content
	</main>
</div>
</body>
</html>
