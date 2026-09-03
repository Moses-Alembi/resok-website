<?php
/**
 * Shared site navbar. Set $activeNav before including this file to one of the
 * keys below (or leave it unset/blank on pages with no matching nav item).
 *
 * A fourth element on an item renders a pill after the label - used to flag
 * Learning as members-only, since /learning is behind the portal session gate
 * (see member-gate.php) and would otherwise look like an ordinary public page.
 */
$navItems = [
    'home' => ['index.html', 'fa-home', 'Home'],
    'about' => ['about.html', 'fa-user-group', 'About'],
    'conferences' => ['conferences.html', 'fa-calendar-check', 'Conferences'],
    'projects' => ['projects.html', 'fa-folder-open', 'Projects'],
    'workshops' => ['workshops-and-training.html', 'fa-chalkboard-user', 'Workshops'],
    'partners' => ['sponsors.html', 'fa-handshake', 'Partners'],
    'knowledge' => ['knowledge.html', 'fa-book-medical', 'Knowledge'],
    'learning' => ['learning.html', 'fa-book-open', 'Learning', 'Members'],
    'blog' => ['blog.html', 'fa-newspaper', 'Blog'],
    'contact' => ['contact.html', 'fa-envelope', 'Contact']
];
$active = $activeNav ?? '';
?>
<nav class="navbar">
    <div class="nav-shell">
      <div class="nav-top">
        <div class="nav-top-left">
          <a href="contact.html" class="nav-top-item"><i class="fas fa-question-circle"></i> Ask a Question</a>
          <a href="mailto:info@resok.org" class="nav-top-item"><i class="far fa-envelope"></i> info@resok.org</a>
        </div>
      </div>
      <div class="nav-main">
        <ul class="nav-menu" id="navMenu">
<?php foreach ($navItems as $key => $item): ?>
          <li><a href="<?= $item[0] ?>" class="nav-link<?= $key === $active ? ' is-active' : '' ?>"><i class="fas <?= $item[1] ?>" aria-hidden="true"></i><?= $item[2] ?><?php if (!empty($item[3])): ?><span class="nav-badge"><?= $item[3] ?></span><?php endif; ?></a></li>
<?php endforeach; ?>
          <li class="nav-mobile-actions">
            <a href="resok-portal/public/" class="mobile-action mobile-action-member"><i class="fas fa-user-plus" aria-hidden="true"></i>Become a Member</a>
            <a href="contact.html#donate" class="mobile-action mobile-action-donate"><i class="fas fa-heart" aria-hidden="true"></i>Donate</a>
            <a href="resok-portal/public/login" class="mobile-action mobile-action-login"><i class="fas fa-user" aria-hidden="true"></i>Login</a>
          </li>
        </ul>
        <div class="nav-actions">
          <a href="resok-portal/public/login" class="nav-top-action"><i class="fas fa-user" aria-hidden="true"></i> Login</a>
          <a href="contact.html#donate" class="nav-top-action"><i class="fas fa-heart" aria-hidden="true"></i> Donate</a>
          <a href="resok-portal/public/" class="nav-top-member">Become a Member</a>
        </div>
        <button class="hamburger" type="button" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
      </div>
      <a href="index.html" class="logo" aria-label="ReSoK Home"><img src="assets/img/logo.png" alt="Respiratory Society of Kenya"></a>
    </div>
  </nav>
