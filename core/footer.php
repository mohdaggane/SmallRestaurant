<?php
/** Closes the page opened by core/header.php. */
$layout = $layout ?? 'app';
?>
<?php if ($layout === 'app'): ?>
        </div>
        <footer class="mt-8 pt-4 pb-2 border-t border-line text-center text-xs text-muted">
            Made By <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand hover:underline">SAHAN ICT</a> · <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="text-muted hover:text-brand hover:underline">sahanict.org</a>
        </footer>
    </main>
</div>
<?php elseif ($layout === 'wide'): ?>
        </div>
        <footer class="mt-8 pt-3 pb-2 border-t border-line text-center text-xs text-muted">
            Made By <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand hover:underline">SAHAN ICT</a> · <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="text-muted hover:text-brand hover:underline">sahanict.org</a>
        </footer>
    </main>
<?php else: ?>
    </main>
<?php endif; ?>

<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
