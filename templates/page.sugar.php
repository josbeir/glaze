<s-template s:extends="layout/page">

<title s:prepend="title"><?= $title ?></title>

<s-template s:block="content">
	<h1 s:block="title"><?= $title ?></h1>
	<?= $content |> raw() ?>
</s-template>
