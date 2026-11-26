            <div class="history-pagination rm-table-footer">
                <span class="history-page-number" role="status">Showing <?= $rows ? ($page - 1) * $pageSize + 1 : 0 ?> to <?= $rows ? ($page - 1) * $pageSize + count($rows) : 0 ?> of <?= (int)$totalRows ?> entries</span>
                <nav class="history-page-buttons" aria-label="<?= htmlspecialchars($paginationLabel ?? 'History pages') ?>">
                    <?php if ($page > 1): ?><a class="history-page-button history-page-direction" href="<?= htmlspecialchars($url(['page' => $page - 1])) ?>" rel="prev"><?php else: ?><span class="history-page-button history-page-direction" aria-disabled="true"><?php endif; ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg> Previous
                    <?php if ($page > 1): ?></a><?php else: ?></span><?php endif; ?>
                    <?php $firstVisiblePage = max(1, min($page - 2, $totalPages - 4)); $lastVisiblePage = min($totalPages, $firstVisiblePage + 4); ?>
                    <?php for ($number = $firstVisiblePage; $number <= $lastVisiblePage; $number++): ?>
                        <?php if ($number === $page): ?><span class="history-page-button" aria-current="page" aria-label="Page <?= $number ?>, current page"><?= $number ?></span><?php else: ?><a class="history-page-button" aria-label="Page <?= $number ?>" href="<?= htmlspecialchars($url(['page' => $number])) ?>"><?= $number ?></a><?php endif; ?>
                        <?php if ($number === $firstVisiblePage): ?>
                        <form method="GET" class="history-page-length rm-page-size"<?= isset($paginationAction) ? ' action="' . htmlspecialchars($paginationAction) . '"' : '' ?>>
                            <?php foreach ($paginationFilters as $name => $value): ?><input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars((string)$value) ?>"><?php endforeach; ?>
                            <label><select name="<?= htmlspecialchars($paginationLengthKey ?? 'length') ?>" aria-label="Entries per page" onchange="this.form.requestSubmit()">
                                <?php foreach ([10, 15, 25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $size === $pageSize ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                            </select></label>
                            <noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
                        </form>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($hasNext): ?><a class="history-page-button history-page-direction" href="<?= htmlspecialchars($url(['page' => $page + 1])) ?>" rel="next"><?php else: ?><span class="history-page-button history-page-direction" aria-disabled="true"><?php endif; ?>
                        Next <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
                    <?php if ($hasNext): ?></a><?php else: ?></span><?php endif; ?>
                </nav>
            </div>
