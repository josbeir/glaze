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
