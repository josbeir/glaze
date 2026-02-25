<div class="w-72 min-h-full border-r border-base-300 bg-base-200 text-base-content">
	<nav class="h-full overflow-y-auto p-4 lg:p-5">
		<a class="btn btn-ghost justify-start normal-case text-base sm:text-lg w-full mb-3" href="<?= ($site->basePath ?? '') . '/' ?>">
			<img
				class="h-8 w-auto"
				src="<?= ($site->basePath ?? '') . '/images/glaze-logo.svg' ?>"
				alt="<?= $site->title ?>"
			/>
			<span class="font-semibold truncate"><?= $site->title ?></span>
		</a>

		<ul class="menu menu-md gap-1">
			<li class="menu-title"><span>Documentation</span></li>
			<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
			<s-template s:foreach="$this->pages()->by('meta.weight', 'asc') as $menuPage">
				<li s:if="$menuPage->meta('navigation') ?? true">
					<a
						href="<?= ($site->basePath ?? '') . $menuPage->urlPath ?>"
						s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
					>
						<?= $menuPage->title ?>
					</a>
				</li>
			</s-template>
		</ul>
	</nav>
</div>
