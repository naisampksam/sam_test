</main>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>; window.BASE = <?= json_encode(base_url('')) ?>;</script>
<script src="<?= h(asset('assets/app.js')) ?>"></script>
</body>
</html>
