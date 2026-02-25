<header>
	<div class="container">
		<div class="branding">
			<a class="home-link" href="<?= ($site->basePath ?? '') . '/' ?>">
				<img
					class="home-logo"
					src="<?= ($site->basePath ?? '') . '/images/glaze-logo.svg' ?>"
					alt="<?= $site->title ?>"
				/>
				<span class="home-name"><?= $site->title ?></span>
			</a>
		</div>
		<a
			class="github-link"
			href="https://github.com/josbeir/glaze"
			target="_blank"
			rel="noopener noreferrer"
			aria-label="Glaze on GitHub"
			title="View on GitHub"
		>
			<svg class="github-icon" viewBox="0 0 24 24" aria-hidden="true">
				<path fill="currentColor" d="M12 .296c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.333-1.754-1.333-1.754-1.09-.744.083-.729.083-.729 1.205.084 1.84 1.237 1.84 1.237 1.07 1.834 2.807 1.304 3.492.997.108-.776.418-1.305.762-1.605-2.665-.303-5.467-1.334-5.467-5.93 0-1.31.468-2.38 1.236-3.22-.123-.303-.536-1.522.117-3.176 0 0 1.008-.323 3.3 1.23a11.49 11.49 0 0 1 3.005-.404c1.02.005 2.047.137 3.005.404 2.29-1.553 3.296-1.23 3.296-1.23.655 1.654.242 2.873.12 3.176.77.84 1.234 1.91 1.234 3.22 0 4.61-2.807 5.624-5.48 5.922.43.37.815 1.102.815 2.222 0 1.606-.014 2.898-.014 3.293 0 .321.216.694.825.576C20.565 22.092 24 17.592 24 12.296c0-6.627-5.373-12-12-12"/>
			</svg>
		</a>
	</div>
</header>
