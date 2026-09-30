</main>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>; window.BASE = <?= json_encode(base_url('')) ?>;</script>
<script src="<?= h(base_url('assets/app.js')) ?>?v=1"></script>
</body>
</html>
