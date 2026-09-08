<?php
/** Closes the page opened by core/header.php. */
$layout = $layout ?? 'app';
?>
<?php if ($layout === 'app'): ?>
    </main>
</div>
<?php else: ?>
    </main>
<?php endif; ?>

<script src="<?= url('assets/vendor/bootstrap.bundle.min.js') ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
