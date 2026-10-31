</main>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>; window.BASE = <?= json_encode(base_url('')) ?>; window.UID = <?= (int)(current_user()['id'] ?? 0) ?>;</script>
<script src="<?= h(asset('assets/app.js')) ?>"></script>
</body>
</html>
