<?php
/**
 * @var array<\Glaze\Content\ContentPage> $pages
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

            <?php if (count($pages) === 0): ?>
                <p class="glaze-empty">No content pages discovered.</p>
            <?php else: ?>
                <div class="glaze-table-wrap">
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
                            <?php foreach ($pages as $page): ?>
                                <tr>
                                    <td>
                                        <a href="<?= htmlspecialchars($page->urlPath, ENT_QUOTES, 'UTF-8') ?>" class="glaze-link">
                                            <?= htmlspecialchars($page->urlPath, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($page->title, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($page->type !== null): ?>
                                            <span class="glaze-tag"><?= htmlspecialchars($page->type, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="glaze-muted">default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="glaze-flags">
                                        <?php if ($page->draft): ?>
                                            <span class="glaze-tag glaze-tag-warn">draft</span>
                                        <?php endif; ?>
                                        <?php if ($page->unlisted): ?>
                                            <span class="glaze-tag glaze-tag-info">unlisted</span>
                                        <?php endif; ?>
                                        <?php if ($page->virtual): ?>
                                            <span class="glaze-tag glaze-tag-info">virtual</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
