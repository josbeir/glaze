import Typed from 'typed.js';

/**
 * Registers the `scaffoldDemo` Alpine component.
 *
 * Manages the homepage typewriter demo, driving the Typed.js animation and
 * the generated-structure step list. Attach as `x-data="scaffoldDemo({...})"` on
 * the demo card, passing `commands`, `statuses`, and `activeStepMap` from the
 * template so the JS module stays content-free.
 *
 * Requires an element with `x-ref="typing"` as the Typed.js cursor target.
 * Individual step items bind their active state via `:class="{ 'menu-active': n <= activeStep }"`.
 *
 * Typed instance is destroyed via Alpine's `destroy()` lifecycle hook.
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export default function registerScaffoldDemo(Alpine) {
	Alpine.data('scaffoldDemo', (config = {}) => ({
		activeStep: -1,
		status: 'Preparing scaffold…',
		commands: config.commands ?? [],
		statuses: config.statuses ?? [],
		activeStepMap: config.activeStepMap ?? [],

		/** @type {Typed | null} */
		_typed: null,

		init() {
			this.$nextTick(() => {
				const typingTarget = this.$refs.typing;
				if (!(typingTarget instanceof HTMLElement) || this.commands.length === 0) {
					return;
				}

				this._typed = new Typed(typingTarget, {
					strings: this.commands,
					typeSpeed: 36,
					backSpeed: 18,
					backDelay: 1200,
					startDelay: 250,
					loop: true,
					smartBackspace: false,
					cursorChar: '▋',
					preStringTyped: (arrayPos) => {
						this.status = this.statuses[arrayPos] ?? '';
						this.activeStep = this.activeStepMap[arrayPos] ?? -1;
					},
				});
			});
		},

		destroy() {
			this._typed?.destroy();
		},
	}));
}
