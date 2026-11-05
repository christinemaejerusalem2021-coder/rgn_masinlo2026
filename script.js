document.addEventListener("DOMContentLoaded", function () {

    const menuToggle = document.getElementById("menu-toggle");
    const navLinks = document.querySelectorAll(".nav-links a");

    navLinks.forEach(function (link) {
        link.addEventListener("click", function () {
            if (menuToggle) {
                menuToggle.checked = false;
            }
        });
    });

    const header = document.querySelector(".header");
    if (header) {
        window.addEventListener("scroll", function () {
            if (window.scrollY > 50) {
                header.classList.add("scrolled");
            } else {
                header.classList.remove("scrolled");
            }
        });
    }

    const heroSlides = document.querySelectorAll(".hero-slideshow .slide");

    if (heroSlides.length > 0) {
        let currentHeroSlide = 0;

        heroSlides.forEach(function (slide, index) {
            slide.classList.toggle("active", index === 0);
        });

        if (heroSlides.length > 1) {
            setInterval(function () {
                heroSlides[currentHeroSlide].classList.remove("active");
                currentHeroSlide = (currentHeroSlide + 1) % heroSlides.length;
                heroSlides[currentHeroSlide].classList.add("active");
            }, 6000); 
        }
    }

    const track = document.querySelector(".slideshow-track");
    const btnNext = document.querySelector(".slide-btn.next");
    const btnPrev = document.querySelector(".slide-btn.prev");

    if (track && btnNext && btnPrev) {
        const totalSlides = track.children.length;
        let currentIndex = 0;

        function updateSlide() {
            track.style.transform = `translateX(-${currentIndex * 100}%)`;
        }

        btnNext.addEventListener("click", function () {
            currentIndex = (currentIndex + 1) % totalSlides;
            updateSlide();
        });

        btnPrev.addEventListener("click", function () {
            currentIndex = (currentIndex - 1 + totalSlides) % totalSlides;
            updateSlide();
        });

        setInterval(function () {
            currentIndex = (currentIndex + 1) % totalSlides;
            updateSlide();
        }, 8000);
    }

    const reveals = document.querySelectorAll(".reveal");

    function revealOnScroll() {
        const windowHeight = window.innerHeight;
        const elementVisible = 100; // Lilitaw kapag 100px na lang ang layo

        reveals.forEach(function (reveal) {
            const elementTop = reveal.getBoundingClientRect().top;
            if (elementTop < windowHeight - elementVisible) {
                reveal.classList.add("active");
            }
        });
    }

    window.addEventListener("scroll", revealOnScroll);
    revealOnScroll(); 

    const checkinInput = document.getElementById("preferred_check_in");
    const checkoutInput = document.getElementById("preferred_check_out");

    if (checkinInput && checkoutInput) {
        function updateCheckoutMinimum() {
            if (!checkinInput.value) {
                checkoutInput.min = checkinInput.min;
                return;
            }

            const checkinDate = new Date(checkinInput.value + "T00:00:00");
            checkinDate.setDate(checkinDate.getDate() + 1);

            const nextDay = [
                checkinDate.getFullYear(),
                String(checkinDate.getMonth() + 1).padStart(2, "0"),
                String(checkinDate.getDate()).padStart(2, "0")
            ].join("-");

            checkoutInput.min = nextDay;

            if (checkoutInput.value && checkoutInput.value < nextDay) {
                checkoutInput.value = "";
            }
        }

        checkinInput.addEventListener("change", updateCheckoutMinimum);
        updateCheckoutMinimum();
    }

    const confirmForms = document.querySelectorAll("form[data-confirm]");

    confirmForms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const message = form.dataset.confirm;

            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });


    // ---------- Swipe sliders (about, accommodation, facilities, gallery) ----------
    document.querySelectorAll("[data-swipe]").forEach(function (slider) {
        const sliderTrack = slider.querySelector(".swipe-track");
        const items = slider.querySelectorAll(".swipe-item");
        const prev = slider.querySelector(".swipe-btn.prev");
        const next = slider.querySelector(".swipe-btn.next");
        const count = slider.querySelector(".swipe-count");
        const dotsWrap = slider.querySelector(".swipe-dots");

        if (!sliderTrack || items.length === 0) return;

        const dots = [];

        items.forEach(function (item, i) {
            const dot = document.createElement("button");
            dot.type = "button";
            dot.setAttribute("aria-label", "Go to slide " + (i + 1));
            dot.addEventListener("click", function () {
                sliderTrack.scrollTo({ left: item.offsetLeft - sliderTrack.offsetLeft });
            });
            dotsWrap.appendChild(dot);
            dots.push(dot);
        });

        function currentIndex() {
            return Math.min(items.length - 1, Math.max(0, Math.round(sliderTrack.scrollLeft / items[0].getBoundingClientRect().width)));
        }

        function update() {
            const active = currentIndex();
            dots.forEach(function (dot, i) {
                dot.classList.toggle("active", i === active);
            });
            if (count) count.textContent = (active + 1) + " / " + items.length;
        }

        function goTo(i) {
            const target = (i + items.length) % items.length;
            sliderTrack.scrollTo({ left: items[target].offsetLeft - sliderTrack.offsetLeft });
        }

        sliderTrack.addEventListener("scroll", update, { passive: true });
        window.addEventListener("resize", update);
        update();

        if (next) next.addEventListener("click", function () { goTo(currentIndex() + 1); });
        if (prev) prev.addEventListener("click", function () { goTo(currentIndex() - 1); });

        sliderTrack.addEventListener("keydown", function (e) {
            if (e.key === "ArrowRight") { e.preventDefault(); goTo(currentIndex() + 1); }
            if (e.key === "ArrowLeft") { e.preventDefault(); goTo(currentIndex() - 1); }
        });

        // Play a video slide only while it is on screen
        const videos = sliderTrack.querySelectorAll("video");
        if (videos.length && "IntersectionObserver" in window) {
            const io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                        const p = entry.target.play();
                        if (p && p.catch) p.catch(function () {});
                    } else {
                        entry.target.pause();
                    }
                });
            }, { threshold: [0, 0.6, 1] });
            videos.forEach(function (v) { io.observe(v); });
        }

        // Mouse drag to swipe (touch uses native scrolling)
        let isDown = false, startX = 0, startLeft = 0;

        sliderTrack.addEventListener("mousedown", function (e) {
            isDown = true;
            startX = e.pageX;
            startLeft = sliderTrack.scrollLeft;
            sliderTrack.style.scrollBehavior = "auto";
            sliderTrack.style.scrollSnapType = "none";
        });

        window.addEventListener("mouseup", function () {
            if (!isDown) return;
            isDown = false;
            sliderTrack.style.scrollBehavior = "";
            sliderTrack.style.scrollSnapType = "";
            goTo(currentIndex());
        });

        window.addEventListener("mousemove", function (e) {
            if (!isDown) return;
            sliderTrack.scrollLeft = startLeft - (e.pageX - startX);
        });
    });

    // ---------- FAQ modal ----------
    const faqModal = document.getElementById("faq-modal");

    if (faqModal) {
        function openFaq() {
            if (typeof faqModal.showModal === "function" && !faqModal.open) {
                faqModal.showModal();
            }
        }

        document.querySelectorAll("[data-open-faq]").forEach(function (btn) {
            btn.addEventListener("click", function (event) {
                event.preventDefault();
                if (menuToggle) menuToggle.checked = false;
                openFaq();
            });
        });

        faqModal.querySelectorAll("[data-close-faq]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                faqModal.close();
            });
        });

        // click on the dark backdrop closes it
        faqModal.addEventListener("click", function (event) {
            if (event.target === faqModal) faqModal.close();
        });

        if (window.location.hash === "#faq") openFaq();
    }

});