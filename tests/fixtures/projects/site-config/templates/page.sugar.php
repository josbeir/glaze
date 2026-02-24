<?php
use function Sugar\Core\Runtime\raw;
?>
<html>
<body>
	<p class="site-title"><?= $site->title ?? 'none' ?></p>
	<p class="meta-description"><?= $meta->description ?? 'none' ?></p>
	<p class="meta-robots"><?= $meta->robots ?? 'none' ?></p>
	<?= $content |> raw() ?>
</body>
</html>
