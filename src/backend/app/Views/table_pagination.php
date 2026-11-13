            <div class="history-pagination">
                <span class="history-page-number">Showing <?= $rows ? ($page - 1) * $pageSize + 1 : 0 ?> to <?= $rows ? ($page - 1) * $pageSize + count($rows) : 0 ?> of <?= (int)$totalRows ?> entries</span>
                <form method="GET" class="history-page-length">
                    <?php foreach ($paginationFilters as $name => $value): ?><input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars((string)$value) ?>"><?php endforeach; ?>
                    <label><select name="length" aria-label="Entries per page">
                        <?php foreach ([10, 25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $size === $pageSize ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                    </select> entries per page</label>
                    <button type="submit" class="btn btn-secondary">Apply</button>
                </form>
                <nav class="history-page-buttons" aria-label="History pages">
                    <?php if ($page > 1): ?><a class="history-page-button history-page-direction" href="<?= htmlspecialchars($url(['page' => $page - 1])) ?>" rel="prev">&lsaquo; Previous</a><?php else: ?><span class="history-page-button history-page-direction" aria-disabled="true">&lsaquo; Previous</span><?php endif; ?>
                    <?php $lastVisiblePage = min($totalPages, max(5, $page)); ?>
                    <?php for ($number = 1; $number <= $lastVisiblePage; $number++): ?>
                        <?php if ($number === $page): ?><span class="history-page-button" aria-current="page" aria-label="Page <?= $number ?>, current page"><?= $number ?></span><?php else: ?><a class="history-page-button" aria-label="Page <?= $number ?>" href="<?= htmlspecialchars($url(['page' => $number])) ?>"><?= $number ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($hasNext): ?><a class="history-page-button history-page-direction" href="<?= htmlspecialchars($url(['page' => $page + 1])) ?>" rel="next">Next &rsaquo;</a><?php else: ?><span class="history-page-button history-page-direction" aria-disabled="true">Next &rsaquo;</span><?php endif; ?>
                </nav>
            </div>
