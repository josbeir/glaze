<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var \Glaze\Template\SiteContext $this
 */
?>
<html>
<body>
	<p class="tags-php"><?= $this->taxonomyTerm('tags', 'php')->count() ?></p>
	<p class="categories-docs"><?= $this->taxonomyTerm('categories', 'docs')->count() ?></p>
	<?= $content |> raw() ?>
</body>
</html>
