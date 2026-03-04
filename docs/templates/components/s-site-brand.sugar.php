<?php
/**
 * @var Glaze\Template\SiteContext $this
 * @var Glaze\Config\SiteConfig $site
 */
?>
<img
	class="h-8 w-auto"
	src="<?= $this->url($site->meta('logo', '/glaze-logo.svg')) ?>"
	alt="<?= $site->title ?>"
/>
<span class="font-semibold truncate max-w-[16rem]"><?= $site->title ?></span>
