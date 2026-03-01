import 'htmx.org';
import Alpine from 'alpinejs'
import intersect from '@alpinejs/intersect'
import registerTocPage from './toc.js'
import registerThemeToggle, { applyTheme, resolvePreferredTheme } from './theme.js'
import registerScaffoldDemo from './scaffold.js'
import registerCodeGroups from './code-groups.js'

Alpine.plugin(intersect)
registerTocPage(Alpine)
registerThemeToggle(Alpine)
registerScaffoldDemo(Alpine)
registerCodeGroups()

window.Alpine = Alpine
Alpine.start()

document.addEventListener('DOMContentLoaded', () => {
	applyTheme(resolvePreferredTheme());
});
