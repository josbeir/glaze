import 'htmx.org';
import Typed from 'typed.js';

const THEME_STORAGE_KEY = 'glaze-docs-theme';
const DARK_THEME = 'glaze-docs-dark';
const LIGHT_THEME = 'glaze-docs-light';

/**
 * Resolve the preferred docs theme from storage or OS preference.
 */
function resolvePreferredTheme() {
	const storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);
	if (storedTheme === DARK_THEME || storedTheme === LIGHT_THEME) {
		return storedTheme;
	}

	return window.matchMedia('(prefers-color-scheme: light)').matches ? LIGHT_THEME : DARK_THEME;
}

/**
 * Apply the selected docs theme to the root element.
 *
 * @param {string} theme Theme identifier.
 */
function applyTheme(theme) {
	if (theme !== DARK_THEME && theme !== LIGHT_THEME) {
		return;
	}

	document.documentElement.setAttribute('data-theme', theme);
	window.localStorage.setItem(THEME_STORAGE_KEY, theme);
}

/**
 * Initialize all theme toggle controls in the provided root.
 *
 * @param {ParentNode} root Root node to search in.
 */
function initializeThemeToggle(root) {
	const toggles = root.querySelectorAll('[data-theme-toggle]');
	if (toggles.length === 0) {
		return;
	}

	const currentTheme = document.documentElement.getAttribute('data-theme') ?? resolvePreferredTheme();

	toggles.forEach((toggle) => {
		if (!(toggle instanceof HTMLInputElement)) {
			return;
		}

		toggle.checked = currentTheme === LIGHT_THEME;
		if (toggle.dataset.initialized === '1') {
			return;
		}

		toggle.addEventListener('change', () => {
			const selectedTheme = toggle.checked ? LIGHT_THEME : DARK_THEME;
			applyTheme(selectedTheme);

			document.querySelectorAll('[data-theme-toggle]').forEach((otherToggle) => {
				if (otherToggle instanceof HTMLInputElement) {
					otherToggle.checked = selectedTheme === LIGHT_THEME;
				}
			});
		});

		toggle.dataset.initialized = '1';
	});
}

/**
 * Initialize homepage scaffold demo typewriter interaction.
 *
 * @param {ParentNode} root Root node to search in.
 */
function initializeScaffoldDemo(root) {
	const demo = root.querySelector('[data-scaffold-demo]');
	if (!(demo instanceof HTMLElement)) {
		return;
	}

	if (demo.dataset.initialized === '1') {
		return;
	}

	const typingTarget = demo.querySelector('[data-scaffold-typing]');
	const statusTarget = demo.querySelector('[data-scaffold-status]');
	const stepTargets = Array.from(demo.querySelectorAll('[data-scaffold-step]'));

	if (!(typingTarget instanceof HTMLElement) || !(statusTarget instanceof HTMLElement)) {
		return;
	}

	const commands = [
		'glaze init docs-site --vite --yes',
		'cd docs-site',
		'npm install',
		'glaze serve --vite',
	];

	const statuses = [
		'created /path/to/docs-site',
		'Switching to project directory…',
		'Installing Vite dependencies…',
		'Serving live templates/content from docs-site at http://127.0.0.1:8080 (Vite: http://127.0.0.1:5173)',
	];

	const activeStepMap = [6, 6, 6, 6];

	const updateStepState = (activeIndex) => {
		stepTargets.forEach((stepTarget, stepIndex) => {
			const isActive = stepIndex <= activeIndex;
			stepTarget.classList.toggle('menu-active', isActive);
		});
	};

	updateStepState(-1);

	new Typed(typingTarget, {
		strings: commands,
		typeSpeed: 36,
		backSpeed: 18,
		backDelay: 1200,
		startDelay: 250,
		loop: true,
		smartBackspace: false,
		cursorChar: '▋',
		preStringTyped(arrayPos) {
			statusTarget.textContent = statuses[arrayPos] ?? '';
			updateStepState(activeStepMap[arrayPos] ?? -1);
		},
	});

	demo.dataset.initialized = '1';
}

document.addEventListener('DOMContentLoaded', () => {
	applyTheme(resolvePreferredTheme());
	initializeThemeToggle(document);
	initializeScaffoldDemo(document);
});

document.body.addEventListener('htmx:afterSwap', (event) => {
	const target = event.target;
	if (!(target instanceof HTMLElement)) {
		initializeThemeToggle(document);
		initializeScaffoldDemo(document);
		return;
	}

	initializeThemeToggle(target);
	initializeScaffoldDemo(target);
});
