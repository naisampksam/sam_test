</main>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>; window.BASE = <?= json_encode(base_url('')) ?>; window.UID = <?= (int)(current_user()['id'] ?? 0) ?>;<?php
  $fu = current_user();
  if ($fu && $fu['role'] !== 'admin' && in_array((string)($fu['job'] ?? ''), ['', 'production'], true)) {
      require_once __DIR__ . '/push.php';
      echo ' window.SHIP_WAITING = ' . ship_waiting_count() . ';'; // number on the app icon
  } ?></script>
<script src="<?= h(asset('assets/app.js')) ?>"></script>
</body>
</html>
