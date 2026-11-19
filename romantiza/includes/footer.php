<?php if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); } ?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a href="index.php" class="brand"><img src="assets/img/logo.png" alt="Romantiza" onerror="this.style.display='none'"><span class="brand-word">Romantiza</span></a>
        <p>Deco textil para el hogar. Ventas online desde Sáenz Peña, Chaco, para todo el país.</p>
      </div>
      <div class="footer-col">
        <h4>Tienda</h4>
        <ul>
          <?php foreach ($pdo->query('SELECT c.slug, c.name FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY c.sort_order') as $c): ?>
          <li><a href="tienda.php?categoria=<?= h($c['slug']) ?>"><?= h($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Ayuda</h4>
        <ul>
          <li><a href="contacto.php">Envíos y entregas</a></li>
          <li><a href="contacto.php">Cambios y devoluciones</a></li>
          <li><a href="contacto.php">Contacto</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Seguínos</h4>
        <ul>
          <?php $ig = setting($pdo, 'contact_instagram_url'); if ($ig): ?>
          <li><a href="<?= h($ig) ?>" target="_blank" rel="noopener">Instagram <?= h(setting($pdo,'contact_instagram')) ?></a></li>
          <?php endif; ?>
          <?php $wa = setting($pdo, 'contact_whatsapp'); if ($wa): ?>
          <li><a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a></li>
          <?php endif; ?>
          <li><span><?= h(setting($pdo, 'contact_address')) ?></span></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> Romantiza — Deco textil hogar.</span>
      <span>Sitio desarrollado por Tres Dos Agency</span>
    </div>
  </div>
</footer>

<?= whatsapp_popup_html($pdo, basename($_SERVER['PHP_SELF'])) ?>

<?php $wa = setting($pdo, 'contact_whatsapp'); if ($wa): ?>
<a class="wa-fab" href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener" aria-label="Escribinos por WhatsApp"><svg><use href="#ic-wa"/></svg></a>
<?php endif; ?>

<script src="assets/js/main.js?v=<?= h((string) asset_version(__DIR__ . '/../assets/js/main.js')) ?>"></script>
<script async src="https://www.instagram.com/embed.js"></script>
</body>
</html>
