import 'htmx.org';
import Typed from 'typed.js';

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
	initializeScaffoldDemo(document);
});

document.body.addEventListener('htmx:afterSwap', (event) => {
	const target = event.target;
	if (!(target instanceof HTMLElement)) {
		initializeScaffoldDemo(document);
		return;
	}

	initializeScaffoldDemo(target);
});
