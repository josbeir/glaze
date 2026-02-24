<?php
/**
 * @var Glaze\Template\SiteContext $this
 */
?>
<s-template s:extends="layout/page">

<title s:prepend="title"><?= $title ?> |</title>

<s-template s:block="content">
	<?= $content |> raw() ?>
</s-template>
