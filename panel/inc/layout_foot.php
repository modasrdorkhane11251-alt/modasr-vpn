<?php ?>
  </main>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-row">
    <a href="index.php"   class="bnav-item <?= ($activeNav??'')==='dashboard'?'active':''?>"><?= icon('dashboard',22) ?><span><?= $textbotlang['panel']['layoutFooterCopyright'] ?></span></a>
    <a href="users.php"   class="bnav-item <?= ($activeNav??'')==='users'?'active':''?>"><?= icon('users',22) ?><span><?= $textbotlang['panel']['layoutFooterVersion'] ?></span></a>
    <a href="invoice.php" class="bnav-item <?= ($activeNav??'')==='invoice'?'active':''?>"><?= icon('invoice',22) ?><span><?= $textbotlang['panel']['layoutFooterPoweredBy'] ?></span></a>
    <a href="payment.php" class="bnav-item <?= ($activeNav??'')==='payment'?'active':''?>"><?= icon('card',22) ?><span><?= $textbotlang['panel']['layoutFooterLinkSupport'] ?></span></a>
    <a href="settings.php"class="bnav-item <?= ($activeNav??'')==='settings'?'active':''?>"><?= icon('settings',22) ?><span><?= $textbotlang['panel']['layoutFooterLinkDocs'] ?></span></a>
  </div>
</nav>
</div>

<script src="js/app.js"></script>
<?php if (!empty($_SESSION['show_donate'])):
  unset($_SESSION['show_donate']); ?>
<div class="modal-veil" id="donateModal">
  <div class="modal" style="width:min(420px,100%)">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['donatePopupTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('donateModal')"><?= icon('close', 14) ?></button>
    </div>
    <div class="modal-body" style="line-height:1.9;font-size:.86rem;color:var(--mute)"><?= $textbotlang['panel']['donatePopupText'] ?></div>
    <div class="modal-foot">
      <a class="btn-glass" href="https://nowpayments.io/donation/mahdi" target="_blank" rel="noopener" onclick="closeModal('donateModal')"><?= icon('heart', 14) ?> <?= $textbotlang['panel']['settingsDonateLink'] ?></a>
      <button type="button" class="btn btn-ghost" onclick="closeModal('donateModal')"><?= $textbotlang['panel']['donatePopupLater'] ?></button>
    </div>
  </div>
</div>
<script>openModal('donateModal');</script>
<?php endif; ?>
</body>
</html>
