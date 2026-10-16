            <div class="history-pagination">
                <span class="history-page-number">Showing <?= $rows ? ($page - 1) * $pageSize + 1 : 0 ?> to <?= $rows ? ($page - 1) * $pageSize + count($rows) : 0 ?> of <?= (int)$totalRows ?> entries</span>
                <form method="GET" class="history-page-length">
                    <?php foreach ($paginationFilters as $name => $value): ?><input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars((string)$value) ?>"><?php endforeach; ?>
                    <label><select name="length" aria-label="Entries per page" onchange="this.form.submit()">
                        <?php foreach ([10, 25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $size === $pageSize ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                    </select> entries per page</label>
                    <noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
                </form>
                <nav class="history-page-buttons" aria-label="History pages">
                    <?php foreach ([['First page', '&laquo;', 1], ['Previous', '&lsaquo;', $page - 1]] as [$name, $symbol, $target]): ?>
                        <?php if ($page > 1): ?><a class="history-page-button" aria-label="<?= $name ?>" href="<?= htmlspecialchars($url(['page' => $target])) ?>"><?= $symbol ?></a><?php else: ?><span class="history-page-button" aria-label="<?= $name ?>" aria-disabled="true"><?= $symbol ?></span><?php endif; ?>
                    <?php endforeach; ?>
                    <?php $firstPage = max(1, min($page - 2, $totalPages - 4)); ?>
                    <?php for ($number = $firstPage; $number <= min($totalPages, $firstPage + 4); $number++): ?>
                        <a class="history-page-button" aria-label="Page <?= $number ?>" <?= $number === $page ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($url(['page' => $number])) ?>"><?= $number ?></a>
                    <?php endfor; ?>
                    <?php foreach ([['Next', '&rsaquo;', $page + 1], ['Last page', '&raquo;', $totalPages]] as [$name, $symbol, $target]): ?>
                        <?php if ($hasNext): ?><a class="history-page-button" aria-label="<?= $name ?>" href="<?= htmlspecialchars($url(['page' => $target])) ?>"><?= $symbol ?></a><?php else: ?><span class="history-page-button" aria-label="<?= $name ?>" aria-disabled="true"><?= $symbol ?></span><?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            </div>
