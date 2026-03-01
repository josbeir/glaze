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
	<section class="mb-10 sm:mb-12">
		<div class="relative">
			<div class="relative z-10 grid justify-between gap-10 lg:gap-14 lg:grid-cols-[minmax(0,1fr)_24rem] xl:grid-cols-[minmax(0,1fr)_28rem] lg:items-center">
				<div class="px-6">
					<div class="badge badge-primary badge-outline mb-4" s:if="$page->hasMeta('hero.category')">
						<?= $page->meta('hero.category') ?>
					</div>
					<h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight mb-5">
						<?= $page->meta('hero.title', $title) ?>
					</h1>
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

				<div class="hidden lg:flex">
					<img
						class="w-72 xl:w-80 2xl:w-88 h-auto ml-auto"
						src="<?= ($site->basePath ?? '') . '/glaze-logo.svg' ?>"
						alt="<?= $site->title ?>"
					/>
				</div>
			</div>

			<div class="mt-6 hidden lg:block">
				<img class="w-full terminal-preview terminal-preview-light" src="terminal_light.png?p=large" alt="<?= $site->title ?>" />
				<img class="w-full terminal-preview terminal-preview-dark" src="terminal_dark.png?p=large" alt="<?= $site->title ?>" />
			</div>
		</div>

		<div class="grid gap-4 my-15 lg:mb-30 md:grid-cols-3" s:if="$page->hasMeta('hero.highlights')">
			<s-template s:foreach="$page->meta('hero.highlights', []) as $heroHighlight">
				<s-hero-card s:bind="['icon' => $heroHighlight['icon'] ?? '']">
					<h2 s:slot="header"><?= $heroHighlight['title'] ?></h2>
					<?= $heroHighlight['description'] ?>
				</s-hero-card>
			</s-template>
		</div>

		<div class="card bg-base-200 border border-base-300" x-data="scaffoldDemo({
			commands: [
				'glaze init docs-site --vite --yes',
				'cd docs-site',
				'npm install',
				'glaze serve --vite',
			],
			statuses: [
				'created /path/to/docs-site',
				'Switching to project directory…',
				'Installing Vite dependencies…',
				'Serving at http://127.0.0.1:8080 (Vite: http://127.0.0.1:5173)',
			],
			activeStepMap: [2, 3, 4, 5],
		})">
			<div class="card-body gap-6 p-5 sm:p-6 lg:p-7">
				<div class="space-y-2">
					<h2 class="text-xl sm:text-2xl font-semibold">Start a new project in seconds</h2>
					<p class="text-base-content/70 text-sm sm:text-base">Run a few commands and start developing locally.</p>
				</div>

				<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
					<div class="scaffold-terminal shadow-inner">
						<pre data-prefix="$"><code x-ref="typing"></code></pre>
						<pre data-prefix="✓"><code x-text="status"></code></pre>
					</div>

					<div class="rounded-box border border-base-300 bg-base-100 p-4">
						<p class="text-xs uppercase tracking-wide text-base-content/60 mb-3">Generated structure</p>
						<ul class="w-full space-y-1">
							<li class="rounded-md px-2 py-1.5 text-sm" :class="{ 'bg-base-200': 0 <= activeStep }"><span>content/index.dj</span></li>
							<li class="rounded-md px-2 py-1.5 text-sm" :class="{ 'bg-base-200': 1 <= activeStep }"><span>templates/page.sugar.php</span></li>
							<li class="rounded-md px-2 py-1.5 text-sm" :class="{ 'bg-base-200': 2 <= activeStep }"><span>templates/layout/page.sugar.php</span></li>
							<li class="rounded-md px-2 py-1.5 text-sm" :class="{ 'bg-base-200': 3 <= activeStep }"><span>glaze.neon</span></li>
							<li class="rounded-md px-2 py-1.5 text-sm" :class="{ 'bg-base-200': 4 <= activeStep }"><span>vite.config.js</span></li>
							<li class="rounded-md px-2 py-1.5 text-sm" :class="{ 'bg-base-200': 5 <= activeStep }"><span>package.json</span></li>
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
	</s-template>
</s-template>
