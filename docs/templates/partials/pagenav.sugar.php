<?php
	$prevPage = $this->previousInSection();
	$nextPage = $this->nextInSection();
	$basePath = $site->basePath ?? '';
?>
<nav
	class="doc-pager"
	s:class="['only-next' => !$prevPage && $nextPage, 'only-prev' => $prevPage && !$nextPage]"
	s:if="$prevPage || $nextPage"
	aria-label="Page navigation"
>
	<a class="doc-pager-link prev" s:if="$prevPage" href="<?= $basePath . $prevPage->urlPath ?>">
		<span class="doc-pager-label">Previous</span>
		<span class="doc-pager-title">&larr; <?= $prevPage->meta['navigationtitle'] ?? $prevPage->title ?></span>
	</a>

	<a class="doc-pager-link next" s:if="$nextPage" href="<?= $basePath . $nextPage->urlPath ?>">
		<span class="doc-pager-label">Next</span>
		<span class="doc-pager-title"><?= $nextPage->meta['navigationtitle'] ?? $nextPage->title ?> &rarr;</span>
	</a>
</nav>
