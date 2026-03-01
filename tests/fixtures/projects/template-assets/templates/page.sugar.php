<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var \Glaze\Template\SiteContext $this
 */
$blog = $this->section('blog');
$blogAllImages = $blog ? $blog->allAssets()->images()->sortByName() : null;
$postA = $this->bySlug('blog/post-a');
$postAGalleryFirst = $postA ? $this->assetsFor($postA, 'post-a')->images()->sortByName()->first() : null;
$currentPageGalleryFirst = $this->pageAssets('post-a')->images()->sortByName()->first();
$loopGalleryCount = 0;

foreach (($blog ? $blog->allPages() : []) as $item) {
    $loopGalleryCount += $this->assetsFor($item, basename($item->slug))->images()->count();
}
?>
<html>
<body>
	<p class="assets-root-count"><?= $this->assets('shared')->count() ?></p>
	<p class="assets-root-first"><?= $this->assets('shared')->sortByName()->first()?->urlPath ?></p>
	<p class="section-direct-count"><?= $blog ? $blog->assets()->count() : 0 ?></p>
	<p class="section-recursive-count"><?= $blog ? $blog->allAssets()->images()->count() : 0 ?></p>
	<p class="section-recursive-first"><?= $blogAllImages?->first()?->urlPath ?? 'none' ?></p>
	<p class="section-recursive-last"><?= $blogAllImages?->last()?->urlPath ?? 'none' ?></p>
	<p class="current-page-gallery-first"><?= $currentPageGalleryFirst?->relativePath ?? 'none' ?></p>
	<p class="post-a-gallery-first"><?= $postAGalleryFirst?->relativePath ?? 'none' ?></p>
	<p class="loop-gallery-count"><?= $loopGalleryCount ?></p>
	<p class="collection-take-reverse"><?= $blog ? $blog->allAssets()->sortByName()->take(2)->reverse()->first()?->filename : 'none' ?></p>
	<?= $content |> raw() ?>
</body>
</html>
