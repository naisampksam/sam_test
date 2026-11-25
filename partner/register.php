<?php
// A print-on-demand company creates its own partner account; Looma approves it before it can log in.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';
require __DIR__ . '/../inc/partner_ui.php';

start_session();
if (current_partner_user()) {
    redirect('partner/index.php');
}
$errors = [];
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    ensure_schema();
    $errors = partner_register($_POST);
    $done = !$errors;
}
$v = fn($k) => h($done ? '' : (string)($_POST[$k] ?? ''));
partner_head('Create an account');
?>
<div class="pt-login pt-register">
  <img src="<?= h(asset('assets/icons/icon-192.png')) ?>" alt="" width="64" height="64">
  <h1>Become a partner</h1>
  <p>Order custom T-shirts from <?= h(setting('company_name', 'Looma Apparels')) ?> — for your customers or in bulk.</p>
  <?php if ($done): ?>
    <div class="pt-note" role="status"><b>Thanks! Your account is waiting for approval.</b><br>Looma will check it and contact you. Then log in with your username and password.</div>
    <a class="pt-btn tinted wide" href="<?= h(base_url('partner/index.php')) ?>">Back to log in</a>
  <?php else: ?>
    <?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <h2 class="pt-h2">Your business</h2>
      <section class="pt-card pt-fields">
        <label><span>Brand / company name</span><input name="company" value="<?= $v('company') ?>" required autocomplete="organization"></label>
        <label><span>GSTIN</span><input name="gstin" value="<?= $v('gstin') ?>" maxlength="15" placeholder="Optional" autocapitalize="characters"></label>
        <label class="tall"><span>Return address</span><textarea name="address" rows="2" placeholder="Where returned parcels should go"><?= $v('address') ?></textarea></label>
        <label><span>Pincode</span><input name="pincode" value="<?= $v('pincode') ?>" inputmode="numeric" maxlength="6"></label>
        <label class="tall"><span>About your business</span><textarea name="about" rows="2" placeholder="Where you sell (Shopify, Instagram…), orders a month"><?= $v('about') ?></textarea></label>
      </section>
      <h2 class="pt-h2">You</h2>
      <section class="pt-card pt-fields">
        <label><span>Your name</span><input name="name" value="<?= $v('name') ?>" required autocomplete="name"></label>
        <label><span>Mobile number</span><input name="phone" value="<?= $v('phone') ?>" required inputmode="tel" autocomplete="tel"></label>
        <label><span>Email</span><input name="email" type="email" value="<?= $v('email') ?>" required autocomplete="email"></label>
      </section>
      <h2 class="pt-h2">Login</h2>
      <section class="pt-card pt-fields">
        <label><span>Username</span><input name="username" value="<?= $v('username') ?>" required autocapitalize="none" autocomplete="username" placeholder="letters and numbers"></label>
        <label><span>Password</span><input name="password" type="password" required autocomplete="new-password" placeholder="8+ letters and numbers"></label>
        <label><span>Password again</span><input name="password2" type="password" required autocomplete="new-password"></label>
      </section>
      <div class="pt-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <button class="pt-btn filled wide pt-reg-btn">Create account</button>
      <p class="pt-sub">Already have an account? <a href="<?= h(base_url('partner/index.php')) ?>">Log in</a></p>
    </form>
  <?php endif; ?>
</div>
<?php partner_foot(); ?>
