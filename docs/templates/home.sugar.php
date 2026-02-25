<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<s-template s:extends="layout/page">

<title s:prepend="title"><?= $title ?></title>

<s-template s:block="sidebar">
	<label for="docs-drawer" class="drawer-overlay lg:hidden"></label>
	<div class="lg:hidden">
		<s-template s:include="partials/sidebar" />
	</div>
</s-template>

<s-template s:block="content">
	<section class="border-base-300 mb-10 sm:mb-12">
		<div class="hero-surface relative overflow-hidden rounded-box border border-base-300 bg-base-100/55 p-6 sm:p-8 lg:p-10">
			<div class="hero-mesh" aria-hidden="true"></div>
			<div class="relative z-10 grid gap-10 lg:gap-14 lg:grid-cols-[minmax(0,1fr)_24rem] xl:grid-cols-[minmax(0,1fr)_28rem] lg:items-center">
			<div>
				<div class="badge badge-primary badge-outline mb-4" s:if="$page->hasMeta('hero.category')"><?= $page->meta('hero.category') ?></div>
				<h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight mb-5"><?= $page->meta('hero.title', $title) ?></h1>
				<p class="text-base-content/75 text-lg lg:text-xl max-w-3xl" s:if="$page->hasMeta('hero.subtitle')">
					<?= $page->meta('hero.subtitle') ?>
				</p>

				<div class="flex flex-wrap gap-4 mt-8">
					<a
						class="btn btn-primary btn-md"
						s:if="$page->hasMeta('hero.primaryAction.label')"
						href="<?= ($site->basePath ?? '') . $page->meta('hero.primaryAction.href', '/') ?>"
					>
						<?= $page->meta('hero.primaryAction.label') ?>
					</a>

					<a
						class="btn btn-ghost btn-md"
						s:if="$page->hasMeta('hero.secondaryAction.label')"
						href="<?= ($site->basePath ?? '') . $page->meta('hero.secondaryAction.href', '/') ?>"
					>
						<?= $page->meta('hero.secondaryAction.label') ?>
					</a>
				</div>

			</div>

			<div class="hidden lg:flex justify-center">
				<div class="hero-logo-glow">
					<img
						class="w-72 xl:w-80 2xl:w-88 h-auto"
						src="<?= ($site->basePath ?? '') . '/images/glaze-logo.svg' ?>"
						alt="<?= $site->title ?>"
					/>
				</div>
			</div>
			</div>
		</div>

		<div class="grid gap-4 mt-12 sm:grid-cols-2 lg:grid-cols-3" s:if="$page->hasMeta('hero.highlights')">
			<s-template s:foreach="$page->meta('hero.highlights', []) as $heroHighlight">
			<div class="card group bg-base-200 border border-base-300 transform-gpu transition-all duration-500 ease-out hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-lg active:-translate-y-0.5 active:border-primary/35 active:shadow-lg">
				<div class="card-body p-5">
					<?php
						$highlightIcon = match ($heroHighlight['title'] ?? '') {
							'Djot-native content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-7 text-primary"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h10l4 4v12H6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 4v4h4M9 13h6M9 17h4"/></svg>',
							'Sugar templating' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-7 text-secondary"><rect x="3" y="4" width="18" height="16" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 9-2.5 3L9 15m6-6 2.5 3L15 15"/></svg>',
							'Vite-ready docs workflow' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-7 text-accent"><path stroke-linecap="round" stroke-linejoin="round" d="M13 3 4 14h6l-1 7 11-13h-7z"/></svg>',
							default => '',
						};
					?>
					<div class="mb-2 opacity-95 transition-all duration-500 ease-out group-hover:translate-x-0.5 group-hover:opacity-100 group-active:translate-x-0.5 group-active:opacity-100" s:notempty="$highlightIcon"><?= $highlightIcon |> raw() ?></div>
					<h2 class="card-title text-base" s:notempty="$heroHighlight['title']"><?= $heroHighlight['title'] ?></h2>
					<p class="text-sm text-base-content/75" s:notempty="$heroHighlight['description']"><?= $heroHighlight['description'] ?></p>
				</div>
			</div>
			</s-template>
		</div>

		<div class="card bg-base-200 border border-base-300 mt-12" data-scaffold-demo>
			<div class="card-body gap-6 p-5 sm:p-6 lg:p-7">
				<div class="space-y-2">
					<h2 class="text-xl sm:text-2xl font-semibold">Start a new project in seconds</h2>
					<p class="text-base-content/70 text-sm sm:text-base">Run a few commands and start developing locally.</p>
				</div>

				<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
					<div class="scaffold-terminal shadow-inner">
						<pre data-prefix="$"><code data-scaffold-typing></code></pre>
						<pre data-prefix="✓"><code data-scaffold-status>Preparing scaffold…</code></pre>
					</div>

					<div class="rounded-box border border-base-300 bg-base-100 p-4">
						<p class="text-xs uppercase tracking-wide text-base-content/60 mb-3">Generated structure</p>
						<ul class="w-full space-y-1" data-scaffold-steps>
							<li class="bg-base-200 rounded-md px-2 py-1.5 text-sm" data-scaffold-step="0"><span>content/index.dj</span></li>
							<li class="bg-base-200 rounded-md px-2 py-1.5 text-sm" data-scaffold-step="1"><span>templates/page.sugar.php</span></li>
							<li class="bg-base-200 rounded-md px-2 py-1.5 text-sm" data-scaffold-step="2"><span>templates/layout/page.sugar.php</span></li>
							<li class="bg-base-200 rounded-md px-2 py-1.5 text-sm" data-scaffold-step="4"><span>glaze.neon</span></li>
							<li class="bg-base-200 rounded-md px-2 py-1.5 text-sm" data-scaffold-step="5"><span>vite.config.js</span></li>
							<li class="bg-base-200 rounded-md px-2 py-1.5 text-sm" data-scaffold-step="6"><span>package.json</span></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
	</section>

	<s-template s:notempty="$content">
		<article class="card bg-base-100 border border-base-300 shadow-sm">
			<div class="card-body p-6 sm:p-8 lg:p-10">
				<div class="prose prose-invert max-w-none">
					<?= $content |> raw() ?>
				</div>
			</div>
		</article>

		<div class="mt-8">
			<s-template s:include="partials/pagenav" />
		</div>
	</s-template>
</s-template>
