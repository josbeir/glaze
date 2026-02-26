import 'htmx.org';
import Alpine from 'alpinejs'
import intersect from '@alpinejs/intersect'
import registerTocPage from './toc.js'
import registerThemeToggle, { applyTheme, resolvePreferredTheme } from './theme.js'
import registerScaffoldDemo from './scaffold.js'

Alpine.plugin(intersect)
registerTocPage(Alpine)
registerThemeToggle(Alpine)
registerScaffoldDemo(Alpine)

window.Alpine = Alpine
Alpine.start()

document.addEventListener('DOMContentLoaded', () => {
	applyTheme(resolvePreferredTheme());
});
