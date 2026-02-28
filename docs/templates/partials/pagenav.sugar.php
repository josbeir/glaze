<?php
/**
 * @var \Glaze\Content\ContentPage $page
 * @var \Glaze\Config\SiteConfig $site
 * @var \Glaze\Template\SiteContext $this
 */

$prevPage = $this->previous();
$nextPage = $this->next();
$basePath = $site->basePath ?? '';
?>
<nav
    class="grid gap-3 grid-cols-2"
    s:class="['only-next' => !$prevPage && $nextPage, 'only-prev' => $prevPage && !$nextPage]"
    s:if="$prevPage || $nextPage"
    aria-label="Page navigation"
>
    <s-doc-nav-card s:if="$prevPage" href="<?= $basePath . $prevPage->urlPath ?>" s:bind="['direction' => 'prev']">
        <?= $prevPage->title ?>
    </s-doc-nav-card>

    <s-doc-nav-card s:if="$nextPage" href="<?= $basePath . $nextPage->urlPath ?>" s:bind="['direction' => 'next']">
        <?= $nextPage->title ?>
    </s-doc-nav-card>
</nav>
