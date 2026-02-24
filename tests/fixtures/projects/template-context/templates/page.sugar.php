<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var \Glaze\Template\SiteContext $this
 */
?>
<html>
<body>
	<p class="regular"><?= $this->regularPages()->count() ?></p>
	<p class="section"><?= $this->section('blog')->count() ?></p>
	<p class="tag-php"><?= $this->taxonomyTerm('tags', 'php')->count() ?></p>
	<?php $pager = $this->paginate($this->section('blog'), 1, 2, '/blog/'); ?>
	<p class="pager-url"><?= $pager->url() ?></p>
	<p class="prev"><?= $this->previousInSection()?->slug ?? 'none' ?></p>
	<p class="next"><?= $this->nextInSection()?->slug ?? 'none' ?></p>
	<?= $content |> raw() ?>
</body>
</html>
