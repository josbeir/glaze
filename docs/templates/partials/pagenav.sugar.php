<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */

$prevPage = $this->previousInSection();
$nextPage = $this->nextInSection();
$basePath = $site->basePath ?? '';
$hasPrev = $prevPage && $prevPage->meta('navigation', true);
?>
<nav
	class="grid gap-3 grid-cols-2"
	s:class="['only-next' => !$prevPage && $nextPage, 'only-prev' => $prevPage && !$nextPage]"
	s:if="$prevPage || $nextPage"
	aria-label="Page navigation"
>
	<s-doc-nav-card s:if="$hasPrev" href="<?= $basePath . $prevPage->urlPath ?>" s:bind="['direction' => 'prev']">
		<?= $prevPage->title ?>
	</s-doc-nav-card>

	<s-doc-nav-card s:if="$nextPage" href="<?= $basePath . $nextPage->urlPath ?>" s:bind="['direction' => 'next']">
		<?= $nextPage->title ?>
	</s-doc-nav-card>
</nav>
