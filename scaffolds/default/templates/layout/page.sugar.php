<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>
		<s-ifblock name="title">
			<s-template s:block="title" /> -
		</s-ifblock
		<?= $site->title ?? 'Glaze static site generator' ?>
	</title>
</head>
<body>
<main s:block="content">
	Default page content
</main>
</body>
</html>
