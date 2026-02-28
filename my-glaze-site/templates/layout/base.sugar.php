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
	<meta name="description" content="<?= $site->description ?? 'Starter site scaffolded with Glaze.' ?>" />
    <title>
		<s-ifblock name="title">
			<s-template s:block="title" /> ·
		</s-ifblock>
		<?= $site->title ?? 'Glaze static site generator' ?>
	</title>
	<s-vite src="['assets/css/site.css']" />
</head>
<body>
<main class="page" s:block="content">
	Default page content
</main>
</body>
</html>
