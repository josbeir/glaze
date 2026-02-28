<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<div class="w-72 h-full min-h-screen border-r border-base-300 bg-base-200 text-base-content">
	<nav class="h-full overflow-y-auto p-4 lg:p-5">
		<a class="btn btn-ghost justify-start normal-case text-base sm:text-lg w-full mb-3" href="<?= ($site->basePath ?? '') . '/' ?>">
			<s-site-brand s:bind="['site' => $site]" />
		</a>
		<ul class="menu bg-base-200 rounded-box w-56">
			<?php $rootPages = $this->rootPages()->filter(
				static fn(Glaze\Content\ContentPage $p): bool => (bool)($p->meta('navigation') ?? true),
			); ?>
			<li s:foreach="$rootPages as $menuPage">
				<a
					href="<?= ($site->basePath ?? '') . $menuPage->urlPath ?>"
					s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
				>
					<?= $menuPage->meta('navigationTitle') ?? $menuPage->title ?>
				</a>
			</li>
			<li s:foreach="$this->sections() as $sectionKey => $sectionPages">
				<h2 class="menu-title"><?= $this->sectionLabel($sectionKey) ?></h2>
				<ul>
					<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
					<s-template s:foreach="$sectionPages as $menuPage">
						<li s:if="$menuPage->meta('navigation') ?? true">
							<a
								href="<?= ($site->basePath ?? '') . $menuPage->urlPath ?>"
								s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
							>
								<?= $menuPage->meta('navigationTitle') ?? $menuPage->title ?>
							</a>
						</li>
					</s-template>
				</ul>
			</li>
		</ul>
	</nav>
</div>
