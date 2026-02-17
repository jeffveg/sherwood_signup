    </main><!-- /.main-content -->

    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-inner">
                <div class="footer-brand">
                    <p>Tournament Management System</p>
                </div>
                <div class="footer-links">
                    <a href="https://sherwoodadventure.com" target="_blank">SherwoodAdventure.com</a>
                    <a href="/admin/login.php">Admin</a>
                </div>
                <div class="footer-copy">
                    <p>&copy; <?php echo date('Y'); ?> Sherwood Adventure LLC. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="/assets/js/main.js"></script>
    <?php if ($isAdminPage): ?>
    <script src="/assets/js/admin.js"></script>
    <?php endif; ?>
    <?php if (isset($extraScripts)): ?>
        <?php foreach ($extraScripts as $script): ?>
        <script src="<?php echo h($script); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
