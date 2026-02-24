<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title s:block="title"> <?= $site->title ?? 'Glaze static site generator' ?></title>
	<link rel="preconnect" href="https://fonts.bunny.net">
	<link href="https://fonts.bunny.net/css?family=albert-sans:300,400,500,700" rel="stylesheet" />
	<style type="text/css">
		body {
			font-family: 'Albert Sans', sans-serif;
			font-size: 18px;
		}
		.phiki {
			padding: 1rem;
			border-radius: .5rem;
		}

		.phiki .line-number {
			margin-right: 1rem;
		}
	</style>
</head>
<body>
<main s:block="content">
	Default page content
</main>
</body>
</html>
