<?php
/** Closes the page opened by core/header.php. */
$layout = $layout ?? 'app';
$footerCompany = platform_setting('company_name', 'SAHAN ICT');
$footerWebName = platform_setting('web_name', 'sahanict.org');
$footerWebUrl  = platform_setting('web_url', 'https://sahanict.org');
?>
<?php if ($layout === 'app'): ?>
        </div>
        <footer class="mt-8 pt-4 pb-2 border-t border-line text-center text-xs text-muted">
            <?= e(__('auth.made_by')) ?> <a href="<?= e($footerWebUrl) ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand hover:underline"><?= e($footerCompany) ?></a> · <a href="<?= e($footerWebUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-muted hover:text-brand hover:underline"><?= e($footerWebName) ?></a>
        </footer>
    </main>
</div>
<?php elseif ($layout === 'wide'): ?>
        </div>
        <footer class="mt-8 pt-3 pb-2 border-t border-line text-center text-xs text-muted">
            <?= e(__('auth.made_by')) ?> <a href="<?= e($footerWebUrl) ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand hover:underline"><?= e($footerCompany) ?></a> · <a href="<?= e($footerWebUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-muted hover:text-brand hover:underline"><?= e($footerWebName) ?></a>
        </footer>
    </main>
<?php else: ?>
    </main>
<?php endif; ?>

<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
