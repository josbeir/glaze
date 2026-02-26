<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */

use Glaze\Content\ContentPage;

?>
<div class="w-72 h-full min-h-screen border-r border-base-300 bg-base-200 text-base-content">
	<nav class="h-full overflow-y-auto p-4 lg:p-5">
		<a class="btn btn-ghost justify-start normal-case text-base sm:text-lg w-full mb-3" href="<?= ($site->basePath ?? '') . '/' ?>">
			<s-site-brand s:bind="['site' => $site]" />
		</a>
		<?php
			$menuPages = $this->pages()->by('weight', 'asc')->filter(
				static fn(ContentPage $menuPage): bool => (bool)($menuPage->meta('navigation') ?? true),
			);
			$groupedPages = $menuPages->filter(
				static fn(ContentPage $menuPage): bool => trim((string)($menuPage->meta('group') ?? '')) !== '',
			)->groupBy('group');
			$ungroupedPages = $menuPages->filter(
				static fn(ContentPage $menuPage): bool => trim((string)($menuPage->meta('group') ?? '')) === '',
			);
		?>
		<ul class="menu bg-base-200 rounded-box w-56">
			<?php foreach ($groupedPages as $groupName => $groupPages): ?>
				<li>
					<h2 class="menu-title"><?= $groupName ?></h2>
					<ul>
						<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
						<?php foreach ($groupPages as $menuPage): ?>
							<li>
								<a
									href="<?= ($site->basePath ?? '') . $menuPage->urlPath ?>"
									s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
								>
									<?= $menuPage->title ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>

			<?php if (count($ungroupedPages) > 0): ?>
				<li>
					<h2 class="menu-title">Other</h2>
					<ul>
						<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
						<?php foreach ($ungroupedPages as $menuPage): ?>
							<li>
								<a
									href="<?= ($site->basePath ?? '') . $menuPage->urlPath ?>"
									s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
								>
									<?= $menuPage->title ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endif; ?>
		</ul>
	</nav>
</div>
