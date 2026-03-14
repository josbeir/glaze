<?php
/**
 * @var array<\Glaze\Content\ContentPage> $pages
 * @var string $basePath Configured site base path (empty string when not set).
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glaze Inspector - Routes</title>
    <link rel="stylesheet" href="/_glaze/assets/css/dev.css">
</head>
<body>
    <div class="glaze-container">
        <header class="glaze-header">
            <div class="glaze-header-inner">
                <span class="glaze-logo">⬡ Glaze</span>
                <nav class="glaze-nav">
                    <a href="/_glaze/routes" class="glaze-nav-link glaze-nav-active">Routes</a>
                </nav>
            </div>
        </header>

        <main class="glaze-main">
            <div class="glaze-page-header">
                <h1 class="glaze-page-title">Content Routes</h1>
                <span class="glaze-badge"><?= count($pages) ?> pages</span>
            </div>

            <p class="glaze-empty" s:empty="$pages">No content pages discovered.</p>

            <div class="glaze-table-wrap" s:notempty="$pages">
                <table class="glaze-table">
                    <thead>
                        <tr>
                            <th>URL Path</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr s:foreach="$pages as $page">
                            <td>
                                <a href="<?= $basePath . $page->urlPath ?>" class="glaze-link">
                                    <?= $page->urlPath ?>
                                </a>
                            </td>
                            <td><?= $page->title ?></td>
                            <td>
                                <span class="glaze-tag" s:if="$page->type"><?= $page->type ?></span>
                                <span class="glaze-muted" s:unless="$page->type">default</span>
                            </td>
                            <td>
                                <div class="glaze-flags">
                                <span class="glaze-tag glaze-tag-warn" s:if="$page->draft">draft</span>
                                <span class="glaze-tag glaze-tag-info" s:if="$page->unlisted">unlisted</span>
                                <span class="glaze-tag glaze-tag-info" s:if="$page->virtual">virtual</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
