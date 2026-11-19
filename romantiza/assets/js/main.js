(function () {
  "use strict";

  /* ---------- MENÚ MÓVIL ---------- */
  var toggle = document.getElementById("menuToggle");
  var nav = document.getElementById("mainNav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.style.display === "flex";
      nav.style.cssText = open
        ? ""
        : "display:flex;position:absolute;top:100%;left:0;right:0;flex-direction:column;background:var(--crudo-2);padding:1rem 1.6rem;border-bottom:1px solid var(--border);gap:1rem;";
      toggle.setAttribute("aria-expanded", String(!open));
    });
  }

  /* ---------- BUSCADOR (panel debajo del header) ---------- */
  var searchToggle = document.getElementById("searchToggle");
  var searchPanel = document.getElementById("searchPanel");
  var searchInput = document.getElementById("searchInput");
  var searchClose = document.getElementById("searchClose");
  if (searchToggle && searchPanel) {
    var openSearch = function () {
      searchPanel.classList.add("open");
      searchToggle.setAttribute("aria-expanded", "true");
      if (searchInput) searchInput.focus();
    };
    var closeSearch = function () {
      searchPanel.classList.remove("open");
      searchToggle.setAttribute("aria-expanded", "false");
    };
    searchToggle.addEventListener("click", function () {
      if (searchPanel.classList.contains("open")) { closeSearch(); } else { openSearch(); }
    });
    if (searchClose) searchClose.addEventListener("click", closeSearch);
    // Si ya venimos de buscar algo (tienda.php?q=...), dejamos el panel abierto.
    if (searchInput && searchInput.value.trim() !== "") { openSearch(); }
  }

  /* ---------- SLIDER DE PORTADA ---------- */
  var heroSlider = document.getElementById("heroSlider");
  if (heroSlider) {
    var slides = heroSlider.querySelectorAll(".hero-slide");
    var dots = heroSlider.querySelectorAll(".hero-dot");
    var current = 0;
    var timer = null;

    var showSlide = function (i) {
      if (!slides.length) return;
      current = (i + slides.length) % slides.length;
      slides.forEach(function (s, idx) {
        s.classList.toggle("is-active", idx === current);
      });
      dots.forEach(function (d, idx) {
        d.classList.toggle("is-active", idx === current);
      });
    };
    var restartTimer = function () {
      if (timer) clearInterval(timer);
      if (slides.length > 1) {
        timer = setInterval(function () { showSlide(current + 1); }, 5500);
      }
    };

    dots.forEach(function (d) {
      d.addEventListener("click", function () {
        showSlide(parseInt(d.getAttribute("data-slide"), 10) || 0);
        restartTimer();
      });
    });
    var prevBtn = heroSlider.querySelector(".hero-slider-prev");
    var nextBtn = heroSlider.querySelector(".hero-slider-next");
    if (prevBtn) prevBtn.addEventListener("click", function () { showSlide(current - 1); restartTimer(); });
    if (nextBtn) nextBtn.addEventListener("click", function () { showSlide(current + 1); restartTimer(); });

    restartTimer();
  }

  /* ---------- GALERÍA DE FOTOS (ficha de producto) ---------- */
  var galleryMainImg = document.getElementById("galleryMainImg");
  var galleryThumbs = document.getElementById("galleryThumbs");
  if (galleryMainImg && galleryThumbs) {
    var thumbs = galleryThumbs.querySelectorAll(".ph");
    var selectThumb = function (thumb) {
      var full = thumb.getAttribute("data-full");
      if (!full) return;
      galleryMainImg.setAttribute("src", full);
      thumbs.forEach(function (t) { t.classList.toggle("active", t === thumb); });
    };
    thumbs.forEach(function (thumb) {
      thumb.addEventListener("click", function () { selectThumb(thumb); });
      thumb.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          selectThumb(thumb);
        }
      });
    });
  }

  /* ---------- POPUP DE CAPTACIÓN DE WHATSAPP ---------- */
  var waPopup = document.getElementById("waPopup");
  if (waPopup) {
    var WA_POPUP_CLOSE_DAYS = 7;
    var WA_POPUP_STORAGE_KEY = "romantiza_wa_popup_closed_until";
    var waPopupClosedUntil = 0;
    try {
      waPopupClosedUntil = parseInt(localStorage.getItem(WA_POPUP_STORAGE_KEY) || "0", 10) || 0;
    } catch (e) {
      // Navegador sin localStorage disponible (modo privado, etc.): no
      // pasa nada, el popup simplemente se va a mostrar siempre.
    }

    var waPopupShown = false;
    var showWaPopup = function () {
      if (waPopupShown || Date.now() < waPopupClosedUntil) return;
      waPopupShown = true;
      waPopup.hidden = false;
      document.body.classList.add("wa-popup-open");
    };
    var hideWaPopup = function (days) {
      waPopup.hidden = true;
      document.body.classList.remove("wa-popup-open");
      if (days) {
        try {
          localStorage.setItem(WA_POPUP_STORAGE_KEY, String(Date.now() + days * 24 * 60 * 60 * 1000));
        } catch (e) {}
      }
    };

    var waPopupClose = document.getElementById("waPopupClose");
    if (waPopupClose) {
      waPopupClose.addEventListener("click", function () { hideWaPopup(WA_POPUP_CLOSE_DAYS); });
    }

    setTimeout(showWaPopup, 6000);
    window.addEventListener("scroll", function () {
      if (window.scrollY > 400) showWaPopup();
    }, { passive: true });
  }

  /* ---------- ACORDEÓN (ficha de producto) ---------- */
  document.querySelectorAll(".accordion-btn").forEach(function (b) {
    b.addEventListener("click", function () {
      var item = b.closest(".accordion-item");
      var wasOpen = item.classList.contains("open");
      document.querySelectorAll(".accordion-item").forEach(function (i) {
        i.classList.remove("open");
        var p = i.querySelector(".accordion-panel");
        if (p) p.style.display = "none";
      });
      if (!wasOpen) {
        item.classList.add("open");
        var panel = item.querySelector(".accordion-panel");
        if (panel) panel.style.display = "block";
      }
    });
  });
})();

/* Portada: las fotos de la portada se van turnando (único efecto de la sección). */
(function () {
  var hero = document.getElementById("hHero");
  if (!hero) return;
  var imgs = hero.querySelectorAll(".h-hero-img");
  if (imgs.length < 2) return;
  if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  var i = 0;
  setInterval(function () {
    imgs[i].classList.remove("is-active");
    i = (i + 1) % imgs.length;
    imgs[i].classList.add("is-active");
  }, 5000);
})();
