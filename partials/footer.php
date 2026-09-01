  <footer class="site-footer">
    <div class="site-footer-inner">
      <div class="site-footer-top">
        <div class="site-footer-brand-row"><img src="assets/img/logo.png" alt="ReSoK logo"></div>
        <div class="site-footer-top-social" aria-label="Social links">
          <a href="https://www.facebook.com/share/1DLne8xyKQ/" aria-label="facebook"><i class="fab fa-facebook-f"></i></a>
          <a href="https://www.tiktok.com/@resokenya?is_from_webapp=1&sender_device=pc" aria-label="tiktok"><i class="fab fa-tiktok"></i></a>
          <a href="https://youtube.com/@respiratorysocietykenya?si=1G_SzakIjlz-BzIa" aria-label="youtube"><i class="fab fa-youtube"></i></a>
          <a href="https://x.com/ReSoKenya" aria-label="twitter"><i class="fab fa-twitter"></i></a>
          <a href="https://www.linkedin.com/in/respiratory-society-of-kenya-671208303" aria-label="linkedin"><i class="fab fa-linkedin-in"></i></a>
          <a href="https://www.instagram.com/respiratorysocietyofkenya" aria-label="instagram"><i class="fab fa-instagram"></i></a>
        </div>
        <div class="site-footer-donate"><a href="resok-portal/public/">Membership</a></div>
      </div>
      <div class="site-footer-mid">
        <div><h3 class="site-footer-head">Sign Up For A Newsletter</h3><p class="site-footer-text">Get updates on CMEs, webinars, projects, and announcements directly in your inbox.</p><form class="site-footer-subscribe" action="mailto:info@resok.org?subject=ReSoK%20Newsletter%20Signup" method="post" enctype="text/plain"><input type="email" name="email" placeholder="Your Email Address" aria-label="Email Address"><button type="submit" aria-label="Submit"><i class="fas fa-arrow-right"></i></button></form></div>
        <div class="site-footer-col"><h4>Society</h4><div class="site-footer-divider"></div><ul class="site-footer-list"><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li><li><a href="conferences.html">Conferences</a></li><li><a href="resok-portal/public/">Membership</a></li><li><a href="https://www.resok.org/faqs" target="_blank" rel="noopener">FAQs</a></li></ul></div>
        <div class="site-footer-col"><h4>Get In Touch</h4><div class="site-footer-divider"></div><ul class="site-footer-list"><li><a href="contact.html">Contact Us</a></li><li><a href="blog.html">Blog</a></li><li><a href="contact.html">Location</a></li></ul></div>
        <div class="site-footer-col"><h4>Resources</h4><div class="site-footer-divider"></div><ul class="site-footer-list"><li><a href="knowledge.html">Knowledge Hub</a></li><li><a href="guidelines.html">Guidelines</a></li><li><a href="patient-resources.html">Patient Resources</a></li><li><a href="projects.html">Projects</a></li><li><a href="workshops-and-training.html">Workshops</a></li><li><a href="learning.html">Learning</a></li></ul></div>
        <div class="site-footer-col"><h4>Our Gallery</h4><div class="site-footer-divider"></div><div class="site-footer-gallery-grid">
<img src="assets/img/footer/F1.jpg" alt="Gallery 1" loading="lazy">
<img src="assets/img/footer/F2.jpg" alt="Gallery 2" loading="lazy">
<img src="assets/img/footer/F3.jpg" alt="Gallery 3" loading="lazy">
<img src="assets/img/footer/F4.jpeg" alt="Gallery 4" loading="lazy">
<img src="assets/img/footer/F5.png" alt="Gallery 5" loading="lazy">
<img src="assets/img/footer/F6.png" alt="Gallery 6" loading="lazy">
<img src="assets/img/footer/F7.jpg" alt="Gallery 7" loading="lazy">
<img src="assets/img/footer/F8.jpg" alt="Gallery 8" loading="lazy">
</div></div>
      </div>
      <div class="site-footer-bottom">&copy; 2026 Respiratory Society of Kenya. Developed and Designed by ReSoK ICT</div>
    </div>
  </footer>
  <div class="social-float" aria-label="Quick social links">
    <a class="social-whatsapp" href="https://wa.me/254735700660?text=Hello%20ReSoK%20team%2C%20I%20have%20an%20inquiry." target="_blank" rel="noopener noreferrer" aria-label="Chat with us on WhatsApp" title="WhatsApp"><img src="assets/img/social/whatsapp.svg" alt="" aria-hidden="true"></a>
    <a class="social-x" href="https://x.com/ReSoKenya" target="_blank" rel="noopener noreferrer" aria-label="Visit us on X" title="X"><img src="assets/img/social/x.svg" alt="" aria-hidden="true"></a>
    <a class="social-linkedin" href="https://www.linkedin.com/in/respiratory-society-of-kenya-671208303?utm_source=share&utm_campaign=share_via&utm_content=profile&utm_medium=android_app" target="_blank" rel="noopener noreferrer" aria-label="Visit us on LinkedIn" title="LinkedIn"><img src="assets/img/social/linkedin.svg" alt="" aria-hidden="true"></a>
    <a class="social-facebook" href="https://www.facebook.com/share/1DLne8xyKQ/" target="_blank" rel="noopener noreferrer" aria-label="Visit us on Facebook" title="Facebook"><img src="assets/img/social/facebook.svg" alt="" aria-hidden="true"></a>
  </div>
  <script>
    (() => {
      const hamburger = document.getElementById('hamburger');
      const navMenu = document.getElementById('navMenu');
      const newsletterForm = document.querySelector('.site-footer-subscribe');
      if (newsletterForm) newsletterForm.addEventListener('submit', (event) => event.preventDefault());
      if (!hamburger || !navMenu) return;
      const closeMobileNav = () => {
        hamburger.classList.remove('active');
        navMenu.classList.remove('active');
        document.body.classList.remove('mobile-nav-open');
      };
      const openMobileNav = () => {
        hamburger.classList.add('active');
        navMenu.classList.add('active');
        document.body.classList.add('mobile-nav-open');
      };
      document.addEventListener('click', (event) => {
        if (hamburger.contains(event.target)) {
          event.preventDefault();
          event.stopPropagation();
          navMenu.classList.contains('active') ? closeMobileNav() : openMobileNav();
          return;
        }
        if (!navMenu.classList.contains('active')) return;
        if (navMenu.contains(event.target)) return;
        closeMobileNav();
      }, true);
      navMenu.querySelectorAll('.nav-link, .mobile-action').forEach((link) => link.addEventListener('click', closeMobileNav));
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMobileNav();
      });
    })();
  </script>
  <script src="assets/js/site-forms.js" defer></script>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
    }
  </script>
