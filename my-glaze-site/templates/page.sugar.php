<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Template\SiteContext $this
 */
?>
<s-template s:extends="layout/base">

<title s:prepend="title"><?= $title ?> | </title>

<s-template s:block="content">
	<section class="panel prose">
		<?= $content |> raw() ?>
	</section>
</s-template>
