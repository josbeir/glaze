<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */

$prevPage = $this->previousInSection();
$nextPage = $this->nextInSection();
$basePath = $site->basePath ?? '';
?>
<nav
	class="grid gap-3 md:grid-cols-2"
	s:class="['only-next' => !$prevPage && $nextPage, 'only-prev' => $prevPage && !$nextPage]"
	s:if="$prevPage || $nextPage"
	aria-label="Page navigation"
>
	<a class="card card-border bg-base-100 hover:border-primary transition-colors" s:if="$prevPage" href="<?= $basePath . $prevPage->urlPath ?>">
		<div class="card-body p-4 gap-1">
			<span class="text-xs uppercase tracking-wide text-base-content/60">Previous</span>
			<span class="font-medium text-sm sm:text-base">&larr; <?= $prevPage->title ?></span>
		</div>
	</a>

	<a class="card card-border bg-base-100 hover:border-primary transition-colors" s:if="$nextPage" href="<?= $basePath . $nextPage->urlPath ?>">
		<div class="card-body p-4 gap-1 items-end text-right">
			<span class="text-xs uppercase tracking-wide text-base-content/60">Next</span>
			<span class="font-medium text-sm sm:text-base"><?= $nextPage->title ?> &rarr;</span>
		</div>
	</a>
</nav>
